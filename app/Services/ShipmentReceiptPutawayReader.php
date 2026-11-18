<?php

namespace App\Services;

use App\Http\Middleware\RoleMiddleware;
use App\Models\{Shipment, ShipmentReceiptItem, ShipmentReceiptPutaway, User, WarehouseLocation};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

class ShipmentReceiptPutawayReader
{
    public function show(User $actor, ShipmentReceiptItem $item): array
    {
        $item = $this->authorizedItem($actor, $item);
        $item->load([
            'receipt.actor:id,name', 'receipt.shipment:id,reference,status', 'receipt.stop:id,position', 'transitItem:id,dispatched_quantity',
            'intent:id,reference', 'product.defaultPackaging', 'warehouse:warehouse_id,warehouse_name',
        ]);

        $receipt = $item->receipt;
        $packaging = $item->product->defaultPackaging;

        return [
            'item' => ShipmentReceiptPutawayService::itemState($item) + [
                'receipt_id' => (int) $item->shipment_receipt_id,
                'shipment_stop_id' => (int) $item->shipment_stop_id,
                'transit_item_id' => (int) $item->shipment_transit_item_id,
                'dispatched_quantity' => $item->transitItem->dispatched_quantity,
                'transfer_intent_id' => (int) $item->transfer_intent_id,
                'intent_reference' => $item->intent->reference,
                'to_warehouse_id' => (int) $item->to_warehouse_id,
                'warehouse_name' => $item->warehouse->warehouse_name,
                'product_id' => (int) $item->product_id,
                'product_name' => $item->product->product_name,
                'sku' => $item->product->sku,
                'note' => $item->note,
            ],
            'receipt' => [
                'id' => $receipt->id,
                'received_at' => $receipt->received_at->toISOString(),
                'actor_id' => (int) $receipt->actor_id,
                'actor_name' => $receipt->actor->name,
            ],
            'shipment' => [
                'id' => $receipt->shipment->id,
                'shipment_number' => $receipt->shipment->shipment_number,
                'reference' => $receipt->shipment->reference,
                'status' => $receipt->shipment->status,
                'stop_position' => $receipt->stop->position,
            ],
            'packaging' => $packaging === null ? null : [
                'id' => $packaging->id,
                'name' => $packaging->name,
                'pieces_per_package' => $packaging->pieces_per_package,
                'gross_weight_grams' => $packaging->grossWeightGrams(),
                'dimensions_cm' => [
                    'width' => $packaging->width_cm,
                    'depth' => $packaging->depth_cm,
                    'height' => $packaging->height_cm,
                ],
                'revision' => TransferCargoWeight::packagingRevision($packaging),
            ],
        ];
    }

    public function history(User $actor, ShipmentReceiptItem $item, array $filters): LengthAwarePaginator
    {
        $item = $this->authorizedItem($actor, $item);
        $page = $item->putaways()->with('actor:id,name')->withExists('placement')->latest('id')
            ->paginate($filters['per_page'] ?? 25, page: $filters['page'] ?? 1)->appends($filters);

        $locations = $this->locations((int) $item->to_warehouse_id, $page->getCollection()->pluck('warehouse_location_id')->all(), ['system']);

        return $page->through(fn (ShipmentReceiptPutaway $event) => $event->eventData() + [
            'receipt_item_id' => $item->id,
            'transfer_intent_id' => (int) $item->transfer_intent_id,
            'product_id' => (int) $item->product_id,
            'warehouse_id' => (int) $item->to_warehouse_id,
            'actor_name' => $event->actor->name,
            'placement_id' => (int) $event->warehouse_placement_id,
            'placement_exists' => $event->placement_exists,
            'location_id' => (int) $event->warehouse_location_id,
            'location_code' => $event->location_code,
            'location_name' => $event->location_name,
            'item_after' => $event->result['item'],
            'location_view_url' => $this->locationViewUrl($actor, $item, $locations->get($event->warehouse_location_id)),
        ]);
    }

    /** @param list<array> $rows */
    public function withLocationMetadata(array $rows, int $warehouseId, ShipmentReceiptItem $item): array
    {
        if ($rows === []) return [];

        $locations = $this->locations($warehouseId, array_column($rows, 'location_id'), ['system.zone', 'level']);

        return array_map(function (array $row) use ($locations, $warehouseId, $item) {
            $location = $locations->get($row['location_id']);
            if ($location === null) return $row;

            $putawayPlanUrl = $this->putawayPlanUrl($item, $location);
            $floorParams = $location->system === null
                ? ['location' => $location->id]
                : ['system' => $location->system->id, 'bin' => $location->id];

            return $row + [
                'position' => $location->pickingPosition(),
                'system_id' => $location->system?->id,
                'level_id' => $location->level?->id,
                'location_url' => route('warehouses.locations.show', [$warehouseId, $location]),
                'floor_plan_url' => $putawayPlanUrl !== null ? route('warehouses.floor-plan.show', ['warehouse' => $warehouseId] + $floorParams) : null,
                'putaway_floor_plan_url' => $putawayPlanUrl,
            ];
        }, $rows);
    }

    public function placedLocationViewUrl(User $actor, ShipmentReceiptItem $item, int $locationId): ?string
    {
        $location = $this->locations((int) $item->to_warehouse_id, [$locationId], ['system'])->get($locationId);

        return $this->locationViewUrl($actor, $item, $location);
    }

    private function locations(int $warehouseId, array $ids, array $relations): Collection
    {
        if ($ids === []) return new Collection;

        return WarehouseLocation::where('warehouse_id', $warehouseId)->whereKey($ids)->with($relations)->get()->keyBy('id');
    }

    private function locationViewUrl(User $actor, ShipmentReceiptItem $item, ?WarehouseLocation $location): ?string
    {
        if ($location === null) return null;

        return $this->putawayPlanUrl($item, $location) ??
            (in_array($actor->role, RoleMiddleware::INVENTORY_ROLES, true)
                ? route('warehouses.locations.show', [$item->to_warehouse_id, $location])
                : null);
    }

    private function putawayPlanUrl(ShipmentReceiptItem $item, WarehouseLocation $location): ?string
    {
        $object = $location->system ?? $location;

        return $object->floor_x !== null && $object->floor_y !== null
            ? route('warehouse-putaway-pages.plan', [$item, $location])
            : null;
    }

    private function authorizedItem(User $actor, ShipmentReceiptItem $routeItem): ShipmentReceiptItem
    {
        $item = ShipmentReceiptItem::findOrFail($routeItem->id);
        Gate::forUser($actor)->authorize('putawayReceiptItem', [Shipment::class, $item]);

        return $item;
    }
}
