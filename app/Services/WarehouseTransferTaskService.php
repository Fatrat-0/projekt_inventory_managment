<?php

namespace App\Services;

use App\Models\{Product, TransferIntent, TransferStockReservation, User, WarehouseLocation, WarehouseTransferAllocation, WarehouseTransferTask};
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WarehouseTransferTaskService
{
    public function __construct(
        private SmartPicking $picking,
        private WarehouseCapacity $capacity,
        private WarehouseStockAvailability $availability,
        private TransferWorkflowOperations $operations,
    ) {}

    public function status(User $actor, TransferIntent $intent, ?int $taskId = null): array
    {
        Gate::forUser($actor)->authorize('viewPickingTask', $intent);

        return DB::transaction(function () use ($actor, $intent, $taskId) {
            $current = TransferIntent::findOrFail($intent->id);
            $tasks = $current->pickingTasks();
            $task = $taskId === null ? $tasks->latest('id')->first() : $tasks->findOrFail($taskId);
            $stock = $task ? $this->availability->snapshot($current->from_warehouse_id, $current->product_id) : null;

            return [
                'version' => $current->version,
                'task' => $task ? $this->operations->taskSnapshot($task) : null,
                'reservation' => $task ? $this->operations->reservationSnapshot($task->reservation) : null,
                'allocations' => $task ? $this->describeAllocations($current, $task) : [],
                'free_quantity' => $stock['free_quantity'] ?? null,
                'can_execute' => $task && in_array($task->status, [WarehouseTransferTask::PENDING, WarehouseTransferTask::IN_PROGRESS], true)
                    && Gate::forUser($actor)->allows('executePickingTask', $current),
            ];
        });
    }

    public function recommendations(User $actor, TransferIntent $intent, ?int $quantity = null, ?int $taskId = null): array
    {
        Gate::forUser($actor)->authorize('viewPickingTask', $intent);

        return DB::transaction(function () use ($intent, $quantity, $taskId) {
            $current = TransferIntent::findOrFail($intent->id);
            $tasks = $current->pickingTasks();
            $task = $taskId === null ? $tasks->where('active_intent_id', $current->id)->first() : $tasks->findOrFail($taskId);
            if ($task?->dispatched_at) throw new ConflictHttpException('Az igény áruját már feladták, ezért a kitárolás nem folytatható.');
            if (!$task || $task->active_intent_id === null) {
                throw new ConflictHttpException('Az igényhez nincs aktív kitárolási feladat.');
            }
            $quantity ??= $task->expected_quantity - $task->staged_quantity;
            $options = $this->picking->reservedOptions($task, $task->reservation, $current->product_id, $quantity);
            foreach (['sources', 'suggestion'] as $key) {
                $options[$key] = array_map(fn (array $row) => $row + [
                    'source_type' => $row['location_id'] === null ? WarehouseTransferAllocation::SOURCE_UNASSIGNED : WarehouseTransferAllocation::SOURCE_LOCATION,
                ], $options[$key]);
            }

            $options['sources'] = $this->describeSources($current, $task, $options['sources']);

            return $options + ['version' => $current->version, 'task_id' => $task->id];
        });
    }

    private function describeAllocations(TransferIntent $intent, WarehouseTransferTask $task): array
    {
        return $task->allocations()->with(['performer:id,name', 'event:id,event_type,metadata', 'location' => fn ($locations) => $locations
            ->with('system')->withExists(['placements as load_requires_verification' => fn ($placements) => $placements->where('load_requires_verification', true)])])
            ->orderBy('performed_at')->orderBy('id')->get()->map(fn ($row) => $row->attributesToArray() + [
                'performer_name' => $row->performer?->name,
                'load_requires_verification' => (bool) $row->location?->load_requires_verification,
                'plan_url' => $this->sourcePlanUrl($intent, $task, $row->location),
                'unassigned_confirmation' => $row->source_type === WarehouseTransferAllocation::SOURCE_UNASSIGNED
                    ? $row->event?->unassignedConfirmation() : null,
            ])->all();
    }

    private function describeSources(TransferIntent $intent, WarehouseTransferTask $task, array $sources): array
    {
        $ids = array_values(array_filter(array_column($sources, 'location_id')));
        $locations = WarehouseLocation::where('warehouse_id', $intent->from_warehouse_id)->whereKey($ids)
            ->with(['system.zone', 'level'])
            ->withExists(['placements as load_requires_verification' => fn ($placements) => $placements->where('load_requires_verification', true)])
            ->get()->keyBy('id');

        return array_map(function (array $source) use ($locations, $intent, $task) {
            $location = $locations->get($source['location_id']);
            $labels = $location ? $location->pickingPosition() : ['zone' => null, 'system' => null, 'level' => null, 'bin' => null];

            return $source + $labels + [
                'position_label' => implode(' · ', array_filter($labels)),
                'load_requires_verification' => (bool) $location?->load_requires_verification,
                'plan_url' => $this->sourcePlanUrl($intent, $task, $location),
            ];
        }, $sources);
    }

    private function sourcePlanUrl(TransferIntent $intent, WarehouseTransferTask $task, ?WarehouseLocation $location): ?string
    {
        return $location && ($location->system ?? $location)->floor_x !== null
            ? route('warehouse-picking-pages.plan', ['transferIntent' => $intent, 'warehouseLocation' => $location, 'task' => $task->id])
            : null;
    }

    public function create(User $actor, TransferIntent $intent, array $data): array
    {
        return $this->run($actor, $intent, $data, 'picking_task_created');
    }

    public function pick(User $actor, TransferIntent $intent, array $data): array
    {
        // The write transaction takes the account lock before it locks and rechecks the assignment.
        Gate::forUser($actor)->authorize('executePickingTask', [$intent, false]);
        $valid = Validator::make($data, [
            'quantity' => ['required', $this->positiveInteger(TransferIntent::MAX_QUANTITY)],
            'source_allocations' => 'required|list|min:1',
            'source_allocations.*' => 'required|array:source_type,location_id,quantity',
            'source_allocations.*.source_type' => 'required|in:location,unassigned',
            'source_allocations.*.location_id' => ['nullable', $this->positiveInteger(PHP_INT_MAX)],
            'source_allocations.*.quantity' => ['required', $this->positiveInteger(TransferIntent::MAX_QUANTITY)],
        ], [
            'quantity.required' => 'Add meg a kitárolandó darabszámot.',
            'source_allocations' => 'Adj meg legalább egy forráshelyet.',
            'source_allocations.*' => 'Adj meg érvényes forrástípust és pozitív egész darabszámot.',
        ])->validate();
        $quantity = (int) $valid['quantity'];
        $allocations = [];
        foreach ($valid['source_allocations'] as $row) {
            $id = $row['location_id'] ?? null;
            if (($row['source_type'] === WarehouseTransferAllocation::SOURCE_LOCATION) !== ($id !== null)) {
                throw ValidationException::withMessages(['source_allocations' => 'Rekeszes forráshoz válassz rekeszt. Tárhely nélküli forráshoz ne adj meg rekeszazonosítót.']);
            }
            $allocations[] = ['location_id' => $id === null ? null : (int) $id, 'quantity' => (int) $row['quantity']];
        }
        $allocations = $this->picking->normalizeAllocations($allocations, $quantity);

        $usesUnassigned = in_array(null, array_column($allocations, 'location_id'), true);
        $confirmation = $this->validateUnassignedConfirmation($data['unassigned_confirmation'] ?? null, $usesUnassigned);

        return $this->run($actor, $intent, $data, 'stock_picked', $quantity, $allocations, $confirmation);
    }

    /** @return array{found: true, description: string}|null */
    private function validateUnassignedConfirmation(mixed $confirmation, bool $usesUnassigned): ?array
    {
        if ($confirmation === null) return null;
        if (!$usesUnassigned) {
            throw ValidationException::withMessages([
                'unassigned_confirmation' => 'Helyleírást csak tárhelyhez nem rendelt készlet kivételekor adj meg.',
            ]);
        }
        if (is_array($confirmation) && is_string($confirmation['description'] ?? null)) {
            $confirmation['description'] = Str::trim($confirmation['description']);
        }
        $valid = Validator::make(['unassigned_confirmation' => $confirmation], [
            'unassigned_confirmation' => 'required|array:found,description',
            'unassigned_confirmation.found' => ['required', static function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== true) $fail('Erősítsd meg, hogy fizikailag megtaláltad a terméket.');
            }],
            'unassigned_confirmation.description' => 'bail|required|string|max:255',
        ], [
            'unassigned_confirmation.*' => 'Adj meg érvényes helyleírást és visszaigazolást.',
            'unassigned_confirmation.found.required' => 'Erősítsd meg, hogy fizikailag megtaláltad a terméket.',
            'unassigned_confirmation.description.required' => 'Írd le röviden, hol találtad meg a terméket.',
            'unassigned_confirmation.description.string' => 'A helyleírás szöveg legyen.',
            'unassigned_confirmation.description.max' => 'A helyleírás legfeljebb 255 karakter hosszú lehet.',
        ])->validate()['unassigned_confirmation'];

        return ['found' => true, 'description' => $valid['description']];
    }

    private function run(
        User $actor, TransferIntent $intent, array $data, string $type, int $quantity = 0, array $allocations = [],
        ?array $unassignedConfirmation = null,
    ): array {
        if (is_string($data['reason'] ?? null)) {
            $data['reason'] = trim($data['reason']);
            if ($data['reason'] === '') $data['reason'] = null;
        }
        $data = Validator::make($data, TransferIntentService::decisionRules(false), TransferIntentService::decisionMessages())->validate();
        $key = strtolower($data['operation_key']);
        $version = (int) $data['version'];
        $reason = $data['reason'] ?? null;
        $creating = $type === 'picking_task_created';
        $payload = ['version' => $version, 'reason' => $reason, 'quantity' => $quantity, 'allocations' => $allocations];
        if ($unassignedConfirmation !== null) $payload['unassigned_confirmation'] = $unassignedConfirmation;
        $fingerprint = $this->operations->fingerprint($type, $actor, $intent->id, $payload);

        return $this->operations->run($actor, $key, $fingerprint, function () use (
            $actor, $intent, $type, $creating, $quantity, $allocations, $key, $version, $reason, $fingerprint, $unassignedConfirmation,
        ) {
            $actor = $this->operations->currentActor($actor);
            $current = TransferIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($creating ? 'createPickingTask' : 'executePickingTask', $current);
            if ($event = $this->operations->findEvent($key, $current->version !== $version)) {
                return $this->operations->replay($event, $fingerprint);
            }
            // Exact retries of earlier events still replay, but every new unassigned pick needs an attestation.
            if ($unassignedConfirmation === null && in_array(null, array_column($allocations, 'location_id'), true)) {
                throw ValidationException::withMessages([
                    'unassigned_confirmation' => 'Erősítsd meg, hogy fizikailag megtaláltad a terméket, és írd le, hol találtad meg.',
                ]);
            }
            if ($current->version !== $version) {
                throw new ConflictHttpException('Az igény közben megváltozott. Töltsd be újra.');
            }
            if ($current->status !== 'approved' || $version === 4294967295) {
                throw new ConflictHttpException('Az igény jelenlegi állapotában a kitárolási művelet nem végezhető el.');
            }

            Product::whereKey($current->product_id)->lockForUpdate()->firstOrFail(['product_id']);
            $this->capacity->lockWarehouses([$current->from_warehouse_id]);
            $stock = $this->availability->snapshotForUpdate($current->from_warehouse_id, $current->product_id);
            if (!$stock['consistent']) {
                throw ValidationException::withMessages(['quantity' => 'A forrásraktár készletadatai ellentmondásosak. Ellenőrizd a készletet.']);
            }
            $reservation = $current->stockReservations()->where('active_intent_id', $current->id)->lockForUpdate()->first();
            $task = $current->pickingTasks()->where('active_intent_id', $current->id)->lockForUpdate()->first();
            if ($task?->dispatched_at) {
                throw new ConflictHttpException('Az igény áruját már feladták, ezért a kitárolás nem módosítható.');
            }
            if (!$reservation || $reservation->stock_id !== $stock['stock_id'] || $reservation->quantity !== $current->quantity) {
                throw new ConflictHttpException('A kitároláshoz érvényes, az igény teljes mennyiségére szóló forrásfoglalás szükséges.');
            }

            if ($creating) {
                if ($task || $reservation->remaining_quantity !== $current->quantity) {
                    throw new ConflictHttpException('Az igényhez már tartozik kitárolási feladat, vagy a foglalás mennyisége megváltozott.');
                }
                $task = $current->pickingTasks()->create([
                    'transfer_stock_reservation_id' => $reservation->id, 'stock_id' => $reservation->stock_id,
                    'warehouse_id' => $current->from_warehouse_id, 'expected_quantity' => $current->quantity,
                    'created_by' => $actor->id,
                ]);
                $pickedRows = [];
            } else {
                if (!$task || !in_array($task->status, [WarehouseTransferTask::PENDING, WarehouseTransferTask::IN_PROGRESS], true)
                    || $task->warehouse_id !== (int) $current->from_warehouse_id
                    || $task->expected_quantity !== $current->quantity
                    || $reservation->remaining_quantity !== $task->expected_quantity - $task->staged_quantity) {
                    throw new ConflictHttpException('A kitárolási feladat és a foglalás nem egyezik, vagy a feladat már lezárult.');
                }
                $pickedRows = $this->picking->withdrawReserved($task, $reservation, $current->product_id, $quantity, $allocations);
                $this->recordProgress($task, $reservation, $actor, $quantity);
            }

            $current->forceFill(['version' => $version + 1])->save();
            $result = $this->operations->log(
                $current, $actor, $type, $current->status, $reason, $key, $fingerprint, $reservation, $task, $unassignedConfirmation,
            );
            foreach ($pickedRows as $row) {
                unset($row['stock_id']);
                $task->allocations()->create($row + [
                    'source_type' => isset($row['warehouse_location_id']) ? WarehouseTransferAllocation::SOURCE_LOCATION : WarehouseTransferAllocation::SOURCE_UNASSIGNED,
                    'transfer_workflow_event_id' => $result['event']['id'], 'performed_by' => $actor->id,
                    'performed_at' => $result['event']['occurred_at'],
                ]);
            }

            return $result;
        });
    }

    private function recordProgress(WarehouseTransferTask $task, TransferStockReservation $reservation, User $actor, int $quantity): void
    {
        $remaining = $reservation->remaining_quantity - $quantity;
        $now = now();
        $reservation->update([
            'remaining_quantity' => $remaining,
            'status' => $remaining === 0 ? TransferStockReservation::CONSUMED : TransferStockReservation::ACTIVE,
        ]);
        $task->update([
            'staged_quantity' => $task->staged_quantity + $quantity,
            'status' => $remaining === 0 ? WarehouseTransferTask::PICKED : WarehouseTransferTask::IN_PROGRESS,
            'performed_by' => $actor->id, 'started_at' => $task->started_at ?? $now,
            'completed_at' => $remaining === 0 ? $now : null,
        ]);
    }

    private function positiveInteger(int $max): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($max): void {
            $integer = is_int($value) || (is_string($value) && ctype_digit($value));
            if (!$integer || (float) $value > $max || (int) $value < 1) {
                $fail('Pozitív egész számot adj meg a megengedett tartományban.');
            }
        };
    }
}
