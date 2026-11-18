<?php

namespace App\Services;

use App\Models\TransferStockReservation;
use App\Models\WarehouseTransferTask;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class WarehouseStockAvailability
{
    /** A single statement gives one coherent view, including at READ COMMITTED. */
    public function snapshot(int $warehouseId, int $productId): array
    {
        $rows = $this->stockRows($warehouseId, $productId)->selectSub(
            self::activeReservations()->whereColumn('transfer_stock_reservations.stock_id', 'stocks.id')
                ->selectRaw('COALESCE(SUM(remaining_quantity), 0)'),
            'source_reserved_quantity',
        )->selectSub(
            self::stagedTasks()->whereColumn('warehouse_transfer_tasks.stock_id', 'stocks.id')
                ->selectRaw('COALESCE(SUM(staged_quantity), 0)'),
            'staged_quantity',
        )->selectSub(self::pendingPutawayQuantityForStock(), 'pending_putaway_quantity')->get();

        return $this->summarize($rows, (int) ($rows->first()->source_reserved_quantity ?? 0),
            (int) ($rows->first()->staged_quantity ?? 0), (int) ($rows->first()->pending_putaway_quantity ?? 0));
    }

    /**
     * Read the latest rows even under an older InnoDB snapshot. The caller must
     * hold the product and warehouse locks, in that order, until the write commits.
     */
    public function snapshotForUpdate(int $warehouseId, int $productId): array
    {
        $this->requireTransaction();

        $rows = $this->stockRows($warehouseId, $productId)->lockForUpdate()->get();
        $stockId = $rows->first()->stock_id ?? null;
        $reserved = $stockId === null ? 0 : (int) self::activeReservations()->where('stock_id', $stockId)
            ->lockForUpdate()->get(['remaining_quantity'])->sum('remaining_quantity');

        $staged = $stockId === null ? 0 : (int) self::stagedTasks()->where('stock_id', $stockId)
            ->lockForUpdate()->get(['staged_quantity'])->sum('staged_quantity');

        $pendingPutaway = $stockId === null ? 0 : (int) self::pendingPutaways()
            ->where('to_warehouse_id', $warehouseId)->where('product_id', $productId)
            ->lockForUpdate()->get(['pending_putaway_quantity'])->sum('pending_putaway_quantity');

        return $this->summarize($rows, $reserved, $staged, $pendingPutaway);
    }

    private static function pendingPutaways(): Builder
    {
        return DB::table('shipment_receipt_items')->whereRaw('pending_putaway_quantity > 0');
    }

    public static function pendingPutawayQuantityForStock(string $stockTable = 'stocks'): Builder
    {
        return self::pendingPutaways()
            ->whereColumn('shipment_receipt_items.to_warehouse_id', $stockTable.'.warehouse_id')
            ->whereColumn('shipment_receipt_items.product_id', $stockTable.'.product_id')
            ->selectRaw('COALESCE(SUM(pending_putaway_quantity), 0)');
    }

    /** Quantity after quarantine and pending putaway, before source reservations and picking restrictions. */
    public static function usableStockSql(string $stockTable = 'stocks'): string
    {
        $pending = self::pendingPutawayQuantityForStock($stockTable)->toSql();

        return "(CAST({$stockTable}.quantity AS SIGNED) - CAST({$stockTable}.quarantined_quantity AS SIGNED) - ({$pending}))";
    }

    public static function activeReservations(): Builder
    {
        return DB::table('transfer_stock_reservations')->where('status', TransferStockReservation::ACTIVE);
    }

    public static function stagedTasks(): Builder
    {
        return DB::table('warehouse_transfer_tasks')->where('type', WarehouseTransferTask::TYPE_PICKING)
            ->whereIn('status', WarehouseTransferTask::STAGED_STATUSES)->whereNull('dispatched_at');
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A zárolt készletellenőrzéshez aktív tranzakció szükséges.');
        }
    }

    private function stockRows(int $warehouseId, int $productId): Builder
    {
        return DB::table('stocks')
            ->leftJoin('warehouse_placements as placements', 'placements.stock_id', '=', 'stocks.id')
            ->leftJoin('warehouse_locations as locations', fn ($join) => $join
                ->on('locations.id', '=', 'placements.warehouse_location_id')
                ->on('locations.warehouse_id', '=', 'stocks.warehouse_id'))
            ->where('stocks.warehouse_id', $warehouseId)->where('stocks.product_id', $productId)
            ->select('stocks.id as stock_id', 'stocks.quantity as physical_quantity', 'stocks.quarantined_quantity',
                'placements.id as placement_id', 'placements.quantity as placement_quantity',
                'locations.id as location_id', 'locations.code', 'locations.name', 'locations.active')
            ->orderBy('placements.id');
    }

    private function summarize(Collection $rows, int $reserved, int $staged, int $pendingPutaway): array
    {
        $stock = $rows->first();
        $physical = (int) ($stock->physical_quantity ?? 0);
        $quarantined = (int) ($stock->quarantined_quantity ?? 0);
        $assigned = 0;
        $locations = [];
        $issues = [];
        if ($physical < 0) $issues['negative_physical'] = true;
        if ($quarantined < 0 || $quarantined > $physical) $issues['invalid_quarantine'] = true;

        foreach ($rows as $row) {
            if ($row->placement_id === null) continue;

            $quantity = (int) $row->placement_quantity;
            $assigned += $quantity;
            if ($quantity <= 0) $issues['invalid_placement_quantity'] = true;
            if ($row->location_id === null) {
                $issues['invalid_placement_location'] = true;
                continue;
            }

            $id = (int) $row->location_id;
            $locations[$id] ??= [
                'location_id' => $id, 'code' => $row->code, 'name' => $row->name,
                'active' => (bool) $row->active, 'stored_quantity' => 0, 'reserved_quantity' => null,
            ];
            $locations[$id]['stored_quantity'] += $quantity;
        }

        $usable = $physical - $quarantined;
        $unassignedBeforePutaway = $usable - $assigned - $staged;
        $unassignedUsable = $unassignedBeforePutaway - $pendingPutaway;
        if ($assigned > $usable) $issues['assigned_exceeds_usable'] = true;
        if ($staged > 0 && $unassignedBeforePutaway < 0) $issues['staged_exceeds_usable'] = true;
        if ($pendingPutaway > 0 && $unassignedUsable < 0) $issues['pending_putaway_exceeds_usable'] = true;

        $pickable = $unassignedUsable;
        foreach ($locations as $location) {
            if ($location['active']) $pickable += $location['stored_quantity'];
        }
        if ($reserved > 0 && $reserved > $pickable) $issues['reserved_exceeds_pickable'] = true;
        $consistent = $issues === [];
        $free = $pickable - $reserved;
        foreach ($locations as &$location) {
            $available = $location['active'] ? $location['stored_quantity'] : 0;
            $location['available_quantity'] = $consistent ? $available : null;
        }
        unset($location);

        return [
            'stock_id' => $stock ? (int) $stock->stock_id : null,
            'physical_quantity' => $physical,
            'quarantined_quantity' => $quarantined,
            'assigned_quantity' => $assigned,
            'unassigned_quantity' => $physical - $assigned - $staged - $pendingPutaway,
            'staged_quantity' => $staged,
            'pending_putaway_quantity' => $pendingPutaway,
            'source_reserved_quantity' => $reserved,
            'unassigned_usable_quantity' => $consistent ? $unassignedUsable : null,
            'free_quantity' => $consistent ? $free : null,
            'direct_issue_quantity' => $consistent ? min($unassignedUsable, $free) : null,
            'pickable_quantity' => $consistent ? $free : null,
            'consistent' => $consistent,
            'issues' => array_keys($issues),
            'locations' => array_values($locations),
        ];
    }
}
