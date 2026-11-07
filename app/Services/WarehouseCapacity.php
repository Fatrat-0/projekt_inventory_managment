<?php

namespace App\Services;

use App\Models\{InventoryTransfer, Product, Stock, Warehouse, WarehouseLocation, WarehousePlacement};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseCapacity
{
    public const WEIGHT_RULE = 'numeric|decimal:0,3|min:0.001|max:1000000';
    public const SLOT_RULE = 'integer|min:1|max:1000000';

    public static function cargoRules(string $prefix = ''): array
    {
        return [
            $prefix.'to_location_id' => 'nullable|integer|min:1|exists:warehouse_locations,id',
            $prefix.'cargo_weight_kg' => 'nullable|'.self::WEIGHT_RULE,
            $prefix.'pallet_slots' => 'nullable|'.self::SLOT_RULE,
        ];
    }

    public static function grams(float|string $kg): int
    {
        return (int) round((float) $kg * 1000);
    }

    public static function cargo(array $data): array
    {
        $location = $data['to_location_id'] ?? null;
        $weight = isset($data['cargo_weight_kg']) ? self::grams($data['cargo_weight_kg']) : ($data['cargo_weight_grams'] ?? null);
        $slots = (int) ($data['pallet_slots'] ?? 0);
        if ($location === null && $weight === null && $slots === 0) return [];

        return ['to_location_id' => $location === null ? null : (int) $location, 'cargo_weight_grams' => $weight, 'pallet_slots' => $slots];
    }

    /** Call after acquiring all product locks, before locking stock or capacity rows. */
    public function lockWarehouses(array $ids): void
    {
        $ids = array_unique(array_map('intval', $ids));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) Warehouse::whereKey($id)->lockForUpdate()->firstOrFail(['warehouse_id']);
    }

    public function saveLocation(Warehouse $warehouse, array $data, ?WarehouseLocation $location = null): WarehouseLocation
    {
        return DB::transaction(function () use ($warehouse, $data, $location) {
            $this->lockWarehouses([$warehouse->warehouse_id]);
            $current = $location ? $warehouse->storageLocations()->lockForUpdate()->findOrFail($location->id) : null;
            if ($current && $current->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'A tárhelyet közben módosították. Töltsd újra az oldalt.'])->status(409);
            }
            $duplicate = $warehouse->storageLocations()->where('code', $data['code']);
            if ($current) $duplicate->whereKeyNot($current->id);
            if ($duplicate->sharedLock()->first(['id'])) {
                throw ValidationException::withMessages(['code' => 'Ez a tárhelykód már szerepel a raktárban.']);
            }

            $weight = self::grams($data['max_weight_kg']);
            $slots = $data['kind'] === 'pallet' ? (int) $data['max_pallet_slots'] : null;
            $active = (bool) ($data['active'] ?? true);
            if ($current) {
                [$usedWeight, $usedSlots] = $this->usage($current);
                if ($usedWeight > 0 && (!$active || $data['kind'] !== $current->kind)) {
                    throw ValidationException::withMessages(['kind' => 'Csak üres, foglalás nélküli tárhely kapcsolható ki vagy alakítható át.']);
                }
                if ($usedWeight > $weight || ($slots !== null && $usedSlots > $slots)) {
                    throw ValidationException::withMessages(['max_weight_kg' => 'Az új határ nem lehet kisebb a tényleges és lefoglalt terhelésnél vagy raklaphelynél.']);
                }
                $current->lock_version++;
            }
            $current ??= $warehouse->storageLocations()->make();
            $current->fill([
                'code' => $data['code'], 'name' => $data['name'], 'kind' => $data['kind'], 'active' => $active,
                'max_weight_grams' => $weight, 'max_pallet_slots' => $slots,
            ])->save();

            return $current;
        }, 3);
    }

    public function place(WarehouseLocation $location, array $data): void
    {
        $productId = (int) $data['product_id'];
        $quantity = (int) $data['quantity'];
        $weight = self::grams($data['cargo_weight_kg']);
        $slots = (int) ($data['pallet_slots'] ?? 0);
        $key = isset($data['request_key']) ? strtolower($data['request_key']) : null;
        $hash = $key === null ? null : hash('sha256', json_encode([(int) $data['user_id'], $location->id, $productId, $quantity, $weight, $slots], JSON_THROW_ON_ERROR));

        try {
            DB::transaction(function () use ($location, $productId, $quantity, $weight, $slots, $key, $hash) {
                if ($key !== null) DB::table('warehouse_placement_requests')->insert(['request_key' => $key, 'payload_hash' => $hash, 'created_at' => now()]);
                Product::whereKey($productId)->lockForUpdate()->firstOrFail();
                $this->lockWarehouses([$location->warehouse_id]);
                $current = WarehouseLocation::whereKey($location->id)->lockForUpdate()->firstOrFail();
                $stock = Stock::where('warehouse_id', $current->warehouse_id)->where('product_id', $productId)->lockForUpdate()->first();
                if (!$stock || $quantity > $stock->available_quantity - $this->assignedQuantity($stock)) {
                    throw ValidationException::withMessages(['quantity' => 'Ennyi kiadható, tárhely nélküli készlet nem áll rendelkezésre ebben a raktárban.']);
                }
                $this->assertFits($current, $weight, $slots);
                $current->placements()->create(['stock_id' => $stock->id, 'quantity' => $quantity,
                    'cargo_weight_grams' => $weight, 'pallet_slots' => $slots]);
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            if ($key === null) throw $e;
            $previous = DB::table('warehouse_placement_requests')->where('request_key', $key)->sharedLock()->value('payload_hash');
            if ($previous === null) throw $e;
            if (!hash_equals($previous, $hash)) {
                throw ValidationException::withMessages(['request_key' => 'Ez az elhelyezés már más adatokkal szerepel. Az új beküldést ellenőrizd és próbáld újra.']);
            }
        }
    }

    public function release(WarehouseLocation $location, WarehousePlacement $placement): void
    {
        $stock = $placement->stock;
        DB::transaction(function () use ($location, $placement, $stock) {
            Product::whereKey($stock->product_id)->lockForUpdate()->firstOrFail();
            $this->lockWarehouses([$location->warehouse_id]);
            $location->placements()->whereKey($placement->id)->delete();
        }, 3);
    }

    public function assignedQuantity(Stock $stock): int
    {
        return (int) $stock->placements()->lockForUpdate()->get(['quantity'])->sum('quantity');
    }

    /** The caller holds the destination warehouse lock until the transfer is written. */
    public function checkReservation(array $data): void
    {
        $locations = WarehouseLocation::where('warehouse_id', $data['to_warehouse_id']);
        $id = $data['to_location_id'] ?? null;
        if ($id === null) {
            if ($locations->sharedLock()->first(['id']) || isset($data['cargo_weight_grams']) || !empty($data['pallet_slots'])) {
                throw ValidationException::withMessages(['to_location_id' => 'A célraktárhoz válassz aktív tárhelyet.']);
            }

            return;
        }

        $location = $locations->whereKey($id)->lockForUpdate()->first();
        if (!$location) {
            throw ValidationException::withMessages(['to_location_id' => 'A választott tárhely nem a célraktárhoz tartozik.']);
        }
        $this->assertFits($location, (int) ($data['cargo_weight_grams'] ?? 0), (int) ($data['pallet_slots'] ?? 0));
    }

    public function receive(InventoryTransfer $transfer, Stock $stock): void
    {
        if ($transfer->to_location_id === null) return;

        $stock->placements()->create([
            'warehouse_location_id' => $transfer->to_location_id,
            'quantity' => $transfer->quantity,
            'cargo_weight_grams' => $transfer->cargo_weight_grams,
            'pallet_slots' => $transfer->pallet_slots,
        ]);
    }

    private function assertFits(WarehouseLocation $location, int $weight, int $slots): void
    {
        if (!$location->active) {
            throw ValidationException::withMessages(['to_location_id' => 'Ez a tárhely ki van kapcsolva. Válassz aktív tárhelyet.']);
        }
        if ($weight < 1 || $weight > 1000000000) {
            throw ValidationException::withMessages(['cargo_weight_kg' => 'Add meg a tétel teljes tömegét kilogrammban.']);
        }
        if (($location->kind === 'pallet' && $slots < 1) || ($location->kind === 'shelf' && $slots !== 0)) {
            throw ValidationException::withMessages(['pallet_slots' => 'Raklaphelyhez add meg a raklapok számát. Polchoz hagyd üresen a mezőt.']);
        }
        [$usedWeight, $usedSlots] = $this->usage($location);
        $errors = [];
        if ($weight > $location->max_weight_grams - $usedWeight) {
            $errors['cargo_weight_kg'] = $location->code.' tárhelyen '.WarehouseLocation::formatWeight($location->max_weight_grams - $usedWeight).' kg szabad teherbírás maradt.';
        }
        if ($location->max_pallet_slots !== null && $slots > $location->max_pallet_slots - $usedSlots) {
            $free = $location->max_pallet_slots - $usedSlots;
            $errors['pallet_slots'] = $location->code.' tárhelyen '.$free.' szabad raklaphely van. Hiányzik '.($slots - $free).' hely.';
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function usage(WarehouseLocation $location): array
    {
        $columns = ['cargo_weight_grams', 'pallet_slots'];
        $occupied = $location->placements()->lockForUpdate()->get($columns);
        $reserved = $location->reservations()->lockForUpdate()->get($columns);

        return [
            (int) $occupied->sum('cargo_weight_grams') + (int) $reserved->sum('cargo_weight_grams'),
            (int) $occupied->sum('pallet_slots') + (int) $reserved->sum('pallet_slots'),
        ];
    }
}
