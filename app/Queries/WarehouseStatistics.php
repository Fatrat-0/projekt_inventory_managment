<?php

namespace App\Queries;

use App\Models\{Warehouse, WarehouseLoadVerification, WarehouseLocation};
use App\Services\WarehouseStockAvailability;
use App\Support\WarehouseStorageOverview;
use Illuminate\Database\Eloquent\{Builder, Collection};
use Illuminate\Support\Facades\DB;

class WarehouseStatistics
{
    public const WAREHOUSE_FILTER = 'nullable|integer|exists:warehouses,warehouse_id';

    public static function reportRows(?int $warehouseId): Collection
    {
        return DB::transaction(function () use ($warehouseId) {
            $rows = self::query()->when($warehouseId !== null, fn ($query) => $query->whereKey($warehouseId))->get();
            $affected = $rows->where('open_load_discrepancy', 1);
            if ($affected->isEmpty()) return $rows;

            $bins = WarehouseLocation::whereIntegerInRaw('warehouse_id', $affected->modelKeys())
                ->withCapacityUsage()->with(['system.zone', 'system.levels'])->get()->groupBy('warehouse_id');
            foreach ($affected as $warehouse) {
                $totals = $bins->get($warehouse->getKey(), collect())->groupBy('system_id')->flatMap(function ($group) {
                    $capacity = $group->map(fn ($bin) => WarehouseStorageOverview::location($bin, collect()));

                    return $group->first()->system ? [WarehouseStorageOverview::system($group->first()->system, $capacity)]
                        : $capacity->where('active', true)->values();
                });
                $warehouse->usable_free_weight = (int) $totals->sum('free');
                $warehouse->usable_free_slots = (int) $totals->sum('freeSlots');
                $warehouse->occupied_weight = (int) $totals->sum('occupied');
                $warehouse->occupied_slots = (int) $totals->sum('occupiedSlots');
            }

            return $rows;
        });
    }

    public static function query(): Builder
    {
        $locations = WarehouseLocation::query()->where('active', true)
            ->select(['warehouse_id', 'system_id', 'max_weight_grams', 'max_pallet_slots'])->withCapacityUsage()->withoutEagerLoads()
            ->withSum('placements as assigned_units', 'quantity');

        $capacity = DB::query()->fromSub($locations, 'active_locations')->select('warehouse_id')
            ->selectRaw('COUNT(*) AS location_count, SUM(CASE WHEN system_id IS NULL THEN max_weight_grams ELSE 0 END) AS legacy_weight, SUM(max_pallet_slots) AS max_slots')
            ->selectRaw('SUM(occupied_weight) AS occupied_weight, SUM(reserved_weight) AS reserved_weight')
            ->selectRaw('SUM(occupied_slots) AS occupied_slots, SUM(reserved_slots) AS reserved_slots, SUM(assigned_units) AS assigned_units')
            ->groupBy('warehouse_id');

        $levelLimits = DB::table('warehouse_locations as bins')->leftJoin('warehouse_storage_levels as levels', 'levels.id', '=', 'bins.level_id')
            ->where('bins.active', true)->whereNotNull('bins.system_id')->select('bins.system_id')
            ->selectRaw('CASE WHEN levels.max_weight_grams < SUM(bins.max_weight_grams) THEN levels.max_weight_grams ELSE SUM(bins.max_weight_grams) END AS weight')
            ->groupBy('bins.system_id', 'bins.level_id', 'levels.max_weight_grams');
        $systemLimits = DB::query()->fromSub($levelLimits, 'level_limits')
            ->join('warehouse_storage_systems as systems', 'systems.id', '=', 'level_limits.system_id')->select('systems.zone_id')
            ->selectRaw('CASE WHEN systems.max_weight_grams < SUM(level_limits.weight) THEN systems.max_weight_grams ELSE SUM(level_limits.weight) END AS weight')
            ->groupBy('systems.id', 'systems.zone_id', 'systems.max_weight_grams');
        $warehouseLimits = DB::query()->fromSub($systemLimits, 'system_limits')
            ->join('warehouse_storage_zones as zones', 'zones.id', '=', 'system_limits.zone_id')->select('zones.warehouse_id')
            ->selectRaw('SUM(system_limits.weight) AS weight')->groupBy('zones.warehouse_id');
        $staged = WarehouseStockAvailability::stagedTasks()
            ->whereColumn('warehouse_transfer_tasks.warehouse_id', 'warehouses.warehouse_id')
            ->selectRaw('COALESCE(SUM(staged_quantity), 0)');

        return Warehouse::query()->leftJoinSub($capacity, 'capacity', 'capacity.warehouse_id', '=', 'warehouses.warehouse_id')
            ->leftJoinSub($warehouseLimits, 'storage_limits', 'storage_limits.warehouse_id', '=', 'warehouses.warehouse_id')
            ->select('warehouses.*')->addSelect(['capacity.location_count', 'capacity.max_slots',
                'capacity.occupied_weight', 'capacity.reserved_weight', 'capacity.occupied_slots', 'capacity.reserved_slots', 'capacity.assigned_units'])
            ->selectRaw('capacity.legacy_weight + COALESCE(storage_limits.weight, 0) AS max_weight')
            ->selectSub($staged, 'staged_units')
            ->selectSub(WarehouseLoadVerification::query()->openDiscrepancies()
                ->whereColumn('warehouse_load_verifications.warehouse_id', 'warehouses.warehouse_id')->selectRaw('1')->limit(1),
                'open_load_discrepancy')
            ->withSum('stocks as physical_units', 'quantity')->withSum('stocks as quarantined_units', 'quarantined_quantity')
            ->withCount([
                'incomingTransfers as incoming_count' => fn ($query) => $query->where('status', 'pending'),
                'outgoingTransfers as outgoing_count' => fn ($query) => $query->where('status', 'pending'),
            ])
            ->orderBy('warehouses.warehouse_name')->orderBy('warehouses.warehouse_id');
    }
}
