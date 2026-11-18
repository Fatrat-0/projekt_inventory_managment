<?php

namespace App\Services;

use App\Models\{Shipment, ShipmentIntentAssignment, TransferIntent, TransferStockReservation, WarehouseTransferAllocation, WarehouseTransferTask};
use Illuminate\Database\Eloquent\Collection;

class ShipmentPlanReadiness
{
    public function __construct(private WarehouseStockAvailability $availability, private ShipmentCargoPreparation $cargo) {}

    /** Advisory assessment for the plan snapshot. Dispatch repeats it under write locks. */
    public function assess(Shipment $shipment, Collection $assignments): array
    {
        if ($shipment->status === 'draft') return $this->preparation($shipment, $assignments, false, [])[0];

        $dispatch = $shipment->dispatch()->first();
        $historical = $dispatch?->snapshot['readiness'] ?? [];
        $status = $dispatch ? 'dispatched' : 'closed';
        $intents = collect($historical['intents'] ?? [])->map(fn (array $row) => array_replace($row, [
            'active_staged_quantity' => 0, 'dispatched_quantity' => $row['required_quantity'],
        ]));

        return array_replace($historical, [
            'shipment_id' => $shipment->id, 'version' => $shipment->version, 'shipment_status' => $shipment->status,
            'stock_ready' => false, 'stock_ready_count' => 0, 'stock_status' => $status,
            'cargo_ready' => false, 'vehicle_ready' => false, 'transport_ready' => false,
            'transport_status' => $status, 'stock_blockers' => [],
            'cargo_blockers' => [], 'vehicle_blockers' => [], 'transport_blockers' => [],
            'dispatch_status' => $status,
            'dispatch_id' => $dispatch?->id, 'dispatched_at' => $dispatch?->dispatched_at?->toISOString(),
            'dispatch_prerequisites_met' => false, 'can_dispatch' => false,
            'dispatch_blockers' => [['code' => $dispatch ? 'already_dispatched' : 'dispatch_closed',
                'message' => $dispatch ? 'A fuvar feladása már megtörtént.' : 'A fuvar már nincs feladható tervezet állapotban.']],
            'intents' => $intents,
        ]);
    }

    /** The caller has locked the shipment, intents and product/warehouse roots, in that order. */
    public function lockedPreparation(Shipment $shipment, Collection $assignments, array $stock): array
    {
        return $this->preparation($shipment, $assignments, true, $stock);
    }

    private function preparation(Shipment $shipment, Collection $assignments, bool $locked, array $stock): array
    {
        [$tasks, $holds] = $this->pickingData($assignments->pluck('transfer_intent_id'), $locked, inspectAllocations: true);
        $rows = $this->intentRows($shipment, $assignments, $tasks, $holds, $stock, $locked);
        $summary = $this->stockSummary($rows, $assignments->count());
        $cargo = $this->cargo->details($shipment, $assignments, $locked);
        $transportReady = $cargo['cargo_ready'] && $cargo['vehicle_ready'];
        $stockBlockers = $rows->isEmpty()
            ? [['code' => 'no_assigned_intents', 'message' => 'A fuvarhoz még nem tartozik átmozgatási igény.']] : [];
        $versionBlockers = $shipment->version >= 4294967295
            ? [['code' => 'shipment_version_exhausted', 'message' => 'A fuvarterv elérte a megengedett legnagyobb verziószámot.']] : [];
        $itemStockBlockers = $rows->flatMap(fn (array $row) => collect($row['blockers'])->map(fn (array $blocker) => $blocker + [
            'transfer_intent_id' => $row['transfer_intent_id'], 'reference' => $row['reference'],
        ]))->all();
        $prerequisitesMet = $summary['stock_ready'] && $transportReady && $versionBlockers === [];

        $readiness = $summary + [
            'shipment_id' => $shipment->id,
            'version' => $shipment->version,
            'shipment_status' => $shipment->status,
            'basis_revision' => $cargo['basis_revision'],
            'stock_blockers' => $stockBlockers,
            'cargo_ready' => $cargo['cargo_ready'],
            'cargo_blockers' => $cargo['cargo_blockers'],
            'vehicle_ready' => $cargo['vehicle_ready'],
            'vehicle_blockers' => $cargo['vehicle_blockers'],
            'vehicle' => $cargo['vehicle'],
            'capacity' => $cargo['capacity'],
            'transport_ready' => $transportReady,
            'transport_status' => $transportReady ? 'ready' : 'incomplete',
            'transport_blockers' => collect([...$cargo['cargo_blockers'], ...$cargo['vehicle_blockers']])->unique('code')->values()->all(),
            'dispatch_status' => 'planned',
            'dispatch_prerequisites_met' => $prerequisitesMet,
            'can_dispatch' => $prerequisitesMet,
            'dispatch_blockers' => [...$versionBlockers, ...$stockBlockers, ...$itemStockBlockers, ...$cargo['cargo_blockers'], ...$cargo['vehicle_blockers']],
            'intents' => $rows,
        ];

        return [$readiness, $cargo, $tasks];
    }

    /** Readiness for the bounded page, sharing task queries and warehouse/product snapshots. */
    public function summaries(Collection $shipments): array
    {
        $drafts = $shipments->where('status', 'draft');
        $drafts->load(['intentAssignments' => fn ($assignments) => $assignments->active()->with([
            'intent:id,reference,product_id,from_warehouse_id,to_warehouse_id,quantity,status,version', 'stop:id,warehouse_id,received_at',
        ])]);
        $ids = $drafts->flatMap(fn (Shipment $shipment) => $shipment->intentAssignments->pluck('transfer_intent_id'));
        [$tasks, $holds] = $this->pickingData($ids);
        $transport = $this->cargo->transportSummaries($drafts);
        $shipments->where('status', '!=', 'draft')->loadExists('dispatch');
        $stock = [];

        return $shipments->mapWithKeys(function (Shipment $shipment) use ($tasks, $holds, $transport, &$stock) {
            if ($shipment->status !== 'draft') {
                $status = $shipment->dispatch_exists ? 'dispatched' : 'closed';

                return [$shipment->id => ['stock_ready' => false, 'stock_ready_count' => 0, 'stock_status' => $status,
                    'transport_ready' => false, 'transport_status' => $status]];
            }
            $picked = $shipment->intentAssignments->filter(fn (ShipmentIntentAssignment $assignment) =>
                $tasks->get($assignment->transfer_intent_id)?->status === WarehouseTransferTask::PICKED);
            $rows = $this->intentRows($shipment, $picked, $tasks, $holds, $stock);

            $ready = $transport[$shipment->id] ?? false;

            return [$shipment->id => $this->stockSummary($rows, $shipment->intentAssignments->count()) + [
                'transport_ready' => $ready, 'transport_status' => $ready ? 'ready' : 'incomplete',
            ]];
        })->all();
    }

    private function pickingData(\Illuminate\Support\Collection $intentIds, bool $locked = false, bool $inspectAllocations = false): array
    {
        if ($intentIds->isEmpty()) return [new Collection(), new Collection()];

        $readCurrent = fn ($query) => $query->when($locked, fn ($query) => $query->sharedLock());
        $tasks = $readCurrent(WarehouseTransferTask::whereIntegerInRaw('active_intent_id', $intentIds))->with(['reservation' => $readCurrent]);
        if ($inspectAllocations) {
            $tasks->with(['allocations' => $readCurrent, 'allocations.event' => $readCurrent, 'allocations.location' => $readCurrent]);
        } else {
            $tasks->withSum('allocations as allocated_quantity', 'quantity');
        }
        $holds = $readCurrent(TransferStockReservation::whereIntegerInRaw('active_intent_id', $intentIds));

        return [$tasks->get()->keyBy('transfer_intent_id'), $holds->get()->keyBy('transfer_intent_id')];
    }

    private function intentRows(Shipment $shipment, Collection $assignments, Collection $tasks, Collection $holds, array &$stock, bool $locked = false): \Illuminate\Support\Collection
    {
        return $assignments->map(function (ShipmentIntentAssignment $assignment) use ($shipment, $tasks, $holds, &$stock, $locked) {
            $intent = $assignment->intent;
            $source = (int) $intent->from_warehouse_id;
            $product = (int) $intent->product_id;
            $routeMatches = $source === (int) $shipment->origin_warehouse_id
                && $assignment->stop?->received_at === null
                && (int) $assignment->stop?->warehouse_id === (int) $intent->to_warehouse_id;
            if (!$routeMatches) return $this->intent($intent, null, null, null);

            $snapshot = $stock[$source][$product] ??= $locked
                ? $this->availability->snapshotForUpdate($source, $product) : $this->availability->snapshot($source, $product);

            return $this->intent($intent, $tasks->get($intent->id), $holds->get($intent->id), $snapshot);
        })->values();
    }

    private function stockSummary(\Illuminate\Support\Collection $rows, int $total): array
    {
        $ready = $rows->where('stock_ready', true)->count();
        $stockReady = $ready > 0 && $total === $ready;

        return [
            'stock_ready' => $stockReady,
            'stock_status' => $stockReady ? 'ready' : 'incomplete',
            'stock_ready_count' => $ready,
        ];
    }

    private function intent(TransferIntent $intent, ?WarehouseTransferTask $task, ?TransferStockReservation $hold, ?array $stock): array
    {
        $reservation = $task?->reservation ?? $hold;
        $quantity = $intent->quantity;
        $staged = $task?->staged_quantity ?? 0;
        $activeStaged = $task?->activeStagedQuantity() ?? 0;
        $blockers = [];

        if ($intent->status !== 'approved') {
            $blockers[] = ['code' => 'intent_not_approved', 'message' => 'Az átmozgatási igény jóváhagyása szükséges.'];
        }
        if ($intent->version >= 4294967295) {
            $blockers[] = ['code' => 'intent_version_exhausted', 'message' => 'Az igény elérte a megengedett legnagyobb verziószámot.'];
        }
        if ($stock === null) {
            $blockers[] = ['code' => 'route_mismatch', 'message' => 'Az igény forrás- vagy célraktára nem egyezik a fuvar útvonalával.'];
        } elseif (!$stock['consistent']) {
            $blockers[] = ['code' => 'stock_inconsistent', 'message' => 'A forrásraktár készletadatai nem egyeznek.'];
        }
        if (!$reservation || $reservation->status === TransferStockReservation::RELEASED) {
            $blockers[] = ['code' => 'source_reservation_required', 'message' => 'A forráskészletet még biztosítani kell.'];
        }
        if (!$task) {
            $blockers[] = ['code' => 'picking_task_required', 'message' => 'A kitárolási feladat még nem készült el.'];
        } elseif ($task->status !== WarehouseTransferTask::PICKED) {
            $blockers[] = ['code' => 'picking_incomplete', 'message' => 'A kitárolás még nem fejeződött be.'];
        } elseif ($activeStaged !== $quantity || $task->expected_quantity !== $quantity || !$this->allocationsMatch($task, $intent)
            || $task->warehouse_id !== (int) $intent->from_warehouse_id || $task->stock_id !== ($stock['stock_id'] ?? null)
            || ($stock['staged_quantity'] ?? 0) < $quantity || $hold || !$reservation
            || $reservation->transfer_intent_id !== $intent->id || $reservation->stock_id !== $task->stock_id
            || $reservation->quantity !== $quantity || $reservation->remaining_quantity !== 0
            || $reservation->status !== TransferStockReservation::CONSUMED) {
            $blockers[] = ['code' => 'staged_quantity_mismatch', 'message' => 'Az igény védett összekészített készlete nem egyezik a kitárolási és foglalási adatokkal.'];
        }

        return [
            'transfer_intent_id' => $intent->id,
            'reference' => $intent->reference,
            'required_quantity' => $quantity,
            'staged_quantity' => $staged,
            'active_staged_quantity' => $activeStaged,
            'dispatched_quantity' => $task?->dispatchedQuantity() ?? 0,
            'reservation_status' => $reservation?->status ?? 'unreserved',
            'picking_task_id' => $task?->id,
            'picking_status' => $task?->status,
            'stock_ready' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    private function allocationsMatch(WarehouseTransferTask $task, TransferIntent $intent): bool
    {
        if (!$task->relationLoaded('allocations')) return (int) $task->allocated_quantity === $intent->quantity;
        if ($task->allocations->sum('quantity') !== $intent->quantity) return false;

        return $task->allocations->every(function (WarehouseTransferAllocation $row) use ($intent) {
            if ($row->quantity < 1 || (int) $row->event?->transfer_intent_id !== $intent->id
                || $row->event?->event_type !== 'stock_picked' || (int) $row->event->actor_id !== $row->performed_by) return false;

            return $row->source_type === WarehouseTransferAllocation::SOURCE_UNASSIGNED
                ? $row->warehouse_location_id === null && $row->event->unassignedConfirmation() !== null
                : (int) $row->location?->warehouse_id === (int) $intent->from_warehouse_id;
        });
    }
}
