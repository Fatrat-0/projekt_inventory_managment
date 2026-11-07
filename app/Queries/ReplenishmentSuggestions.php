<?php

namespace App\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Independent proposals, never reservations or a combined allocation plan. */
class ReplenishmentSuggestions
{
    public static function query(): Builder
    {
        $incoming = DB::table('inventory_transfers')->where('status', 'pending')
            ->select('product_id', 'to_warehouse_id')->selectRaw('SUM(quantity) AS incoming_quantity')->groupBy('product_id', 'to_warehouse_id');
        $assigned = DB::table('warehouse_placements')->select('stock_id')->selectRaw('SUM(quantity) AS quantity')->groupBy('stock_id');
        $candidateSurplus = self::donorSurplus('candidate', 'candidate_placements');
        $sourceSurplus = self::donorSurplus('source', 'source_placements');
        $donor = DB::table('stocks as candidate')->select('candidate.id')
            ->leftJoinSub($assigned, 'candidate_placements', 'candidate_placements.stock_id', '=', 'candidate.id')
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
            ->leftJoin('warehouses as origin', 'origin.warehouse_id', '=', 'source.warehouse_id')->select('needs.*')
            ->addSelect('source.warehouse_id as source_warehouse_id', 'origin.warehouse_name as source_warehouse_name')
            ->selectRaw("{$sourceSurplus} AS surplus")
            ->orderByDesc('needs.shortage')->orderBy('needs.id');
    }

    private static function donorSurplus(string $stock, string $placements): string
    {
        // Limit the proposal both to the surplus and to stock already off the storage locations.
        $available = "CAST({$stock}.quantity AS SIGNED) - CAST({$stock}.quarantined_quantity AS SIGNED)";
        $assigned = "CAST(COALESCE({$placements}.quantity, 0) AS SIGNED)";
        $kept = "CASE WHEN {$assigned} > {$stock}.min_quantity THEN {$assigned} ELSE {$stock}.min_quantity END";

        return "({$available} - ({$kept}))";
    }
}
