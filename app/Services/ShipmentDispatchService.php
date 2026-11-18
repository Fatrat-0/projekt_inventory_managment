<?php

namespace App\Services;

use App\Models\{Product, Shipment, ShipmentIntentAssignment, ShipmentStop, TransferIntent, User, Warehouse};
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ShipmentDispatchService
{
    public function __construct(
        private WarehouseCapacity $warehouseLocks,
        private WarehouseStockAvailability $availability,
        private ShipmentPlanReadiness $readiness,
        private InventoryService $inventory,
    ) {}

    /** Called by the versioned planning transaction with the shipment and all active intents locked. */
    public function execute(User $actor, Shipment $shipment, ModelCollection $stops, ModelCollection $assignments, Collection $intents, ?string $note): void
    {
        if (DB::transactionLevel() === 0) throw new LogicException('A fuvar feladásához aktív tranzakció szükséges.');

        $origin = (int) $shipment->origin_warehouse_id;
        $stopsById = $stops->keyBy('id');
        $intents = $intents->keyBy('id');
        foreach ($assignments as $assignment) {
            $assignment->setRelation('intent', $intents->get($assignment->transfer_intent_id));
            $assignment->setRelation('stop', $stopsById->get($assignment->shipment_stop_id));
        }

        // The picking and ordinary inventory writers also acquire product before warehouse and stock rows.
        $productIds = $intents->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $products = [];
        foreach ($productIds as $productId) {
            $products[$productId] = Product::whereKey($productId)->lockForUpdate()->firstOrFail(['product_id', 'product_name', 'sku']);
        }
        $warehouseIds = [$origin, ...$intents->pluck('to_warehouse_id')->all()];
        $this->warehouseLocks->lockWarehouses($warehouseIds);

        $stocks = [];
        foreach ($productIds as $productId) $stocks[$productId] = $this->availability->snapshotForUpdate($origin, $productId);
        [$readiness, $cargo, $tasks] = $this->readiness->lockedPreparation($shipment, $assignments, [$origin => $stocks]);
        if (!$readiness['dispatch_prerequisites_met']) throw new ShipmentDispatchBlocked($readiness['dispatch_blockers']);

        $at = now();
        $warehouses = Warehouse::whereKey($warehouseIds)->sharedLock()->get(['warehouse_id', 'warehouse_name', 'location'])->keyBy('warehouse_id');
        $byStop = $assignments->groupBy('shipment_stop_id');
        $route = $stops->filter(fn (ShipmentStop $stop) => $byStop->has($stop->id))->values();
        $loading = Shipment::loadingStops($route)->pluck('id')->flip();
        $dispatch = $shipment->dispatch()->create([
            'actor_id' => $actor->id, 'vehicle_id' => $shipment->vehicle_id, 'dispatched_at' => $at, 'note' => $note,
            'snapshot' => [
                'actor_name' => $actor->name,
                'shipment' => ['id' => $shipment->id, 'shipment_number' => $shipment->shipment_number, 'reference' => $shipment->reference,
                    'origin_warehouse_id' => $origin, 'origin_warehouse_name' => $warehouses[$origin]->warehouse_name,
                    'origin_warehouse_location' => $warehouses[$origin]->location,
                    'planned_departure_at' => $shipment->planned_departure_at?->toISOString()],
                'vehicle' => $cargo['vehicle'], 'cargo' => $cargo,
                'capacity' => $cargo['capacity'], 'readiness' => $readiness,
                'loading_strategy' => 'reverse_stop_order',
                'stops' => $route->map(fn (ShipmentStop $stop) => [
                    'id' => $stop->id, 'warehouse_id' => (int) $stop->warehouse_id,
                    'warehouse_name' => $warehouses[$stop->warehouse_id]->warehouse_name,
                    'warehouse_location' => $warehouses[$stop->warehouse_id]->location,
                    'position' => $stop->position, 'loading_position' => $loading[$stop->id] + 1,
                    'intent_ids' => $byStop[$stop->id]->pluck('transfer_intent_id')->all(),
                ])->all(),
                'intents' => $assignments->map(fn (ShipmentIntentAssignment $assignment) => [
                    'transfer_intent_id' => $assignment->transfer_intent_id, 'assignment_id' => $assignment->id,
                    'reference' => $assignment->intent->reference, 'product_id' => (int) $assignment->intent->product_id,
                    'product_name' => $products[$assignment->intent->product_id]->product_name,
                    'sku' => $products[$assignment->intent->product_id]->sku,
                    'quantity' => $assignment->intent->quantity, 'shipment_stop_id' => $assignment->shipment_stop_id,
                    'to_warehouse_id' => (int) $assignment->intent->to_warehouse_id,
                    'warehouse_transfer_task_id' => $tasks[$assignment->transfer_intent_id]->id,
                ])->all(),
            ],
        ]);

        $items = $assignments->map(function (ShipmentIntentAssignment $assignment) use ($dispatch, $origin, $tasks) {
            $intent = $assignment->intent;

            return $dispatch->transitItems()->create([
                'shipment_id' => $assignment->shipment_id,
                'shipment_intent_assignment_id' => $assignment->id, 'transfer_intent_id' => $intent->id,
                'warehouse_transfer_task_id' => $tasks[$intent->id]->id,
                'product_id' => $intent->product_id, 'from_warehouse_id' => $origin, 'to_warehouse_id' => $intent->to_warehouse_id,
                'shipment_stop_id' => $assignment->shipment_stop_id, 'dispatched_quantity' => $intent->quantity,
                'remaining_quantity' => $intent->quantity, 'status' => 'in_transit',
            ]);
        });
        $this->inventory->bookShipmentDispatch($dispatch, $items, $stocks);

        foreach ($intents as $intent) {
            $tasks[$intent->id]->update(['dispatched_at' => $at]);
            $intent->forceFill(['version' => $intent->version + 1])->save();
        }
        $shipment->forceFill(['status' => 'in_transit', 'started_at' => $at]);
    }

    public function state(Shipment $shipment): array
    {
        $dispatch = $shipment->dispatch()->first();
        $items = $dispatch?->transitItems()->with('movement:id,shipment_transit_item_id')->orderBy('id')->get() ?? collect();

        return [
            'shipment' => ['id' => $shipment->id, 'status' => $shipment->status, 'version' => $shipment->version,
                'started_at' => $shipment->started_at?->toISOString()],
            'dispatch' => $dispatch ? [
                'id' => $dispatch->id, 'shipment_id' => $shipment->id, 'actor_id' => $dispatch->actor_id,
                'actor_name' => $dispatch->snapshot['actor_name'] ?? $dispatch->actor()->value('name'),
                'vehicle_id' => $dispatch->vehicle_id, 'dispatched_at' => $dispatch->dispatched_at->toISOString(),
                'note' => $dispatch->note, 'snapshot' => $dispatch->snapshot,
            ] : null,
            'dispatched_intent_count' => $items->count(), 'transit_quantity' => $items->sum('remaining_quantity'),
            'transit_items' => $items->map(fn ($item) => [
                'id' => $item->id, 'shipment_id' => $item->shipment_id, 'shipment_dispatch_id' => $item->shipment_dispatch_id,
                'transfer_intent_id' => $item->transfer_intent_id, 'shipment_intent_assignment_id' => $item->shipment_intent_assignment_id,
                'warehouse_transfer_task_id' => $item->warehouse_transfer_task_id, 'product_id' => $item->product_id,
                'from_warehouse_id' => $item->from_warehouse_id, 'to_warehouse_id' => $item->to_warehouse_id,
                'shipment_stop_id' => $item->shipment_stop_id, 'dispatched_quantity' => $item->dispatched_quantity,
                'remaining_quantity' => $item->remaining_quantity, 'received_quantity' => $item->dispatched_quantity - $item->remaining_quantity,
                'status' => $item->status, 'movement_id' => $item->movement?->id,
            ])->all(),
        ];
    }
}
