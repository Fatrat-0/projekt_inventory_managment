<?php

namespace App\Services;

use App\Models\{InventoryTransfer, WarehouseLocation, WarehousePlacement};
use App\Support\WarehouseLoadDiscrepancies;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WarehouseRelocationSources
{
    public function products(EloquentBuilder $warehouses, ?int $warehouseId, string $search, int $perPage, ?int $locationId = null): LengthAwarePaginator
    {
        $eligible = $this->eligible($warehouses);
        if ($locationId !== null) {
            $eligible->whereExists(fn ($placements) => $placements->from('warehouse_placements as source')
                ->whereColumn('source.stock_id', 'stock.id')->where('source.warehouse_location_id', $locationId)
                ->where('source.quantity', '>', 0));
        }
        $query = DB::table('products')->select('products.product_id', 'products.product_name', 'products.sku');
        if ($warehouseId === null) {
            $query->whereIn('products.product_id', $eligible->select('stock.product_id'));
        } else {
            $query->joinSub($eligible->where('stock.warehouse_id', $warehouseId)
                ->select('stock.product_id', 'stock.active_bin_quantity', 'stock.pickable_quantity'), 'available', 'available.product_id', '=', 'products.product_id')
                ->addSelect('available.active_bin_quantity', 'available.pickable_quantity');
        }
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($matches) => $matches->whereRaw("products.product_name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("products.sku LIKE ? ESCAPE '!'", [$pattern]));
        }

        return $query->orderBy('products.product_name')->orderBy('products.product_id')->paginate($perPage)->withQueryString();
    }

    public function eligible(EloquentBuilder $warehouses): Builder
    {
        return DB::query()->fromSub(WarehouseStockAvailability::listingRows(), 'stock')
            ->whereIn('stock.warehouse_id', $warehouses->select('warehouse_id'))
            ->where('stock.pickable_quantity', '>', 0)->where('stock.active_bin_quantity', '>', 0);
    }

    /** Captures the target's capacity inputs before the source placements are changed. */
    public function targetRevision(WarehouseLocation $target, bool $locked = false): string
    {
        $scope = WarehouseLocation::where('warehouse_id', $target->warehouse_id);
        $scope = $target->system_id === null ? $scope->whereKey($target->id) : $scope->where('system_id', $target->system_id);
        $binIds = $scope->orderBy('id')->when($locked, fn ($query) => $query->lockForUpdate())->get(['id'])->modelKeys();
        $placements = WarehousePlacement::whereIn('warehouse_location_id', $binIds)->orderBy('id')
            ->when($locked, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'warehouse_location_id', 'stock_id', 'quantity', 'cargo_weight_grams', 'pallet_slots']);
        $discrepancies = WarehouseLoadDiscrepancies::read($binIds, $locked);
        $reservations = InventoryTransfer::whereIn('to_location_id', $binIds)->where('status', 'pending')->orderBy('id')
            ->when($locked, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'to_location_id', 'cargo_weight_grams', 'pallet_slots']);
        $system = $target->system()->when($locked, fn ($query) => $query->lockForUpdate())->first(['id', 'max_weight_grams']);
        $level = $target->level()->when($locked, fn ($query) => $query->lockForUpdate())->first(['id', 'max_weight_grams']);
        $categories = $target->allowedCategories()->orderBy('categories.category_id')
            ->when($locked, fn ($query) => $query->lockForUpdate())->pluck('categories.category_id');

        return hash('sha256', json_encode([
            $target->only(['id', 'active', 'kind', 'max_weight_grams', 'max_pallet_slots', 'width_cm', 'depth_cm', 'height_cm']),
            $system, $level, $categories, $placements, $reservations, $discrepancies->modelKeys(),
        ], JSON_THROW_ON_ERROR));
    }

    public function placements(array $stock, array $locationIds, bool $locked = false): Collection
    {
        if (!$stock['stock_id'] || $locationIds === []) return collect();

        return WarehousePlacement::where('stock_id', $stock['stock_id'])->whereIn('warehouse_location_id', $locationIds)
            ->orderBy('id')->when($locked, fn ($query) => $query->lockForUpdate())->get();
    }

    public function describe(WarehouseLocation $location, array $stock, Collection $placements): array
    {
        $stored = collect($stock['locations'])->firstWhere('location_id', $location->id)['stored_quantity'] ?? 0;
        if ($stored !== (int) $placements->sum('quantity')) {
            throw new ConflictHttpException('A forrásrekesz készlete közben megváltozott. Frissítsd a forrás adatait.');
        }
        $rows = $placements->map(fn (WarehousePlacement $row) => [
            'placement_id' => $row->id, 'quantity' => $row->quantity, 'cargo_weight_grams' => $row->cargo_weight_grams,
            'pallet_slots' => $row->pallet_slots, 'load_requires_verification' => $row->load_requires_verification,
        ])->values()->all();
        $movable = $location->active && $stock['consistent'] ? max(0, min($stored, $stock['pickable_quantity'])) : 0;
        $revision = hash('sha256', json_encode([$location->id, $stock['stock_id'], $location->active, $movable, $rows], JSON_THROW_ON_ERROR));

        return [
            'location_id' => $location->id, 'code' => $location->code, 'name' => $location->name, 'kind' => $location->kind,
            'active' => $location->active, 'position' => $location->pickingPosition(), 'stored_quantity' => $stored,
            'movable_quantity' => $movable, 'source_revision' => $revision,
            'recorded_weight_grams' => (int) $placements->sum('cargo_weight_grams'),
            'recorded_pallet_slots' => (int) $placements->sum('pallet_slots'),
            'load_requires_verification' => $placements->contains('load_requires_verification', true),
            'placements' => $rows,
        ];
    }
}
