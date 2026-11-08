<?php

namespace App\Queries;

use App\Models\{Warehouse, WarehouseLocation};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class WarehouseStatistics
{
    public const WAREHOUSE_FILTER = 'nullable|integer|exists:warehouses,warehouse_id';

    public static function query(): Builder
    {
        $locations = WarehouseLocation::query()->where('active', true)
            ->select(['warehouse_id', 'system_id', 'max_weight_grams', 'max_pallet_slots'])->withCapacityUsage()
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

        return Warehouse::query()->leftJoinSub($capacity, 'capacity', 'capacity.warehouse_id', '=', 'warehouses.warehouse_id')
            ->leftJoinSub($warehouseLimits, 'storage_limits', 'storage_limits.warehouse_id', '=', 'warehouses.warehouse_id')
            ->select('warehouses.*')->addSelect(['capacity.location_count', 'capacity.max_slots',
                'capacity.occupied_weight', 'capacity.reserved_weight', 'capacity.occupied_slots', 'capacity.reserved_slots', 'capacity.assigned_units'])
            ->selectRaw('capacity.legacy_weight + COALESCE(storage_limits.weight, 0) AS max_weight')
            ->withSum('stocks as physical_units', 'quantity')->withSum('stocks as quarantined_units', 'quarantined_quantity')
            ->withCount([
                'incomingTransfers as incoming_count' => fn ($query) => $query->where('status', 'pending'),
                'outgoingTransfers as outgoing_count' => fn ($query) => $query->where('status', 'pending'),
            ])
            ->orderBy('warehouses.warehouse_name')->orderBy('warehouses.warehouse_id');
    }
}
