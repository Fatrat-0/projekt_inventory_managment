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
            ->select(['warehouse_id', 'max_weight_grams', 'max_pallet_slots'])->withCapacityUsage()
            ->withSum('placements as assigned_units', 'quantity');

        $capacity = DB::query()->fromSub($locations, 'active_locations')->select('warehouse_id')
            ->selectRaw('COUNT(*) AS location_count, SUM(max_weight_grams) AS max_weight, SUM(max_pallet_slots) AS max_slots')
            ->selectRaw('SUM(occupied_weight) AS occupied_weight, SUM(reserved_weight) AS reserved_weight')
            ->selectRaw('SUM(occupied_slots) AS occupied_slots, SUM(reserved_slots) AS reserved_slots, SUM(assigned_units) AS assigned_units')
            ->groupBy('warehouse_id');

        return Warehouse::query()->leftJoinSub($capacity, 'capacity', 'capacity.warehouse_id', '=', 'warehouses.warehouse_id')
            ->select('warehouses.*')->addSelect(['capacity.location_count', 'capacity.max_weight', 'capacity.max_slots',
                'capacity.occupied_weight', 'capacity.reserved_weight', 'capacity.occupied_slots', 'capacity.reserved_slots', 'capacity.assigned_units'])
            ->withSum('stocks as physical_units', 'quantity')->withSum('stocks as quarantined_units', 'quarantined_quantity')
            ->withCount([
                'incomingTransfers as incoming_count' => fn ($query) => $query->where('status', 'pending'),
                'outgoingTransfers as outgoing_count' => fn ($query) => $query->where('status', 'pending'),
            ])
            ->orderBy('warehouses.warehouse_name')->orderBy('warehouses.warehouse_id');
    }
}
