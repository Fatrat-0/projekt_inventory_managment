<?php

namespace App\Services;

use App\Models\{Product, Shipment, ShipmentReceiptItem, ShipmentReceiptPutaway, User, WarehouseLocation, WarehousePlacement};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShipmentReceiptPutawayService
{
    public const MAX_VERSION = ReceiptPutawayCargo::MAX_VERSION;

    public function __construct(
        private SmartPutaway $smartPutaway,
        private WarehouseCapacity $capacity,
        private WarehouseStockAvailability $availability,
        private TransferWorkflowOperations $operations,
        private ReceiptPutawayCargo $cargo,
    ) {}

    public function recommendations(User $actor, ShipmentReceiptItem $item, array $input): array
    {
        return $this->evaluate($actor, $item, $input, false);
    }

    public function locations(User $actor, ShipmentReceiptItem $item, array $input): array
    {
        return $this->evaluate($actor, $item, $input, true);
    }

    private function evaluate(User $actor, ShipmentReceiptItem $item, array $input, bool $manual): array
    {
        $data = $this->cargo->validate($input, false, $manual);

        return DB::transaction(function () use ($actor, $item, $data, $manual) {
            $current = ShipmentReceiptItem::findOrFail($item->id);
            Gate::forUser($actor)->authorize('putawayReceiptItem', [Shipment::class, $current]);
            $this->assertRemaining($current, $data['quantity']);
            $product = $current->product;
            $warehouse = $current->warehouse;
            $weight = $this->cargo->weight($data, $product);
            $cargo = $this->cargo->assessmentInput($data, $weight);

            $evaluation = $manual
                ? ['locations' => $this->smartPutaway->assessLocations($warehouse, $product, $cargo, $data['to_location_id'] ?? null, $data['search'] ?? null)]
                : $this->smartPutaway->recommend($warehouse, $product, $cargo);

            return $evaluation + [
                'warehouse_id' => (int) $current->to_warehouse_id,
                'product_id' => (int) $current->product_id,
                'quantity' => $data['quantity'],
                'item' => self::itemState($current),
                'cargo' => $this->cargo->summary($data, $weight),
            ];
        });
    }

    public function putaway(User $actor, ShipmentReceiptItem $item, array $input): array
    {
        $data = $this->cargo->validate($input, true);
        $key = $data['operation_key'];
        $hash = $this->operations->fingerprint('shipment_receipt_putaway', $actor, null, [
            'receipt_item_id' => $item->id,
            'request' => array_diff_key($data, ['operation_key' => true]),
        ]);

        try {
            return DB::transaction(function () use ($actor, $item, $data, $key, $hash) {
                $actor = $this->operations->currentActor($actor);
                // The destination and product are immutable receipt identities. Take
                // the shared inventory locks before touching protected receipt rows.
                $product = Product::whereKey($item->getRawOriginal('product_id'))->lockForUpdate()->firstOrFail();
                $warehouseId = (int) $item->getRawOriginal('to_warehouse_id');
                $this->capacity->lockWarehouses([$warehouseId]);
                $current = ShipmentReceiptItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('putawayReceiptItem', [Shipment::class, $current]);
                $stale = $current->putaway_version !== $data['version'];
                if ($prior = $this->findEvent($key, $stale)) return $this->replay($prior, $hash);
                if ($stale || $current->putaway_version >= self::MAX_VERSION) {
                    throw new ConflictHttpException('A betárolási tétel megváltozott vagy elérte a verzióhatárt. Frissítsd az átvételi tételt.');
                }
                $this->assertRemaining($current, $data['quantity']);
                $location = WarehouseLocation::where('warehouse_id', $warehouseId)->whereKey($data['to_location_id'])->lockForUpdate()->first();
                if (!$location) {
                    throw ValidationException::withMessages(['to_location_id' => 'Válassz az átvétel célraktárához tartozó tárhelyet.']);
                }
                $stock = $this->availability->snapshotForUpdate($warehouseId, (int) $product->getKey());
                if (!$stock['consistent'] || !$stock['stock_id'] || $stock['pending_putaway_quantity'] < $current->pending_putaway_quantity) {
                    throw new ConflictHttpException('Az átvételi tétel és a raktári készlet adatai eltérnek. Ellenőrizd a készletet.');
                }

                $weight = $this->cargo->weight($data, $product, true);
                $placed = $this->capacity->placeAssignedStock($location, $product, $stock['stock_id'], $data['quantity'],
                    $weight, $data['pallet_slots'], $data['dimensions_cm'], $data['site_verified']);
                $current->forceFill([
                    'pending_putaway_quantity' => $current->pending_putaway_quantity - $data['quantity'],
                    'putaway_version' => $current->putaway_version + 1,
                ])->save();

                return $this->record($actor, $current, $location, $placed['placement'], $placed['geometry_status'], $data, $weight, $hash);
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            if ($prior = $this->findEvent($key, true)) return $this->replay($prior, $hash);
            throw $error;
        }
    }

    public static function itemState(ShipmentReceiptItem $item): array
    {
        $placed = $item->received_quantity - $item->pending_putaway_quantity;

        return [
            'id' => $item->id,
            'received_quantity' => $item->received_quantity,
            'placed_quantity' => $placed,
            'pending_putaway_quantity' => $item->pending_putaway_quantity,
            'version' => $item->putaway_version,
            'status' => $item->pending_putaway_quantity === 0 ? 'completed' : ($placed > 0 ? 'partial' : 'pending'),
            'can_putaway' => $item->pending_putaway_quantity > 0 && $item->putaway_version < self::MAX_VERSION,
        ];
    }

    private function assertRemaining(ShipmentReceiptItem $item, int $quantity): void
    {
        if ($quantity > $item->pending_putaway_quantity) {
            throw new ConflictHttpException('Ebből az átvételi tételből legfeljebb '.$item->pending_putaway_quantity.' db vár betárolásra. Frissítsd az adatokat.');
        }
    }

    private function record(User $actor, ShipmentReceiptItem $item, WarehouseLocation $location, WarehousePlacement $placement,
        string $geometryStatus, array $data, int $weight, string $hash): array
    {
        $event = ShipmentReceiptPutaway::create([
            'shipment_receipt_item_id' => $item->id,
            'warehouse_placement_id' => $placement->id,
            'warehouse_location_id' => $location->id,
            'location_code' => $location->code,
            'location_name' => $location->name,
            'actor_id' => $actor->id,
            'quantity' => $data['quantity'],
            'cargo_weight_grams' => $weight,
            'pallet_slots' => $data['pallet_slots'],
            'dimensions_cm' => $data['dimensions_cm'] ?: null,
            'geometry_status' => $geometryStatus,
            'site_verified' => $data['site_verified'],
            'weight_source' => $data['weight_source'],
            'packaging_revision' => $data['cargo_weight_packaging'],
            'note' => $data['note'],
            'operation_key' => $data['operation_key'],
            'request_hash' => $hash,
            'placed_at' => now(),
            'result' => [
                'item' => self::itemState($item),
                'placement' => [
                    'id' => $placement->id, 'location_id' => $location->id,
                    'location_code' => $location->code, 'location_name' => $location->name,
                    'quantity' => $data['quantity'],
                ],
            ],
        ]);

        return $this->response($event->refresh());
    }

    private function findEvent(string $key, bool $current): ?ShipmentReceiptPutaway
    {
        $query = ShipmentReceiptPutaway::where('operation_key', $key);

        return ($current ? $query->sharedLock() : $query)->first();
    }

    private function replay(ShipmentReceiptPutaway $prior, string $hash): array
    {
        if (!hash_equals($prior->request_hash, $hash)) {
            throw new ConflictHttpException('Ez a műveleti azonosító már másik betárolási kéréshez tartozik.');
        }

        return $this->response($prior);
    }

    private function response(ShipmentReceiptPutaway $event): array
    {
        return $event->result + ['event' => $event->eventData()];
    }
}
