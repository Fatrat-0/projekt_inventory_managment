<?php

namespace App\Services;

use App\Models\{Stock, WarehouseLocation, WarehousePlacement};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SmartPicking
{
    /**
     * Read-only source snapshot. Pending departures have already been deducted;
     * pending arrivals reserve space but are not yet part of this stock.
     */
    public function options(int $warehouseId, int $productId, ?int $quantity = null): array
    {
        return DB::transaction(function () use ($warehouseId, $productId, $quantity) {
            $stock = Stock::where('warehouse_id', $warehouseId)->where('product_id', $productId)
                ->with('placements.location')->first();
            $sources = $this->sourceRows($stock, $stock?->placements ?? collect(), $warehouseId);
            $available = array_sum(array_column($sources, 'available_quantity'));
            $suggestion = [];
            if ($quantity !== null && $quantity > 0 && $quantity <= $available) {
                // Use unassigned stock first, then the largest bins to minimize physical picks.
                $remaining = $quantity;
                foreach ($sources as $source) {
                    $picked = min($remaining, $source['available_quantity']);
                    if ($picked > 0) $suggestion[] = ['location_id' => $source['location_id'], 'quantity' => $picked];
                    $remaining -= $picked;
                    if ($remaining === 0) break;
                }
            }

            return [
                'available_quantity' => $available,
                'quarantined_quantity' => $stock?->quarantined_quantity ?? 0,
                'sources' => $sources,
                'suggestion' => $suggestion,
            ];
        });
    }

    /** @return list<array{location_id: ?int, quantity: int}> */
    public function normalizeAllocations(mixed $input, int $quantity): array
    {
        $data = Validator::make(['source_allocations' => $input], [
            'source_allocations' => 'required|array|list',
            'source_allocations.*' => 'required|array:location_id,quantity',
            'source_allocations.*.location_id' => 'present|nullable|integer|min:1',
            'source_allocations.*.quantity' => 'required|integer|min:1|max:2147483647',
        ], [
            'source_allocations.*' => 'Adj meg érvényes forráshelyeket és pozitív egész darabszámokat.',
        ])->validate();
        $allocations = [];
        foreach ($data['source_allocations'] as $row) {
            $locationId = $row['location_id'] === null ? null : (int) $row['location_id'];
            if ($locationId === 0) {
                throw ValidationException::withMessages(['source_allocations' => 'Válassz érvényes forráshelyet.']);
            }
            $key = $locationId ?? 0;
            if (isset($allocations[$key])) {
                throw ValidationException::withMessages(['source_allocations' => 'Egy forráshely csak egyszer szerepelhet.']);
            }
            $allocations[$key] = ['location_id' => $locationId, 'quantity' => (int) $row['quantity']];
        }
        if (array_sum(array_column($allocations, 'quantity')) !== $quantity) {
            throw ValidationException::withMessages(['source_allocations' => 'A kitárolandó darabszám egyezzen meg az átmozgatás mennyiségével.']);
        }

        ksort($allocations, SORT_NUMERIC);

        return array_values($allocations);
    }

    /**
     * Take only the assigned quantities, after the caller locks the product and
     * warehouses. InventoryService books the warehouse-level deduction once.
     */
    public function withdraw(int $warehouseId, int $productId, int $quantity, array $allocations): array
    {
        $stock = Stock::where('warehouse_id', $warehouseId)->where('product_id', $productId)->lockForUpdate()->first();
        if (!$stock || $quantity > $stock->available_quantity) {
            throw ValidationException::withMessages(['quantity' => 'Nincs elegendő kiadható készlet a forrásraktárban.']);
        }
        $placements = $stock->placements()->orderBy('id')->lockForUpdate()->get();
        $locations = WarehouseLocation::where('warehouse_id', $warehouseId)
            ->whereKey($placements->pluck('warehouse_location_id')->unique()->values()->all())
            ->lockForUpdate()->get()->keyBy('id');
        foreach ($placements as $placement) {
            $placement->setRelation('location', $locations->get($placement->warehouse_location_id));
        }
        $sources = collect($this->sourceRows($stock, $placements, $warehouseId))->keyBy(fn (array $source) => $source['location_id'] ?? 0);
        foreach ($allocations as $allocation) {
            $source = $sources->get($allocation['location_id'] ?? 0);
            if (!$source || $allocation['quantity'] > $source['available_quantity']) {
                $name = $source['code'] ?? ($allocation['location_id'] === null ? 'tárhely nélküli készlet' : 'választott tárhely');
                throw ValidationException::withMessages(['source_allocations' => 'A(z) '.$name.' nem rendelkezik a megadott kiadható mennyiséggel. Ellenőrizd a friss készletet.']);
            }
        }

        $placementsByLocation = $placements->groupBy('warehouse_location_id');
        $picked = [];
        foreach ($allocations as $allocation) {
            $locationId = $allocation['location_id'];
            $remaining = $allocation['quantity'];
            if ($locationId === null) {
                $picked[] = ['stock_id' => $stock->id, 'quantity' => $remaining];
                continue;
            }
            foreach ($placementsByLocation[$locationId] as $placement) {
                $taken = min($remaining, $placement->quantity);
                $picked[] = $this->takeFromPlacement($placement, $taken);
                $remaining -= $taken;
                if ($remaining === 0) break;
            }
        }

        return $picked;
    }

    private function sourceRows(?Stock $stock, Collection $placements, int $warehouseId): array
    {
        $assigned = (int) $placements->sum('quantity');
        $unassigned = [
            'location_id' => null, 'code' => null, 'name' => 'Tárhely nélküli készlet',
            'stored_quantity' => max(0, ($stock?->quantity ?? 0) - $assigned),
            'reserved_quantity' => 0, 'available_quantity' => max(0, ($stock?->available_quantity ?? 0) - $assigned),
            'active' => true,
        ];
        $bins = [];
        foreach ($placements->groupBy('warehouse_location_id') as $rows) {
            $location = $rows->first()->location;
            if (!$location || (int) $location->warehouse_id !== $warehouseId) continue;
            $stored = (int) $rows->sum('quantity');
            $bins[] = [
                'location_id' => $location->id, 'code' => $location->code, 'name' => $location->name,
                'stored_quantity' => $stored, 'reserved_quantity' => 0, 'available_quantity' => $location->active ? $stored : 0,
                'active' => $location->active,
            ];
        }
        usort($bins, fn (array $a, array $b) => ($b['available_quantity'] <=> $a['available_quantity'])
            ?: strcmp($a['code'], $b['code']) ?: ($a['location_id'] <=> $b['location_id']));

        return [$unassigned, ...$bins];
    }

    private function takeFromPlacement(WarehousePlacement $placement, int $quantity): array
    {
        $full = $quantity === $placement->quantity;
        // Whole grams are conserved; the remaining stock retains rounding remainders.
        $grams = intdiv($placement->cargo_weight_grams * $quantity, $placement->quantity);
        // Without per-pallet contents, only a full pick proves pallet slots were freed.
        $slots = $full ? $placement->pallet_slots : 0;
        $allocation = [
            'stock_id' => $placement->stock_id, 'warehouse_location_id' => $placement->warehouse_location_id,
            'source_placement_id' => $placement->id, 'location_code' => $placement->location->code,
            'location_name' => $placement->location->name, 'quantity' => $quantity,
            'cargo_weight_grams' => $grams, 'pallet_slots' => $slots,
        ];
        if ($full) {
            $placement->delete();
        } else {
            $placement->update(['quantity' => $placement->quantity - $quantity, 'cargo_weight_grams' => $placement->cargo_weight_grams - $grams]);
        }

        return $allocation;
    }
}
