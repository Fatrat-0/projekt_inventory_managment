<?php

namespace App\Services;

use App\Models\{Shipment, ShipmentIntentAssignment, ShipmentPlanOperation, ShipmentStop, TransferIntent, User, Vehicle, Warehouse};
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShipmentPlanningService
{
    private const MAX_VERSION = 4294967295;

    public function __construct(
        private TransferWorkflowOperations $intentOperations,
        private ShipmentCargoPreparation $cargo,
        private ShipmentDispatchService $dispatchService,
    ) {}

    public function create(User $actor, array $data): array
    {
        $data = $this->validate($data, [
            'origin_warehouse_id' => ['required', $this->positiveInteger()],
            'reference' => 'required|string|max:100',
            'vehicle_label' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:1000',
            'planned_departure_at' => ['bail', 'nullable', 'string', 'date',
                'regex:/^\d{4}-\d{2}-\d{2}(?:[T ](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?)?$/D'],
            'intent_ids' => 'sometimes|array|list|max:1000',
            'intent_ids.*' => ['required', 'distinct', $this->positiveInteger()],
            'grouping_reason' => 'nullable|string|max:500',
        ]);
        $origin = (int) $data['origin_warehouse_id'];
        $departure = isset($data['planned_departure_at'])
            ? CarbonImmutable::parse($data['planned_departure_at'], config('app.timezone'))->utc()
            : null;
        if ($departure && ($departure->year < 1000 || $departure->year > 9999)) {
            throw ValidationException::withMessages(['planned_departure_at' => 'A tervezett indulás éve 1000 és 9999 közötti legyen.']);
        }
        $attributes = [
            'origin_warehouse_id' => $origin,
            'reference' => $data['reference'],
            'vehicle_label' => $data['vehicle_label'] ?? null,
            'note' => $data['note'] ?? null,
            'planned_departure_at' => $departure?->toDateTimeString(),
        ];
        $ids = $this->ids($data['intent_ids'] ?? []);
        $reason = $data['grouping_reason'] ?? null;

        return $this->run($actor, 'create', $data, null, $origin,
            ['attributes' => $attributes, 'intent_ids' => $ids, 'grouping_reason' => $reason], $reason,
            function (User $actor) use ($attributes, $ids, $origin, $reason) {
                $intents = $this->lockIntents($ids);
                if (!Warehouse::whereKey($origin)->sharedLock()->first(['warehouse_id'])) {
                    throw ValidationException::withMessages(['origin_warehouse_id' => 'Az induló raktár nem található.']);
                }
                $shipment = Shipment::create($attributes + [
                    'planning_mode' => Shipment::MODE_TRANSFER_INTENT, 'user_id' => $actor->id, 'status' => 'draft', 'version' => 1,
                ]);
                [$stops, $assignments] = $this->assign($shipment, $actor, $intents, collect(), collect(), $reason);

                return [$shipment, $stops, $assignments, $ids];
            });
    }

    public function attach(User $actor, Shipment $shipment, array $data): array
    {
        $data = $this->validate($data, [
            'intent_ids' => 'required|array|list|min:1|max:1000',
            'intent_ids.*' => ['required', 'distinct', $this->positiveInteger()],
            'grouping_reason' => 'nullable|string|max:500',
        ], requireVersion: true);
        $ids = $this->ids($data['intent_ids']);
        $reason = $data['grouping_reason'] ?? null;

        return $this->run($actor, 'attach', $data, $shipment, null, ['intent_ids' => $ids, 'grouping_reason' => $reason], $reason,
            function (User $actor, Shipment $current) use ($ids, $reason) {
                $intents = $this->lockIntents($ids);
                [$stops, $assignments] = $this->currentRoute($current);
                [$stops, $assignments] = $this->assign($current, $actor, $intents, $stops, $assignments, $reason);
                $current->cargo_revision++;

                return [$current, $stops, $assignments, $ids];
            });
    }

    public function detach(User $actor, Shipment $shipment, TransferIntent $intent, array $data): array
    {
        $data = $this->validate($data, ['reason' => 'nullable|string|max:500'], requireVersion: true);
        $intentId = (int) $intent->getKey();
        $reason = $data['reason'] ?? null;

        return $this->run($actor, 'detach', $data, $shipment, null, ['intent_id' => $intentId, 'reason' => $reason], $reason,
            function (User $actor, Shipment $current) use ($intentId, $reason) {
                $this->lockIntents([$intentId]);
                [$stops, $assignments] = $this->currentRoute($current);
                $assignment = $assignments->firstWhere('transfer_intent_id', $intentId);
                if (!$assignment) throw new ConflictHttpException('Az igény nincs ehhez a fuvartervhez rendelve.');

                $assignment->update(['detached_at' => now(), 'detached_by' => $actor->id, 'detach_reason' => $reason]);
                $assignments = $assignments->reject(fn (ShipmentIntentAssignment $row) => $row->id === $assignment->id)->values();
                $current->cargo_revision++;

                return [$current, $this->orderStops($stops, $assignments), $assignments, [$intentId]];
            });
    }

    public function reorder(User $actor, Shipment $shipment, array $data): array
    {
        $data = $this->validate($data, [
            'stop_ids' => 'present|array|list',
            'stop_ids.*' => ['required', 'distinct', $this->positiveInteger()],
        ], requireVersion: true);
        $ids = $this->ids($data['stop_ids']);

        return $this->run($actor, 'reorder', $data, $shipment, null, ['stop_ids' => $ids], null,
            function (User $actor, Shipment $current) use ($ids) {
                [$stops, $assignments] = $this->currentRoute($current);

                return [$current, $this->orderStops($stops, $assignments, $ids), $assignments, []];
            });
    }

    public function selectVehicle(User $actor, Shipment $shipment, array $data): array
    {
        $data = $this->validate($data, ['vehicle_id' => ['present', 'nullable', $this->positiveInteger()]], requireVersion: true);
        $vehicleId = isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;

        return $this->changeCargo($actor, $shipment, 'select_vehicle', $data, ['vehicle_id' => $vehicleId], null,
            function (User $actor, Shipment $current) use ($vehicleId): void {
                if ($vehicleId !== null && !Vehicle::whereKey($vehicleId)->where('active', true)->sharedLock()->first(['id'])) {
                    throw ValidationException::withMessages(['vehicle_id' => 'Válassz aktív, nyilvántartott járművet.']);
                }
                if ($current->vehicle_id !== $vehicleId) {
                    $current->vehicle_id = $vehicleId;
                    $current->cargo_revision++;
                }
            });
    }

    public function saveCargoItem(User $actor, Shipment $shipment, int $assignmentId, array $data): array
    {
        $dimension = 'required_with:dimensions_cm|numeric|decimal:0,2|min:0.01|max:10000';
        $data = $this->validate($data, [
            'source' => 'required|in:manual,calculated', 'confirmed' => 'required|boolean',
            'gross_weight_grams' => ['nullable', 'prohibited_unless:source,manual', $this->cargoWeight(1)],
            'packaging_revision' => 'required_if:source,calculated|prohibited_unless:source,calculated|regex:/\A[a-f0-9]{64}\z/D',
            'dimensions_cm' => 'nullable|array:width,depth,height|required_array_keys:width,depth,height',
            'dimensions_cm.width' => $dimension, 'dimensions_cm.depth' => $dimension, 'dimensions_cm.height' => $dimension,
            'packaging_note' => 'nullable|string|max:500', 'reason' => 'nullable|string|max:500',
            'product_id' => 'missing', 'quantity' => 'missing',
        ], requireVersion: true);
        $dimensions = $data['dimensions_cm'] ?? null;
        $payload = [
            'assignment_id' => $assignmentId, 'source' => $data['source'], 'confirmed' => (bool) $data['confirmed'],
            'gross_weight_grams' => isset($data['gross_weight_grams']) ? (int) $data['gross_weight_grams'] : null,
            'packaging_revision' => $data['packaging_revision'] ?? null,
            'dimensions_cm' => $dimensions ? ['width' => (float) $dimensions['width'], 'depth' => (float) $dimensions['depth'], 'height' => (float) $dimensions['height']] : null,
            'packaging_note' => $data['packaging_note'] ?? null, 'reason' => $data['reason'] ?? null,
        ];

        return $this->changeCargo($actor, $shipment, 'save_cargo_item', $data, $payload, $payload['reason'],
            fn (User $actor, Shipment $current, Collection $assignments) => $this->cargo->saveItem($actor, $current, $assignments, $assignmentId, $payload));
    }

    public function confirmCargo(User $actor, Shipment $shipment, array $data): array
    {
        $data = $this->validate($data, [
            'basis_revision' => 'required|string|regex:/\A[a-f0-9]{64}\z/D',
            'gross_weight_grams' => ['required', $this->cargoWeight(1)],
            'pallet_slots' => ['required', $this->integerBetween(0, ShipmentCargoPreparation::MAX_PALLET_SLOTS,
                'Add meg a tényleges raklaphelyigényt egész számmal. Ha nincs raklaphelyigény, adj meg nullát.')],
            'shared_materials_weight_grams' => ['nullable', $this->cargoWeight(0)],
            'reason' => 'nullable|string|max:500',
        ], requireVersion: true);
        $payload = [
            'basis_revision' => $data['basis_revision'], 'gross_weight_grams' => (int) $data['gross_weight_grams'],
            'pallet_slots' => (int) $data['pallet_slots'],
            'shared_materials_weight_grams' => isset($data['shared_materials_weight_grams']) ? (int) $data['shared_materials_weight_grams'] : null,
            'reason' => $data['reason'] ?? null,
        ];

        return $this->changeCargo($actor, $shipment, 'confirm_cargo', $data, $payload, $payload['reason'],
            fn (User $actor, Shipment $current, Collection $assignments) => $this->cargo->confirm($actor, $current, $assignments, $payload));
    }

    public function dispatch(User $actor, Shipment $shipment, array $data): array
    {
        $confirmationMessage = 'Erősítsd meg, hogy a teljes rakományt átadtad szállításra.';
        $data = $this->validate($data, ['confirmed' => 'required|accepted', 'note' => 'nullable|string|max:500'], requireVersion: true, messages: [
            'confirmed.required' => $confirmationMessage, 'confirmed.accepted' => $confirmationMessage,
            'note.*' => 'A feladási megjegyzés legfeljebb 500 karakteres szöveg lehet.',
        ]);
        $note = $data['note'] ?? null;

        return $this->run($actor, 'dispatch', $data, $shipment, null, ['confirmed' => true, 'note' => $note], $note,
            function (User $actor, Shipment $current) use ($note) {
                [$stops, $assignments] = $this->currentRoute($current, locked: true);
                $ids = $this->ids($assignments->pluck('transfer_intent_id')->all());
                $intents = $this->lockIntents($ids);
                $this->dispatchService->execute($actor, $current, $stops, $assignments, $intents, $note);

                return [$current, $stops, $assignments, $ids];
            });
    }

    private function changeCargo(User $actor, Shipment $shipment, string $action, array $data, array $payload, ?string $reason, Closure $change): array
    {
        return $this->run($actor, $action, $data, $shipment, null, $payload, $reason,
            function (User $actor, Shipment $current) use ($change) {
                [$stops, $assignments] = $this->currentRoute($current);
                $changedIntent = $change($actor, $current, $assignments);

                return [$current, $stops, $assignments, $changedIntent === null ? [] : [$changedIntent]];
            }, includeCargo: true);
    }

    private function run(
        User $actor, string $action, array $data, ?Shipment $shipment, ?int $origin, array $payload, ?string $reason, Closure $mutate,
        bool $includeCargo = false,
    ): array {
        $operationKey = strtolower($data['operation_key']);
        $version = isset($data['version']) ? (int) $data['version'] : null;
        $hash = hash('sha256', json_encode([
            $action, (int) $actor->getKey(), $shipment?->id, $version, $payload,
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $action, $shipment, $origin, $operationKey, $version, $hash, $reason, $mutate, $includeCargo) {
                $actor = $this->intentOperations->currentActor($actor);
                $current = $shipment ? Shipment::whereKey($shipment->id)->lockForUpdate()->first() : null;
                abort_if($shipment && !$current, 404, 'A fuvarterv nem található.');
                $gate = Gate::forUser($actor);
                if ($current) {
                    $gate->authorize($action === 'dispatch' ? 'dispatchPlan' : 'managePlan', $current);
                } else {
                    $gate->authorize('createPlan', [Shipment::class, $origin]);
                }

                // By this point the actor and this shipment are locked. On stale writes the
                // replay lookup must also see events committed after a caller's outer snapshot.
                if ($prior = $this->findOperation($operationKey, $current && $current->version !== $version)) {
                    return $this->replay($prior, $hash);
                }
                if ($current) $this->assertDraft($current, $version);

                [$result, $stops, $assignments, $changedIds] = $mutate($actor, $current);
                if ($current) $result->update(['version' => $version + 1]);
                $snapshot = $this->snapshot($result, $stops, $assignments) + ['changed_intent_ids' => $changedIds];
                if ($includeCargo) $snapshot['cargo'] = $this->cargo->details($result, $assignments, true) + ['can_manage' => true];
                if ($action === 'dispatch') $snapshot += $this->dispatchService->state($result);
                $event = ShipmentPlanOperation::create([
                    'shipment_id' => $result->id,
                    'actor_id' => $actor->id,
                    'operation_key' => $operationKey,
                    'action' => $action,
                    'request_hash' => $hash,
                    'reason' => $reason,
                    'result' => $snapshot,
                    'occurred_at' => now(),
                ]);

                // A MySQL JSON column may reorder map keys; read both responses in the same format.
                return $this->response($event->fresh());
            }, 3);
        } catch (UniqueConstraintViolationException|ConflictHttpException|ValidationException $error) {
            // A concurrent operation can win despite an older outer read snapshot, including
            // when a second create hits the already assigned intent before logging its own key.
            $prior = $this->findOperation($operationKey, true);
            if (!$prior) throw $error;

            return $this->replay($prior, $hash);
        }
    }

    private function assertDraft(Shipment $shipment, int $version): void
    {
        if ($shipment->planning_mode !== Shipment::MODE_TRANSFER_INTENT || $shipment->status !== 'draft') {
            throw new ConflictHttpException('Csak tervezett átmozgatási fuvar módosítható.');
        }
        if ($shipment->version !== $version || $version === self::MAX_VERSION) {
            throw new ConflictHttpException('A fuvarterv verziója megváltozott vagy elérte a felső határt. Töltsd be újra az aktuális fuvartervet.');
        }
    }

    private function lockIntents(array $ids): Collection
    {
        $intents = collect();
        // Explicit primary-key lock order also holds when a batch contains overlapping IDs.
        $lockOrder = $ids;
        sort($lockOrder, SORT_NUMERIC);
        foreach ($lockOrder as $id) {
            $intent = TransferIntent::whereKey($id)->lockForUpdate()->first();
            if (!$intent) throw ValidationException::withMessages(['intent_ids' => 'Az egyik átmozgatási igény nem található.']);
            $intents->put($id, $intent);
        }

        return collect($ids)->map(fn (int $id) => $intents[$id]);
    }

    private function currentRoute(Shipment $shipment, bool $locked = false): array
    {
        $stops = $shipment->stops();
        $assignments = $shipment->intentAssignments()->active()->orderBy('id');
        // A matching version certifies the route in this snapshot. In most requests
        // the shipment lock therefore suffices, avoiding next-key locks on empty routes.
        if ($locked || (int) Shipment::whereKey($shipment->id)->value('version') !== $shipment->version) {
            $stops->sharedLock();
            $assignments->sharedLock();
        }

        return [$stops->get(), $assignments->get()];
    }

    private function assign(Shipment $shipment, User $actor, Collection $intents, Collection $stops, Collection $assignments, ?string $reason): array
    {
        foreach ($intents as $intent) {
            if ((int) $intent->from_warehouse_id !== (int) $shipment->origin_warehouse_id) {
                throw ValidationException::withMessages(['intent_ids' => 'Csak a fuvar induló raktárából kért átmozgatási igény rendelhető hozzá.']);
            }
            if ($intent->status !== 'approved') throw new ConflictHttpException('Fuvarhoz csak jóváhagyott átmozgatási igény rendelhető.');
        }
        $this->checkGrouping($shipment, $actor, $intents, $assignments, $reason);

        $position = (int) $stops->max('position');
        $byWarehouse = $stops->keyBy('warehouse_id');
        foreach ($intents as $intent) {
            $warehouseId = (int) $intent->to_warehouse_id;
            $stop = $byWarehouse->get($warehouseId);
            if (!$stop) {
                $stop = $shipment->stops()->create(['warehouse_id' => $warehouseId, 'position' => ++$position]);
                $stops->push($stop);
                $byWarehouse->put($warehouseId, $stop);
            }
            try {
                $assignments->push($shipment->intentAssignments()->create([
                    'shipment_stop_id' => $stop->id, 'transfer_intent_id' => $intent->id,
                    'assigned_by' => $actor->id, 'assigned_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException $error) {
                // Check uniqueness at insertion against the current database, even with an older read snapshot.
                throw new ConflictHttpException('Az igény már aktív fuvarhoz tartozik, vagy korábban már szerepelt ebben a fuvartervben.', $error);
            }
        }

        return [$this->orderStops($stops, $assignments), $assignments];
    }

    private function checkGrouping(Shipment $shipment, User $actor, Collection $intents, Collection $assignments, ?string $reason): void
    {
        if ($intents->count() + $assignments->count() < 2) return;
        $hasDedicated = $intents->contains('shipping_preference', 'dedicated_requested')
            || $this->containsDedicatedIntent($assignments);
        if (!$hasDedicated) return;

        Gate::forUser($actor)->authorize('overrideDedicated', $shipment);
        if ($reason === null) {
            throw ValidationException::withMessages(['grouping_reason' => 'Indokold meg a külön fuvart kérő igény közös fuvarba sorolását.']);
        }
    }

    private function containsDedicatedIntent(Collection $assignments): bool
    {
        $ids = $assignments->pluck('transfer_intent_id');
        $preferences = TransferIntent::whereKey($ids->all())->pluck('shipping_preference', 'id');
        if ($preferences->contains('dedicated_requested')) return true;

        // An immutable preference may only be absent if an outer snapshot predates the intent.
        $missing = $ids->diff($preferences->keys())->sort()->values()->all();

        return $missing !== [] && TransferIntent::whereKey($missing)->where('shipping_preference', 'dedicated_requested')
            ->orderBy('id')->sharedLock()->first(['id']) !== null;
    }

    private function orderStops(Collection $stops, Collection $assignments, ?array $requested = null): Collection
    {
        $active = $assignments->pluck('shipment_stop_id')->map(fn ($id) => (int) $id)->unique();
        $stops = $stops->sortBy('position')->values();
        [$route, $empty] = $stops->partition(fn (ShipmentStop $stop) => $active->containsStrict((int) $stop->id));
        if ($requested !== null) {
            $byId = $route->keyBy('id');
            if (count($requested) !== $route->count() || $byId->only($requested)->count() !== $route->count()) {
                throw ValidationException::withMessages(['stop_ids' => 'Az aktuális útvonal minden megállóját pontosan egyszer add meg.']);
            }
            $route = collect($requested)->map(fn (int $id) => $byId[$id]);
        }
        $ordered = $route->values()->concat($empty->values());
        $moved = $ordered->filter(fn (ShipmentStop $stop, int $index) => $stop->position !== $index + 1);
        $temporary = (int) $stops->max('position');
        if ($temporary + $moved->count() > self::MAX_VERSION) {
            throw new ConflictHttpException('A megállók sorrendje a jelenlegi pozíciókból nem állítható át.');
        }

        // Move changed rows to unused positions first, preserving the unique (shipment, position) constraint.
        foreach ($moved as $stop) $stop->update(['position' => ++$temporary]);
        foreach ($moved as $index => $stop) $stop->update(['position' => $index + 1]);

        return $ordered;
    }

    private function snapshot(Shipment $shipment, Collection $stops, Collection $assignments): array
    {
        $byStop = $assignments->groupBy('shipment_stop_id');

        return [
            'shipment' => [
                'id' => (int) $shipment->id, 'shipment_number' => $shipment->shipment_number,
                'origin_warehouse_id' => (int) $shipment->origin_warehouse_id, 'reference' => $shipment->reference,
                'vehicle_label' => $shipment->vehicle_label, 'vehicle_id' => $shipment->vehicle_id,
                'note' => $shipment->note, 'status' => $shipment->status,
                'version' => $shipment->version, 'planning_mode' => $shipment->planning_mode,
                'planned_departure_at' => $shipment->planned_departure_at?->toISOString(),
                'started_at' => $shipment->started_at?->toISOString(),
            ],
            'assignments' => $assignments->values()->map(fn (ShipmentIntentAssignment $row) => [
                'id' => (int) $row->id, 'transfer_intent_id' => (int) $row->transfer_intent_id,
                'shipment_stop_id' => (int) $row->shipment_stop_id, 'assigned_by' => (int) $row->assigned_by,
                'assigned_at' => $row->assigned_at->toISOString(),
            ])->all(),
            'stops' => $stops->filter(fn (ShipmentStop $stop) => $byStop->has($stop->id))->values()
                ->map(fn (ShipmentStop $stop) => [
                    'id' => (int) $stop->id, 'warehouse_id' => (int) $stop->warehouse_id, 'position' => $stop->position,
                    'intent_ids' => $byStop[$stop->id]->pluck('transfer_intent_id')->map(fn ($id) => (int) $id)->all(),
                ])->all(),
        ];
    }

    private function findOperation(string $key, bool $current = false): ?ShipmentPlanOperation
    {
        $query = ShipmentPlanOperation::where('operation_key', $key);

        return ($current ? $query->sharedLock() : $query)->first();
    }

    private function replay(ShipmentPlanOperation $operation, string $hash): array
    {
        if (!hash_equals($operation->request_hash, $hash)) {
            throw new ConflictHttpException('Ez a műveleti azonosító már másik fuvartervezési kéréshez tartozik.');
        }

        return $this->response($operation);
    }

    private function response(ShipmentPlanOperation $operation): array
    {
        return $operation->result + ['event' => [
            'id' => (int) $operation->id, 'action' => $operation->action, 'actor_id' => (int) $operation->actor_id,
            'reason' => $operation->reason, 'occurred_at' => $operation->occurred_at->toISOString(),
        ]];
    }

    private function ids(array $values): array
    {
        return array_map(intval(...), $values);
    }

    private function positiveInteger(int $max = PHP_INT_MAX): Closure
    {
        return $this->integerBetween(1, $max, 'Adj meg érvényes pozitív egész azonosítót vagy verziót.');
    }

    private function cargoWeight(int $min): Closure
    {
        return $this->integerBetween($min, ShipmentCargoPreparation::MAX_WEIGHT_GRAMS, 'Adj meg érvényes tömeget egész grammban.');
    }

    private function integerBetween(int $min, int $max, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($min, $max, $message): void {
            $integer = is_int($value) || (is_string($value) && ctype_digit($value) && filter_var($value, FILTER_VALIDATE_INT) !== false);
            if (!$integer || (int) $value < $min || (int) $value > $max) $fail($message);
        };
    }

    private function validate(array $data, array $rules, bool $requireVersion = false, array $messages = []): array
    {
        foreach (['reference', 'vehicle_label', 'note', 'reason', 'grouping_reason', 'packaging_note'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $value = Str::trim($data[$field]);
                $data[$field] = $value === '' ? null : $value;
            }
        }
        $rules['operation_key'] = 'required|string|uuid';
        if ($requireVersion) $rules['version'] = ['required', $this->positiveInteger(self::MAX_VERSION)];

        return Validator::make($data, $rules, $messages + [
            'operation_key.*' => 'Adj meg érvényes műveleti azonosítót (UUID).',
            'version.*' => 'Add meg a fuvarterv aktuális verzióját.',
            'origin_warehouse_id.*' => 'Válassz érvényes induló raktárt.',
            'reference.*' => 'Adj meg legfeljebb 100 karakteres fuvarhivatkozást.',
            'vehicle_label.*' => 'A jármű megnevezése legfeljebb 100 karakteres szöveg lehet.',
            'note.*' => 'A megjegyzés legfeljebb 1000 karakteres szöveg lehet.',
            'planned_departure_at.*' => 'Adj meg érvényes tervezett indulást ISO-formátumban.',
            'intent_ids.*' => 'Adj meg 1–1000 különböző átmozgatási igényazonosítót.',
            'intent_ids.*.distinct' => 'Egy igény csak egyszer szerepelhet a kérelemben.',
            'stop_ids.*' => 'Add meg a megállóazonosítók listáját.',
            'stop_ids.*.distinct' => 'Egy megálló csak egyszer szerepelhet az útvonalban.',
            'grouping_reason.*' => 'A csoportosítás indoka legfeljebb 500 karakteres szöveg lehet.',
            'reason.*' => 'Az indoklás legfeljebb 500 karakteres szöveg lehet.',
            'vehicle_id.*' => 'Válassz nyilvántartott járművet, vagy töröld a jármű hozzárendelését.',
            'source.*' => 'Válaszd ki, hogy a tömeg számított vagy kézzel megadott.',
            'confirmed.*' => 'Add meg, hogy megerősíted-e a tétel tömegét.',
            'basis_revision.*' => 'A rakomány aktuális alapadatait előbb töltsd be.',
            'packaging_revision.*' => 'A számított tömeghez töltsd be a csomagolási javaslatot.',
            'gross_weight_grams.*' => 'A kézzel megadott tömeghez adj meg pozitív egész grammértéket.',
            'pallet_slots.*' => 'Add meg a tényleges raklaphelyigényt. Ha nincs raklaphelyigény, adj meg nullát.',
            'shared_materials_weight_grams.*' => 'A külön kezelt közös segédanyagok tömegét egész grammban add meg.',
            'dimensions_cm.*' => 'A külső méretekhez add meg a szélességet, mélységet és magasságot centiméterben, legfeljebb két tizedesjeggyel.',
            'dimensions_cm.width.*' => 'Adj meg érvényes szélességet centiméterben.',
            'dimensions_cm.depth.*' => 'Adj meg érvényes mélységet centiméterben.',
            'dimensions_cm.height.*' => 'Adj meg érvényes magasságot centiméterben.',
            'packaging_note.*' => 'A csomagolási megjegyzés legfeljebb 500 karakteres szöveg lehet.',
            'product_id.*' => 'A terméket az átmozgatási igény határozza meg.',
            'quantity.*' => 'A darabszámot az átmozgatási igény határozza meg.',
        ])->validate();
    }
}
