<?php

namespace App\Services;

use App\Models\{Category, InventoryTransfer, Product, Stock, Warehouse, WarehouseLocation, WarehousePlacement};
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
                if ($current->system_id && ($current->code !== $data['code'] || $current->kind !== $data['kind']
                    || $current->max_weight_grams !== $weight || $current->max_pallet_slots !== $slots)) {
                    throw ValidationException::withMessages(['code' => 'A létrehozott tárhely kódja és típusa rögzített. A teherbírást a tervrajzon módosíthatod.']);
                }
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
                $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
                $this->lockWarehouses([$location->warehouse_id]);
                $current = WarehouseLocation::whereKey($location->id)->lockForUpdate()->firstOrFail();
                $stock = Stock::where('warehouse_id', $current->warehouse_id)->where('product_id', $productId)->lockForUpdate()->first();
                if (!$stock || $quantity > $stock->available_quantity - $this->assignedQuantity($stock)) {
                    throw ValidationException::withMessages(['quantity' => 'Ennyi kiadható, tárhely nélküli készlet nem áll rendelkezésre ebben a raktárban.']);
                }
                $this->assertFits($current, $weight, $slots, (int) $product->category_id);
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
    public function checkReservation(array $data, int $categoryId): void
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
        $this->assertFits($location, (int) ($data['cargo_weight_grams'] ?? 0), (int) ($data['pallet_slots'] ?? 0), $categoryId);
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

    private function assertFits(WarehouseLocation $location, int $weight, int $slots, int $categoryId): void
    {
        $categories = $location->system_id === null ? [] : $this->lockedCategoryIds($location);
        [$usedWeight, $usedSlots] = $this->usage($location);
        $limits = ['location_grams' => $location->max_weight_grams - $usedWeight,
            'slots' => $location->max_pallet_slots === null ? null : $location->max_pallet_slots - $usedSlots];
        $this->assertChecks($this->fitChecks($location, $categoryId, $weight, $slots, $categories, $limits));
        if ($location->system_id !== null) {
            $system = $location->system()->lockForUpdate()->firstOrFail();
            $level = $location->level_id === null ? null : $location->level()->lockForUpdate()->firstOrFail();
            $bins = $system->locations()->lockForUpdate()->get(['id', 'level_id']);
            $committed = $this->committedWeights($bins->modelKeys());
            $limits['system_grams'] = $system->max_weight_grams - array_sum($committed);
            $limits['system_name'] = $system->name;
            if ($level) {
                $limits['level_grams'] = $level->max_weight_grams - $bins->where('level_id', $level->id)->sum(fn ($bin) => $committed[$bin->id] ?? 0);
            }

            $this->assertChecks($this->fitChecks($location, $categoryId, $weight, $slots, $categories, $limits));
        }
    }

    /**
     * Evaluate the same rules against a capacity snapshot, without queries or writes.
     * A null result marks an unknown input, not a passed check.
     *
     * @param list<int> $allowedCategories
     * @param array{location_grams: int, slots: ?int, level_grams?: int, system_grams?: int, system_name?: string} $limits
     * @return array<string, array{passed: ?bool, field: string, message: string}>
     */
    public function fitChecks(WarehouseLocation $location, int $categoryId, ?int $weight, ?int $slots, array $allowedCategories, array $limits): array
    {
        $weightFits = fn (int $free) => $weight === null ? ($free <= 0 ? false : null) : $weight <= $free;
        $check = fn (?bool $passed, string $field, string $message) => compact('passed', 'field', 'message');
        $pallet = $location->kind === 'pallet';
        $checks = [
            'active' => $check($location->active, 'to_location_id', 'Ez a tárhely ki van kapcsolva. Válassz aktív tárhelyet.'),
            'category' => $check($location->system_id === null || $this->categoryAllowed($allowedCategories, $categoryId),
                'to_location_id', $this->categoryMessage($location)),
            'weight_input' => $check($weight === null ? null : ($weight >= 1 && $weight <= 1000000000),
                'cargo_weight_kg', 'Add meg a tétel teljes tömegét kilogrammban.'),
            'pallet_kind' => $check($slots === null ? null : ($pallet ? $slots >= 1 : ($location->kind !== 'shelf' || $slots === 0)),
                'pallet_slots', 'Raklaphelyhez add meg a raklapok számát. Polchoz hagyd üresen a mezőt.'),
            'location_weight' => $check($weightFits($limits['location_grams']), 'cargo_weight_kg', $location->code.' tárhelyen '
                .WarehouseLocation::formatWeight($limits['location_grams']).' kg szabad teherbírás maradt.'),
        ];
        if ($limits['slots'] !== null) {
            $free = $limits['slots'];
            $checks['pallet_space'] = $check($slots === null && $pallet ? ($free <= 0 ? false : null) : ($slots ?? 0) <= $free,
                'pallet_slots', $location->code.' tárhelyen '.$free.' szabad raklaphely van.'
                    .($slots === null ? '' : ' Hiányzik '.($slots - $free).' hely.'));
        }
        if (isset($limits['level_grams'])) {
            $checks['level_weight'] = $check($weightFits($limits['level_grams']), 'cargo_weight_kg', 'A tétel meghaladná az állványszint szabad teherbírását.');
        }
        if (isset($limits['system_grams'])) {
            $checks['system_weight'] = $check($weightFits($limits['system_grams']), 'cargo_weight_kg',
                'A tétel meghaladná a(z) '.$limits['system_name'].' szabad teherbírását.');
        }

        return $checks;
    }

    private function assertChecks(array $checks): void
    {
        foreach ([['active'], ['category'], ['weight_input'], ['pallet_kind'], ['location_weight', 'pallet_space'], ['level_weight'], ['system_weight']] as $group) {
            $errors = [];
            foreach ($group as $key) {
                if (isset($checks[$key]) && $checks[$key]['passed'] !== true) {
                    $errors[$checks[$key]['field']] = $checks[$key]['message'];
                }
            }
            if ($errors) throw ValidationException::withMessages($errors);
        }
    }

    public function assertExistingCategories(WarehouseLocation $bin, array $categories): void
    {
        if (!$categories) return;
        if (Category::whereKey($categories)->sharedLock()->get(['category_id'])->count() !== count($categories)) {
            throw ValidationException::withMessages(['categories' => 'Az egyik kategória időközben megszűnt. Töltsd újra az oldalt.']);
        }
        $incompatiblePlacement = $bin->placements()->join('stocks', 'stocks.id', '=', 'warehouse_placements.stock_id')
            ->join('products', 'products.product_id', '=', 'stocks.product_id')
            ->whereNotIn('products.category_id', $categories)->sharedLock()->first(['warehouse_placements.id']);
        $incompatibleReservation = $bin->reservations()->join('products', 'products.product_id', '=', 'inventory_transfers.product_id')
            ->whereNotIn('products.category_id', $categories)->sharedLock()->first(['inventory_transfers.id']);
        if ($incompatiblePlacement || $incompatibleReservation) {
            throw ValidationException::withMessages(['categories' => 'A rekeszben olyan tárolt vagy lefoglalt tétel van, amelynek kategóriája hiányzik a megengedett listából.']);
        }
    }

    public function assertProductCategoryChange(Product $product): void
    {
        $placements = WarehousePlacement::whereIn('stock_id', Stock::where('product_id', $product->getKey())->select('id'));
        $reservations = InventoryTransfer::where('product_id', $product->getKey())->where('status', 'pending')->whereNotNull('to_location_id');
        $locations = WarehouseLocation::whereNotNull('system_id')->where(fn ($query) => $query
            ->whereIn('id', (clone $placements)->select('warehouse_location_id'))
            ->orWhereIn('id', (clone $reservations)->select('to_location_id')))->get();
        $this->lockWarehouses($locations->pluck('warehouse_id')->all());
        foreach ($locations as $location) {
            $this->assertCategoryAllowed($location, (int) $product->category_id, 'category_id');
        }
    }

    private function assertCategoryAllowed(WarehouseLocation $location, int $categoryId, string $field): void
    {
        if (!$this->categoryAllowed($this->lockedCategoryIds($location), $categoryId)) {
            throw ValidationException::withMessages([$field => $this->categoryMessage($location)]);
        }
    }

    private function lockedCategoryIds(WarehouseLocation $location): array
    {
        return DB::table('warehouse_location_categories')->where('location_id', $location->id)
            ->sharedLock()->pluck('category_id')->map(fn ($id) => (int) $id)->all();
    }

    private function categoryAllowed(array $allowed, int $categoryId): bool
    {
        return $allowed === [] || in_array($categoryId, $allowed, true);
    }

    private function categoryMessage(WarehouseLocation $location): string
    {
        return 'A(z) '.$location->code.' tárhelyen ez a termékkategória nem engedélyezett.';
    }

    public function committedWeights(array $locationIds): array
    {
        $weights = [];
        foreach (WarehousePlacement::whereIn('warehouse_location_id', $locationIds)->lockForUpdate()->get(['warehouse_location_id', 'cargo_weight_grams']) as $placement) {
            $id = $placement->warehouse_location_id;
            $weights[$id] = ($weights[$id] ?? 0) + (int) $placement->cargo_weight_grams;
        }
        foreach (InventoryTransfer::whereIn('to_location_id', $locationIds)->where('status', 'pending')->lockForUpdate()->get(['to_location_id', 'cargo_weight_grams']) as $transfer) {
            $id = $transfer->to_location_id;
            $weights[$id] = ($weights[$id] ?? 0) + (int) $transfer->cargo_weight_grams;
        }

        return $weights;
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
