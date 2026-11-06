<?php

namespace App\Queries;

use App\Models\{Category, InventoryMovement, InventoryTransfer, Product, Shipment, Stock, Warehouse};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Read-only, bounded previews; counts and totals always cover the entire database. */
class DashboardOverview
{
    public const LIST_PREVIEW_LIMIT = 3;
    public const LIST_PAGE_SIZE = 25;
    public const PANEL_PAGES = [
        'product_alert_page' => ['data' => 'lowStockProducts', 'anchor' => 'product-alerts-title'],
        'category_alert_page' => ['data' => 'lowStockCategories', 'anchor' => 'category-alerts-title'],
        'transfer_page' => ['data' => 'pendingTransfers', 'anchor' => 'pending-title'],
        'movement_page' => ['data' => 'recentMovements', 'anchor' => 'recent-title'],
        'warehouse_page' => ['data' => 'warehouseStocks', 'anchor' => 'warehouse-stocks-title'],
        'replenishment_page' => ['data' => 'replenishments', 'anchor' => 'replenishment-title'],
    ];

    public static function productAlerts(): Builder
    {
        $stocks = DB::table('stocks')->select('product_id')->selectRaw('SUM(quantity) AS physical, SUM(quarantined_quantity) AS quarantined, SUM(quantity - quarantined_quantity) AS available')->groupBy('product_id');
        $minimum = 'COALESCE(products.min_stock_override, alert_categories.default_item_min_stock)';
        $available = 'COALESCE(product_stock.available, 0)';
        return Product::query()->leftJoinSub($stocks, 'product_stock', 'product_stock.product_id', '=', 'products.product_id')
            ->leftJoin('categories as alert_categories', 'alert_categories.category_id', '=', 'products.category_id')
            ->select('products.*')->selectRaw('COALESCE(product_stock.physical, 0) AS total_stock, COALESCE(product_stock.quarantined, 0) AS total_quarantined')
            ->selectRaw("{$available} AS total_available, {$minimum} AS minimum_quantity, ({$minimum} - {$available}) AS shortage")
            ->whereRaw("{$minimum} IS NOT NULL AND {$available} < {$minimum}");
    }

    public static function categoryAlerts(): Builder
    {
        // Only directly assigned products contribute; child categories have their own minima.
        $stocks = DB::table('products')->leftJoin('stocks', 'stocks.product_id', '=', 'products.product_id')->select('products.category_id')
            ->selectRaw('COALESCE(SUM(stocks.quantity), 0) AS physical, COALESCE(SUM(stocks.quarantined_quantity), 0) AS quarantined, COALESCE(SUM(stocks.quantity - stocks.quarantined_quantity), 0) AS available')
            ->groupBy('products.category_id');
        $available = 'COALESCE(category_stock.available, 0)';
        return Category::query()->leftJoinSub($stocks, 'category_stock', 'category_stock.category_id', '=', 'categories.category_id')
            ->select('categories.*')->selectRaw('COALESCE(category_stock.physical, 0) AS total_stock, COALESCE(category_stock.quarantined, 0) AS total_quarantined')
            ->selectRaw("{$available} AS total_available, (categories.aggregate_min_stock - {$available}) AS shortage")
            ->whereNotNull('categories.aggregate_min_stock')->whereRaw("{$available} < categories.aggregate_min_stock");
    }

    public function data(int $days, array $pages = []): array
    {
        return DB::transaction(function () use ($days, $pages) {
            $asOf = now();
            $start = $asOf->copy()->startOfDay()->subDays($days - 1);
            $productAlerts = self::productAlerts();
            $categoryAlerts = self::categoryAlerts();
            $productCount = (clone $productAlerts)->count();
            $categoryCount = (clone $categoryAlerts)->count();
            $stock = Stock::query()->selectRaw('COALESCE(SUM(quantity), 0) AS physical, COALESCE(SUM(quarantined_quantity), 0) AS quarantined, COALESCE(SUM(quantity - quarantined_quantity), 0) AS available')->first();
            $warehouseTotals = DB::table('stocks')->select('warehouse_id')->selectRaw('SUM(quantity) AS physical, SUM(quarantined_quantity) AS quarantined')->groupBy('warehouse_id');
            $warehouseStocks = Warehouse::query()->leftJoinSub($warehouseTotals, 'totals', 'totals.warehouse_id', '=', 'warehouses.warehouse_id')
                ->select('warehouses.*')->selectRaw('COALESCE(totals.physical, 0) AS physical, COALESCE(totals.quarantined, 0) AS quarantined, COALESCE(totals.physical - totals.quarantined, 0) AS available')
                ->orderByDesc('physical')->orderBy('warehouses.warehouse_id');
            $warehousePeak = max(1, (int)DB::query()->fromSub($warehouseTotals, 'totals')->max('physical'));
            $replenishments = ReplenishmentSuggestions::query();
            $replenishmentCount = (clone $replenishments)->count();
            $transit = InventoryTransfer::where('status', 'pending')->selectRaw('COUNT(*) AS transfers_count, COALESCE(SUM(quantity), 0) AS quantity')->first();
            $pending = InventoryTransfer::with(['product', 'fromWarehouse', 'toWarehouse', 'shipmentItem.stop.shipment'])
                ->where('status', 'pending')->orderBy('created_at')->orderBy('id');
            $recent = InventoryMovement::with(['product', 'warehouse', 'user'])->where('created_at', '<=', $asOf)->orderByDesc('created_at')->orderByDesc('id');
            $movementCount = (clone $recent)->count();
            $shipments = Shipment::query()->selectRaw("COALESCE(SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END), 0) AS in_transit, COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) AS drafts")->first();
            $activity = InventoryMovement::where('created_at', '>=', $start)->where('created_at', '<=', $asOf)
                ->selectRaw("DATE(created_at) AS day, SUM(CASE WHEN type = 'in' THEN 1 ELSE 0 END) AS in_count, SUM(CASE WHEN type = 'out' THEN 1 ELSE 0 END) AS out_count")
                ->selectRaw("SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END) AS in_quantity, SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END) AS out_quantity")
                ->groupByRaw('DATE(created_at)')->orderBy('day')->get()->keyBy('day');
            $dailyActivity = collect();
            for ($date = $start->copy(); $date->lte($asOf); $date->addDay()) {
                $row = $activity->get($date->toDateString());
                $dailyActivity->push(['date' => $date->toDateString(), 'label' => $date->format('m.d.'),
                    'in_count' => (int)($row?->in_count ?? 0), 'out_count' => (int)($row?->out_count ?? 0),
                    'in_quantity' => (int)($row?->in_quantity ?? 0), 'out_quantity' => (int)($row?->out_quantity ?? 0)]);
            }
            return [
                'days' => $days, 'asOf' => $asOf, 'periodStart' => $start,
                'totalProducts' => Product::count(), 'totalWarehouses' => Warehouse::count(),
                'stockSummary' => ['physical' => (int)$stock->physical, 'quarantined' => (int)$stock->quarantined, 'available' => (int)$stock->available],
                'warehouseStocks' => $this->listPage($warehouseStocks, Warehouse::count(), (int)($pages['warehouse_page'] ?? 1), 'warehouse_page'),
                'warehousePeak' => $warehousePeak,
                'replenishmentCount' => $replenishmentCount,
                'replenishments' => $this->listPage($replenishments, $replenishmentCount, (int)($pages['replenishment_page'] ?? 1), 'replenishment_page'),
                'qualitySummary' => ReturnQualityListing::pendingSummary(),
                'lowStockProductCount' => $productCount, 'lowStockCategoryCount' => $categoryCount,
                'lowStockProducts' => $this->listPage($productAlerts->with('category')->orderByDesc('shortage')->orderBy('products.product_id'), $productCount, (int)($pages['product_alert_page'] ?? 1), 'product_alert_page'),
                'lowStockCategories' => $this->listPage($categoryAlerts->orderByDesc('shortage')->orderBy('categories.category_id'), $categoryCount, (int)($pages['category_alert_page'] ?? 1), 'category_alert_page'),
                'transitSummary' => ['count' => (int)$transit->transfers_count, 'quantity' => (int)$transit->quantity],
                'shipmentSummary' => ['in_transit' => (int)$shipments->in_transit, 'drafts' => (int)$shipments->drafts],
                'pendingTransfers' => $this->listPage($pending, (int)$transit->transfers_count, (int)($pages['transfer_page'] ?? 1), 'transfer_page'),
                'recentMovements' => $this->listPage($recent, $movementCount, (int)($pages['movement_page'] ?? 1), 'movement_page'),
                'dailyActivity' => $dailyActivity, 'activityPeak' => max(1, $dailyActivity->max(fn ($row) => $row['in_count'] + $row['out_count'])),
                'activitySummary' => ['count' => $dailyActivity->sum('in_count') + $dailyActivity->sum('out_count'),
                    'in_quantity' => $dailyActivity->sum('in_quantity'), 'out_quantity' => $dailyActivity->sum('out_quantity')],
            ];
        });
    }

    private function listPage(Builder|\Illuminate\Database\Query\Builder $query, int $total, int $requested, string $name): LengthAwarePaginator
    {
        $page = min(max(1, $requested), max(1, (int)ceil($total / self::LIST_PAGE_SIZE)));
        return new LengthAwarePaginator($query->forPage($page, self::LIST_PAGE_SIZE)->get(), $total, self::LIST_PAGE_SIZE, $page,
            ['pageName' => $name, 'path' => route('dashboard')]);
    }
}
