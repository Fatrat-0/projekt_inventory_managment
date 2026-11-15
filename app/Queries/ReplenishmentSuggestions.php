<?php

namespace App\Queries;

use App\Services\WarehouseStockAvailability;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Independent proposals, never reservations or a combined allocation plan. */
class ReplenishmentSuggestions
{
    public static function query(): Builder
    {
        $incoming = DB::table('inventory_transfers')->where('status', 'pending')
            ->select('product_id', 'to_warehouse_id')->selectRaw('SUM(quantity) AS incoming_quantity')->groupBy('product_id', 'to_warehouse_id');
        $assigned = DB::table('warehouse_placements as placement')
            ->join('warehouse_locations as location', 'location.id', '=', 'placement.warehouse_location_id')
            ->select('placement.stock_id')
            ->selectRaw('SUM(placement.quantity) AS quantity, SUM(CASE WHEN location.active THEN 0 ELSE placement.quantity END) AS inactive_quantity')
            ->groupBy('placement.stock_id');
        $reserved = WarehouseStockAvailability::activeReservations()->select('stock_id')
            ->selectRaw('SUM(remaining_quantity) AS quantity')->groupBy('stock_id');
        $staged = WarehouseStockAvailability::stagedTasks()->select('stock_id')
            ->selectRaw('SUM(staged_quantity) AS quantity')->groupBy('stock_id');
        $candidateSurplus = self::donorSurplus('candidate', 'candidate_placements');
        $sourceSurplus = self::donorSurplus('source', 'source_placements');
        $donor = DB::table('stocks as candidate')->select('candidate.id')
            ->leftJoinSub($assigned, 'candidate_placements', 'candidate_placements.stock_id', '=', 'candidate.id')
            ->leftJoinSub($reserved, 'candidate_reservations', 'candidate_reservations.stock_id', '=', 'candidate.id')
            ->leftJoinSub($staged, 'candidate_staged', 'candidate_staged.stock_id', '=', 'candidate.id')
            ->whereColumn('candidate.product_id', 'target.product_id')->whereColumn('candidate.warehouse_id', '!=', 'target.warehouse_id')
            ->whereRaw("{$candidateSurplus} > 0")
            ->orderByRaw("{$candidateSurplus} DESC")->orderBy('candidate.id')->limit(1);
        // MySQL stock columns are unsigned: a surplus must be allowed to produce a negative deficit.
        $need = 'CAST(target.min_quantity AS SIGNED) - (CAST(target.quantity AS SIGNED) - CAST(target.quarantined_quantity AS SIGNED)) - COALESCE(incoming.incoming_quantity, 0)';
        $targets = DB::table('stocks as target')->join('products', 'products.product_id', '=', 'target.product_id')
            ->join('warehouses as destination', 'destination.warehouse_id', '=', 'target.warehouse_id')
            ->leftJoinSub($incoming, 'incoming', fn ($join) => $join->on('incoming.product_id', '=', 'target.product_id')->on('incoming.to_warehouse_id', '=', 'target.warehouse_id'))
            ->whereRaw("{$need} > 0")
            ->select('target.id', 'target.product_id', 'target.warehouse_id', 'target.min_quantity', 'products.product_name', 'products.sku', 'destination.warehouse_name')
            ->selectRaw("target.quantity - target.quarantined_quantity AS available, COALESCE(incoming.incoming_quantity, 0) AS incoming, {$need} AS shortage")
            ->selectSub($donor, 'source_stock_id');

        return DB::query()->fromSub($targets, 'needs')->leftJoin('stocks as source', 'source.id', '=', 'needs.source_stock_id')
            ->leftJoinSub($assigned, 'source_placements', 'source_placements.stock_id', '=', 'source.id')
            ->leftJoinSub($reserved, 'source_reservations', 'source_reservations.stock_id', '=', 'source.id')
            ->leftJoinSub($staged, 'source_staged', 'source_staged.stock_id', '=', 'source.id')
            ->leftJoin('warehouses as origin', 'origin.warehouse_id', '=', 'source.warehouse_id')->select('needs.*')
            ->addSelect('source.warehouse_id as source_warehouse_id', 'origin.warehouse_name as source_warehouse_name')
            ->selectRaw("{$sourceSurplus} AS surplus")
            ->orderByDesc('needs.shortage')->orderBy('needs.id');
    }

    private static function donorSurplus(string $stock, string $placements): string
    {
        // Limit the proposal both to the surplus and to stock already off the storage locations.
        $staged = "CAST(COALESCE({$stock}_staged.quantity, 0) AS SIGNED)";
        $available = "CAST({$stock}.quantity AS SIGNED) - CAST({$stock}.quarantined_quantity AS SIGNED) - {$staged}";
        $assigned = "CAST(COALESCE({$placements}.quantity, 0) AS SIGNED)";
        $inactive = "CAST(COALESCE({$placements}.inactive_quantity, 0) AS SIGNED)";
        $reserved = "CAST(COALESCE({$stock}_reservations.quantity, 0) AS SIGNED)";
        $floor = "({$inactive} + {$reserved} + {$stock}.min_quantity)";
        $kept = "CASE WHEN {$assigned} > {$floor} THEN {$assigned} ELSE {$floor} END";

        return "({$available} - ({$kept}))";
    }
}
