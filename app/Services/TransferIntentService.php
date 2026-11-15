<?php

namespace App\Services;

use App\Models\{Product, TransferIntent, User, Warehouse};
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\{Gate, Validator};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Administrative workflow only. Physical inventory changes belong to later stages. */
class TransferIntentService
{
    public function __construct(
        private TransferWorkflowOperations $operations,
        private TransferStockReservationService $reservations,
    ) {
    }

    public static function creationRules(): array
    {
        return [
            'operation_key' => 'required|string|uuid',
            'product_id' => ['bail', 'required', self::positiveInteger(PHP_INT_MAX, 'Válassz érvényes terméket.'), 'exists:products,product_id'],
            'from_warehouse_id' => ['bail', 'required', self::positiveInteger(PHP_INT_MAX, 'Válassz érvényes forrásraktárt.'), 'exists:warehouses,warehouse_id'],
            'to_warehouse_id' => ['bail', 'required', self::positiveInteger(PHP_INT_MAX, 'Válassz érvényes célraktárt.'),
                'different:from_warehouse_id', 'exists:warehouses,warehouse_id'],
            'quantity' => ['bail', 'required', self::positiveInteger(
                TransferIntent::MAX_QUANTITY, 'A kért mennyiség 1 és 2 147 483 647 közötti egész darabszám legyen.',
            )],
            'requested_arrival_at' => ['bail', 'nullable', 'string', 'date',
                'regex:/^\d{4}-\d{2}-\d{2}(?:[T ](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?)?$/D'],
            'reason' => 'nullable|string|max:500',
            'note' => 'nullable|string|max:2000',
            'priority' => 'nullable|in:normal,urgent,critical',
            'shipping_preference' => 'nullable|in:groupable,dedicated_requested',
        ];
    }

    public static function creationMessages(): array
    {
        return [
            'operation_key.*' => 'Adj meg érvényes műveleti azonosítót (UUID).',
            'product_id.*' => 'Válassz érvényes terméket.',
            'from_warehouse_id.*' => 'Válassz érvényes forrásraktárt.',
            'to_warehouse_id.different' => 'A forrásraktár és a célraktár nem lehet ugyanaz.',
            'to_warehouse_id.*' => 'Válassz érvényes célraktárt.',
            'quantity.required' => 'Add meg a kért darabszámot.',
            'requested_arrival_at.*' => 'Adj meg érvényes érkezési dátumot ISO-formátumban.',
            'reason.*' => 'Az indok legfeljebb 500 karakter hosszú szöveg lehet.',
            'note.*' => 'A megjegyzés legfeljebb 2000 karakter hosszú szöveg lehet.',
            'priority.*' => 'Válassz érvényes prioritást.',
            'shipping_preference.*' => 'Válassz érvényes fuvarszervezési preferenciát.',
        ];
    }

    public static function decisionRules(bool $reasonRequired): array
    {
        return [
            'operation_key' => 'required|string|uuid',
            'version' => ['bail', 'required', self::positiveInteger(
                4294967295, 'Az igény verziója érvénytelen. Töltsd be újra az aktuális igényt.',
            )],
            'reason' => ($reasonRequired ? 'required' : 'nullable').'|string|max:500',
        ];
    }

    public static function decisionMessages(): array
    {
        return [
            'operation_key.*' => 'Adj meg érvényes műveleti azonosítót (UUID).',
            'version.required' => 'Add meg az igény aktuális verzióját.',
            'reason.required' => 'Add meg a döntés rövid indokát.',
            'reason.*' => 'Az indok legfeljebb 500 karakter hosszú szöveg lehet.',
        ];
    }

    public function create(User $actor, array $data): array
    {
        $data = Validator::make($this->normalizeText($data), self::creationRules(), self::creationMessages())->validate();
        $key = strtolower($data['operation_key']);
        $payload = [
            'product_id' => (int) $data['product_id'],
            'from_warehouse_id' => (int) $data['from_warehouse_id'],
            'to_warehouse_id' => (int) $data['to_warehouse_id'],
            'quantity' => (int) $data['quantity'],
            'requested_by' => (int) $actor->getKey(),
            'requested_arrival_at' => $this->arrival($data['requested_arrival_at'] ?? null),
            'reason' => $data['reason'] ?? null,
            'note' => $data['note'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'shipping_preference' => $data['shipping_preference'] ?? 'groupable',
        ];
        if ($payload['from_warehouse_id'] === $payload['to_warehouse_id']) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'A forrásraktár és a célraktár nem lehet ugyanaz.']);
        }
        $fingerprint = $this->operations->fingerprint('created', $actor, null, $payload);

        return $this->operations->run($key, $fingerprint, function () use ($actor, $payload, $key, $fingerprint) {
            $actor = $this->operations->currentActor($actor);
            Gate::forUser($actor)->authorize('create', [
                TransferIntent::class, $payload['from_warehouse_id'], $payload['to_warehouse_id'],
            ]);
            if ($existing = $this->operations->findEvent($key)) {
                return $this->operations->replay($existing, $fingerprint);
            }

            $this->lockReferences($payload);
            $intent = TransferIntent::create($payload);

            return $this->operations->log($intent, $actor, 'created', null, $payload['reason'], $key, $fingerprint);
        });
    }

    public function approve(User $actor, TransferIntent $intent, array $data): array
    {
        return $this->transition($actor, $intent, 'approve', 'approved', $data);
    }

    public function reject(User $actor, TransferIntent $intent, array $data): array
    {
        return $this->transition($actor, $intent, 'reject', 'rejected', $data);
    }

    public function cancel(User $actor, TransferIntent $intent, array $data): array
    {
        return $this->transition($actor, $intent, 'cancel', 'cancelled', $data);
    }

    public static function allowsTransition(string $ability, string $status): bool
    {
        return match ($ability) {
            'approve', 'reject' => $status === 'pending_approval',
            'cancel' => in_array($status, ['pending_approval', 'approved'], true),
            default => false,
        };
    }

    private function transition(User $actor, TransferIntent $intent, string $ability, string $target, array $data): array
    {
        $data = Validator::make(
            $this->normalizeText($data), self::decisionRules($ability !== 'approve'), self::decisionMessages(),
        )->validate();
        $key = strtolower($data['operation_key']);
        $version = (int) $data['version'];
        $reason = $data['reason'] ?? null;
        $intentId = (int) $intent->getKey();
        $fingerprint = $this->operations->fingerprint($target, $actor, $intentId, ['version' => $version, 'reason' => $reason]);

        return $this->operations->run($key, $fingerprint, function () use ($actor, $intentId, $ability, $target, $key, $version, $reason, $fingerprint) {
            $actor = $this->operations->currentActor($actor);
            $current = TransferIntent::whereKey($intentId)->lockForUpdate()->first();
            abort_unless($current, 404, 'Az átmozgatási igény nem található.');
            $gate = Gate::forUser($actor);
            $gate->authorize($ability, $current);

            $from = $current->status;
            $stateAllows = self::allowsTransition($ability, $from);
            // On stale commands use a current read so an outer MySQL snapshot cannot hide a committed replay.
            $existing = $this->operations->findEvent($key, $current->version !== $version || !$stateAllows);
            if ($existing) {
                $result = $this->operations->replay($existing, $fingerprint);
                if ($ability === 'cancel' && $existing->from_status === 'approved') {
                    $gate->authorize('approve', $current);
                }

                return $result;
            }

            if ($ability === 'cancel' && $from === 'approved') {
                $gate->authorize('approve', $current);
            }
            if ($current->version !== $version) {
                throw new ConflictHttpException('Az igény közben megváltozott. Töltsd be újra az aktuális állapotot.');
            }
            if (!$stateAllows) {
                throw new ConflictHttpException('A művelet az igény jelenlegi állapotában nem végezhető el.');
            }
            if ($version === 4294967295) {
                throw new ConflictHttpException('Az igény elérte a megengedett legnagyobb verziószámot.');
            }

            $reservation = $ability === 'cancel' && $from === 'approved'
                ? $this->reservations->releaseForCancellation($current, $actor) : null;
            $current->forceFill(['status' => $target, 'version' => $version + 1])->save();

            return $this->operations->log($current, $actor, $target, $from, $reason, $key, $fingerprint, $reservation);
        });
    }

    private function lockReferences(array $payload): void
    {
        if (!Product::whereKey($payload['product_id'])->sharedLock()->first(['product_id'])) {
            throw ValidationException::withMessages(['product_id' => 'A termék időközben megszűnt. Válassz másik terméket.']);
        }
        $warehouses = Warehouse::whereKey([$payload['from_warehouse_id'], $payload['to_warehouse_id']])
            ->orderBy('warehouse_id')->sharedLock()->pluck('warehouse_id')->map(fn ($id) => (int) $id);
        foreach (['from_warehouse_id', 'to_warehouse_id'] as $field) {
            if (!$warehouses->containsStrict($payload[$field])) {
                throw ValidationException::withMessages([$field => 'A választott raktár időközben megszűnt. Válassz másik raktárt.']);
            }
        }
    }

    private function arrival(?string $value): ?string
    {
        if ($value === null) return null;

        $arrival = CarbonImmutable::parse($value, config('app.timezone'))->utc();
        if ($arrival->year < 1000 || $arrival->year > 9999) {
            throw ValidationException::withMessages(['requested_arrival_at' => 'Az érkezés dátuma kívül esik a tárolható dátumtartományon.']);
        }

        return $arrival->format('Y-m-d H:i:s');
    }

    private function normalizeText(array $data): array
    {
        foreach (['reason', 'note', 'requested_arrival_at'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
                if ($data[$field] === '') $data[$field] = null;
            }
        }

        return $data;
    }

    private static function positiveInteger(int $max, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($max, $message): void {
            $number = match (true) {
                is_int($value) => $value,
                is_string($value) && ctype_digit($value) => filter_var(ltrim($value, '0'), FILTER_VALIDATE_INT),
                default => false,
            };
            if ($number === false || $number < 1 || $number > $max) $fail($message);
        };
    }
}
