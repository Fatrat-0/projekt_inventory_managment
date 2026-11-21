<?php

namespace App\Services;

use App\Models\{ManualReceiptBatch, ManualReceiptPutaway, Product, Shipment, User, Warehouse, WarehouseLocation};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ManualReceiptPutawayService
{
    public function __construct(
        private ReceiptPutawayCargo $cargo,
        private SmartPutaway $smartPutaway,
        private WarehouseCapacity $capacity,
        private WarehouseStockAvailability $availability,
        private TransferWorkflowOperations $operations,
    ) {}

    public function recommendations(User $actor, ManualReceiptBatch $batch, array $input): array
    {
        return $this->evaluate($actor, $batch, $input, 'recommendations');
    }

    public function locations(User $actor, ManualReceiptBatch $batch, array $input): array
    {
        return $this->evaluate($actor, $batch, $input, 'locations');
    }

    public function preview(User $actor, ManualReceiptBatch $batch, array $input): array
    {
        return $this->evaluate($actor, $batch, $input, 'preview');
    }

    private function evaluate(User $actor, ManualReceiptBatch $batch, array $input, string $mode): array
    {
        return DB::transaction(function () use ($actor, $batch, $input, $mode) {
            $current = ManualReceiptBatch::findOrFail($batch->id);
            $this->authorize($actor, $current);
            $data = $this->cargo->validate($input, false, $mode !== 'recommendations');
            $this->assertRemaining($current, $data['quantity']);
            $weight = $this->cargo->weight($data, $current->product);
            $cargo = $this->cargo->assessmentInput($data, $weight);
            $result = ['batch' => $current->state(), 'quantity' => $data['quantity'], 'cargo' => $this->cargo->summary($data, $weight)];

            if ($mode === 'recommendations') {
                return $result + $this->smartPutaway->recommend($current->warehouse, $current->product, $cargo);
            }
            if ($mode === 'locations') {
                return $result + ['locations' => $this->smartPutaway->assessLocations(
                    $current->warehouse, $current->product, $cargo, $data['to_location_id'] ?? null, $data['search'] ?? null,
                )];
            }

            $preview = Validator::make($input, [
                'version' => 'required|integer|min:1|max:'.ReceiptPutawayCargo::MAX_VERSION,
                'to_location_id' => 'required',
                'site_verified' => 'sometimes|boolean',
            ], [
                'version.*' => 'Add meg a bevételezési tétel aktuális verzióját.',
                'to_location_id.required' => 'Válassz célrekeszt.',
                'site_verified.*' => 'Add meg, hogy megtörtént-e a helyszíni illeszkedés ellenőrzése.',
            ])->validate();
            $this->assertVersion($current, (int) $preview['version']);
            $location = $this->smartPutaway->assessLocations($current->warehouse, $current->product, $cargo, $data['to_location_id'])[0] ?? null;
            if ($location === null) $this->invalidLocation();
            $this->assertProtectedStock($current, $this->availability->snapshot($current->warehouse_id, $current->product_id));
            $missing = $location['checks']['missing'];
            $geometry = array_intersect_key($missing, array_flip(['cargo_dimensions', 'location_dimensions', 'remaining_physical_space']));
            $otherMissing = array_diff_key($missing, $geometry);
            $siteVerified = (bool) ($preview['site_verified'] ?? false);
            $blocking = $location['checks']['failed'] + $otherMissing + ($siteVerified ? [] : $geometry);

            return $result + [
                'location' => $location, 'blocking_reasons' => (object) $blocking,
                'missing_information' => (object) $missing, 'site_verification_required' => $geometry !== [],
                'can_putaway' => $blocking === [],
            ];
        });
    }

    public function putaway(User $actor, ManualReceiptBatch $batch, array $input): array
    {
        $this->authorize($actor, $batch);
        $data = $this->cargo->validate($input, true);
        $key = $data['operation_key'];
        $hash = $this->operations->fingerprint('manual_receipt_putaway', $actor, null, [
            'batch_id' => $batch->id, 'request' => array_diff_key($data, ['operation_key' => true]),
        ]);

        try {
            return DB::transaction(function () use ($actor, $batch, $data, $key, $hash) {
                $actor = $this->operations->currentActor($actor);
                // Match receipt putaway: product, warehouse, receipt, bin, then stock and holds.
                $product = Product::whereKey($batch->getRawOriginal('product_id'))->lockForUpdate()->firstOrFail();
                $warehouse = Warehouse::whereKey($batch->getRawOriginal('warehouse_id'))->lockForUpdate()->firstOrFail();
                $current = ManualReceiptBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $this->authorize($actor, $current);

                $stale = $current->putaway_version !== $data['version'];
                if ($prior = $this->findEvent($key, $stale)) return $this->result($prior, $hash);
                $this->assertVersion($current, $data['version']);
                $this->assertRemaining($current, $data['quantity']);

                $location = $warehouse->storageLocations()->whereKey($data['to_location_id'])->lockForUpdate()->first();
                if (!$location) $this->invalidLocation();
                $stock = $this->availability->snapshotForUpdate($warehouse->getKey(), $product->getKey());
                $this->assertProtectedStock($current, $stock);
                $weight = $this->cargo->weight($data, $product, true);
                $placed = $this->capacity->placeAssignedStock($location, $product, $stock['stock_id'], $data['quantity'],
                    $weight, $data['pallet_slots'], $data['dimensions_cm'], $data['site_verified']);

                $remaining = $current->pending_putaway_quantity - $data['quantity'];
                $current->forceFill([
                    'pending_putaway_quantity' => $remaining, 'putaway_version' => $current->putaway_version + 1,
                    'closed_at' => $remaining === 0 ? now() : null,
                ])->save();

                $result = [
                    'batch' => $current->state(),
                    'placement' => ['id' => $placed['placement']->id, 'location_id' => $location->id, 'location_code' => $location->code,
                        'location_name' => $location->name, 'quantity' => $data['quantity']],
                ];
                $event = $current->putaways()->create([
                    'warehouse_placement_id' => $placed['placement']->id, 'warehouse_location_id' => $location->id,
                    'location_code' => $location->code, 'location_name' => $location->name, 'product_name' => $product->product_name,
                    'sku' => $product->sku, 'warehouse_name' => $warehouse->warehouse_name,
                    'actor_id' => $actor->id, 'actor_name' => $actor->name, 'quantity' => $data['quantity'],
                    'cargo_weight_grams' => $weight, 'pallet_slots' => $data['pallet_slots'],
                    'dimensions_cm' => $data['dimensions_cm'] ?: null, 'geometry_status' => $placed['geometry_status'],
                    'site_verified' => $data['site_verified'], 'weight_source' => $data['weight_source'],
                    'packaging_revision' => $data['cargo_weight_packaging'], 'note' => $data['note'],
                    'operation_key' => $key, 'request_hash' => $hash, 'placed_at' => now(), 'result' => $result,
                ]);

                return $this->result($event->refresh(), $hash);
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            if ($prior = $this->findEvent($key, true)) return $this->result($prior, $hash);
            throw $error;
        }
    }

    public function authorize(User $actor, ManualReceiptBatch $batch): void
    {
        Gate::forUser($actor)->authorize('putawayInWarehouse', [Shipment::class, (int) $batch->warehouse_id]);
    }

    private function assertRemaining(ManualReceiptBatch $batch, int $quantity): void
    {
        if ($quantity > $batch->pending_putaway_quantity) {
            throw new ConflictHttpException('Ebből a bevételezésből legfeljebb '.$batch->pending_putaway_quantity.' db vár betárolásra. Frissítsd a tételt.');
        }
    }

    private function assertVersion(ManualReceiptBatch $batch, int $version): void
    {
        if ($batch->putaway_version !== $version || $batch->putaway_version >= ReceiptPutawayCargo::MAX_VERSION) {
            throw new ConflictHttpException('A betárolási tétel megváltozott vagy elérte a verzióhatárt. Frissítsd a tételt.');
        }
    }

    private function assertProtectedStock(ManualReceiptBatch $batch, array $stock): void
    {
        if (!$stock['consistent'] || !$stock['stock_id'] || $stock['pending_putaway_quantity'] < $batch->pending_putaway_quantity) {
            throw new ConflictHttpException('A bevételezési tétel és a raktári készlet adatai eltérnek. Ellenőrizd a készletet.');
        }
    }

    private function invalidLocation(): never
    {
        throw ValidationException::withMessages(['to_location_id' => 'Válassz a bevételezés raktárához tartozó tárhelyet.']);
    }

    private function findEvent(string $key, bool $current): ?ManualReceiptPutaway
    {
        $query = ManualReceiptPutaway::where('operation_key', $key);

        return ($current ? $query->sharedLock() : $query)->first();
    }

    private function result(ManualReceiptPutaway $event, string $hash): array
    {
        if (!hash_equals($event->request_hash, $hash)) {
            throw new ConflictHttpException('Ez a műveleti azonosító már másik betárolási kéréshez tartozik.');
        }

        return $event->result + ['event' => $event->eventData()];
    }
}
