<?php

namespace App\Services;

use App\Models\{User, Vehicle, VehicleOperation};
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\{Arr, Str};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VehicleRegistry
{
    private const MAX_VERSION = 4294967295;

    public function __construct(private TransferWorkflowOperations $workflow) {}

    public function save(User $actor, array $data, ?Vehicle $vehicle = null): array
    {
        $this->authorizeManage($actor);
        $data = $this->validate($data, $vehicle !== null);
        $key = strtolower($data['operation_key']);
        $version = isset($data['version']) ? (int) $data['version'] : null;
        $attributes = $this->attributes($data, $vehicle === null);
        $action = $vehicle ? 'updated' : 'created';
        $reason = $data['inactivation_reason'] ?? null;
        $command = [$action, $actor->getKey(), $vehicle?->id, $version, $attributes];
        if ($reason !== null) $command[] = $reason;
        $hash = hash('sha256', json_encode($command, JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $vehicle, $version, $attributes, $action, $key, $hash, $reason) {
                $actor = $this->workflow->currentActor($actor);
                $this->authorizeManage($actor);
                $current = $vehicle ? Vehicle::whereKey($vehicle->id)->lockForUpdate()->first() : null;
                abort_if($vehicle && !$current, 404, 'A jármű nem található.');

                if ($previous = $this->operation($key, $current && $current->version !== $version)) {
                    return $this->replay($previous, $hash);
                }
                if ($current && ($current->version !== $version || $version === self::MAX_VERSION)) {
                    throw new ConflictHttpException('A jármű adatai időközben módosultak vagy a verzió elérte a felső határt. Töltsd be újra az adatokat.');
                }

                $previousActive = $current?->active;
                $active = $attributes['active'] ?? $previousActive;
                $changesStatus = $current !== null && $previousActive !== $active;
                if ($reason !== null && (!$changesStatus || $active)) {
                    throw ValidationException::withMessages([
                        'inactivation_reason' => 'Indokot aktív jármű inaktiválásakor adhatsz meg.',
                    ]);
                }

                if ($current) {
                    $current->update($attributes + ['version' => $version + 1]);
                } else {
                    $current = Vehicle::create($attributes);
                }
                $result = $current->registrySnapshot();
                if ($changesStatus) {
                    $result['status_change'] = ['previous_active' => $previousActive, 'active' => $active, 'reason' => $reason];
                }
                $event = $current->operations()->create([
                    'actor_id' => $actor->getKey(),
                    'operation_key' => $key,
                    'action' => $action,
                    'request_hash' => $hash,
                    'result' => $result,
                    'occurred_at' => now(),
                ]);

                return $this->response($event->refresh());
            }, 3);
        } catch (UniqueConstraintViolationException|ConflictHttpException $error) {
            return DB::transaction(function () use ($actor, $key, $hash, $error) {
                $this->authorizeManage($this->workflow->currentActor($actor));
                $previous = $this->operation($key, true);
                if (!$previous) throw $error;

                return $this->replay($previous, $hash);
            });
        }
    }

    private function authorizeManage(User $actor): void
    {
        abort_unless($actor->role === 'admin', 403, 'A járműadatokat központi adminisztrátor kezelheti.');
    }

    private function validate(array $data, bool $update): array
    {
        foreach (['name', 'registration_number', 'note', 'inactivation_reason'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $value = Str::trim($data[$field]);
                $data[$field] = $value === '' ? null : $value;
            }
        }
        $rules = [
            'operation_key' => 'required|string|uuid',
            'name' => $update ? 'sometimes|required|string|max:100' : 'required|string|max:100',
            'registration_number' => 'nullable|string|max:32',
            'payload_capacity_kg' => 'nullable|numeric|decimal:0,3|min:0|max:1000000',
            'pallet_slots_capacity' => ['nullable', $this->integer(0, 1000000, 'A raklaphelykapacitás 0 és 1 000 000 közötti egész szám legyen.')],
            'active' => 'sometimes|boolean',
            'note' => 'nullable|string|max:1000',
            'inactivation_reason' => 'nullable|string|max:500',
        ];
        if ($update) {
            $rules['version'] = ['required', $this->integer(1, self::MAX_VERSION, 'Add meg a jármű aktuális verzióját.')];
        }

        return Validator::make($data, $rules, [
            'operation_key.*' => 'Adj meg érvényes műveleti azonosítót (UUID).',
            'name.*' => 'Adj meg egy legfeljebb 100 karakteres járműnevet.',
            'registration_number.*' => 'A rendszám legfeljebb 32 karakter lehet.',
            'payload_capacity_kg.*' => 'A hasznos teherbírás 0 és 1 000 000 kg közötti érték legyen, legfeljebb három tizedesjeggyel.',
            'active.*' => 'Add meg, hogy a jármű aktív-e.',
            'note.*' => 'A megjegyzés legfeljebb 1000 karakter lehet.',
            'inactivation_reason.*' => 'Az inaktiválás indoka legfeljebb 500 karakteres szöveg lehet.',
            'version.required' => 'Add meg a jármű aktuális verzióját.',
        ])->validate();
    }

    private function integer(int $min, int $max, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($min, $max, $message): void {
            if ((!is_int($value) && !is_string($value))
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
                $fail($message);
            }
        };
    }

    private function attributes(array $data, bool $create): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'registration_number', 'note', 'active', 'pallet_slots_capacity']));
        if (array_key_exists('payload_capacity_kg', $data)) {
            $attributes['payload_capacity_grams'] = $data['payload_capacity_kg'] === null ? null : WarehouseCapacity::grams($data['payload_capacity_kg']);
        }
        if (isset($attributes['pallet_slots_capacity'])) {
            $attributes['pallet_slots_capacity'] = (int) $attributes['pallet_slots_capacity'];
        }
        if (isset($attributes['active'])) {
            $attributes['active'] = (bool) $attributes['active'];
        }
        if ($create) {
            $attributes += ['registration_number' => null, 'payload_capacity_grams' => null, 'pallet_slots_capacity' => null, 'note' => null, 'active' => true];
        } elseif ($attributes === []) {
            throw ValidationException::withMessages(['vehicle' => 'A módosításhoz adj meg legalább egy járműadatot.']);
        }
        ksort($attributes);

        return $attributes;
    }

    private function operation(string $key, bool $current): ?VehicleOperation
    {
        $query = VehicleOperation::where('operation_key', $key);

        return ($current ? $query->sharedLock() : $query)->first();
    }

    private function replay(VehicleOperation $previous, string $hash): array
    {
        if (!hash_equals($previous->request_hash, $hash)) {
            throw new ConflictHttpException('Ez a műveleti azonosító már másik járműmódosításhoz tartozik.');
        }

        return $this->response($previous);
    }

    private function response(VehicleOperation $event): array
    {
        $vehicle = $event->result;
        $statusChange = Arr::pull($vehicle, 'status_change');

        return [
            'vehicle' => $vehicle,
            'event' => [
                'id' => $event->id,
                'actor_id' => $event->actor_id,
                'action' => $event->action,
                'occurred_at' => $event->occurred_at->toISOString(),
            ] + ($statusChange ? ['status_change' => $statusChange] : []),
        ];
    }
}
