<?php

namespace App\Queries;

use App\Services\WarehouseStockAvailability;
use App\Models\{Category, Product, Stock, Warehouse};
use App\Support\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** One result represents one product in one warehouse, including zero-quantity rows. */
class StockListing
{
    public const SORTS = ['product_name' => 'Termék', 'sku' => 'Cikkszám', 'warehouse_name' => 'Raktár',
        'quantity' => 'Fizikai készlet', 'available' => 'Közvetlenül kiadható', 'quarantined_quantity' => 'Elkülönített', 'min_quantity' => 'Helyi minimum'];
    public const STATUSES = ['all' => 'Minden készletsor', 'low' => 'Helyi minimum alatt', 'out' => 'Nincs közvetlenül kiadható készlet',
        'positive' => 'Van közvetlenül kiadható készlet', 'quarantined' => 'Elkülönített készlettel', 'zero' => 'Nulla fizikai készlet',
        'ok' => 'Minimumot elérő, kitárolható', 'unknown' => 'Nem számítható elérhetőség'];
    public array $filters;

    public function __construct(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:200', 'product_id' => 'nullable|integer|exists:products,product_id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id', 'category_id' => 'nullable|integer|exists:categories,category_id',
            'stock_status' => ['nullable', Rule::in(array_keys(self::STATUSES))], 'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'sort_dir' => 'nullable|in:asc,desc', 'per_page' => ['nullable', 'integer', Rule::in([10,25,50,100])],
            'page' => 'nullable|integer|min:1|max:2147483647',
            'edit_minimum' => 'nullable|integer|min:1|exists:stocks,id',
        ]);
        $this->filters = ['search' => trim($data['search'] ?? ''), 'product_id' => $data['product_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null, 'category_id' => $data['category_id'] ?? null,
            'stock_status' => $data['stock_status'] ?? 'all', 'sort_by' => $data['sort_by'] ?? 'product_name',
            'sort_dir' => $data['sort_dir'] ?? 'asc', 'per_page' => (int)($data['per_page'] ?? 25)];
    }

    public function query(bool $suggestions = false): Builder
    {
        $f = $this->filters;
        $query = Stock::query()->fromSub(WarehouseStockAvailability::listingRows(), 'stocks')
            ->join('products as stock_products', 'stock_products.product_id', '=', 'stocks.product_id')
            ->join('warehouses as stock_warehouses', 'stock_warehouses.warehouse_id', '=', 'stocks.warehouse_id')
            ->leftJoin('categories as stock_categories', 'stock_categories.category_id', '=', 'stock_products.category_id')
            ->select('stocks.*')->with(['product.category', 'warehouse']);
        if (!$suggestions && $f['product_id'] !== null) $query->where('stocks.product_id', $f['product_id']);
        if ($f['warehouse_id'] !== null) $query->where('stocks.warehouse_id', $f['warehouse_id']);
        if ($f['category_id'] !== null) $query->whereIn('stock_products.category_id', (new CategoryTree(Category::get(['category_id', 'category_name', 'parent_id'])))->branchIds((int)$f['category_id']));
        match ($f['stock_status']) {
            'low' => $query->whereColumn('stocks.pickable_quantity', '<', 'stocks.min_quantity'),
            'out' => $query->where('stocks.direct_issue_quantity', 0),
            'positive' => $query->where('stocks.direct_issue_quantity', '>', 0),
            'quarantined' => $query->where('stocks.quarantined_quantity', '>', 0),
            'zero' => $query->where('stocks.quantity', 0),
            'ok' => $query->whereColumn('stocks.pickable_quantity', '>=', 'stocks.min_quantity')->where('stocks.pickable_quantity', '>', 0),
            'unknown' => $query->whereNull('stocks.direct_issue_quantity'),
            default => null,
        };
        if (!$suggestions) foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(function (Builder $q) use ($pattern) {
                foreach (['stock_products.product_name', 'stock_products.sku', 'stock_categories.category_name', 'stock_warehouses.warehouse_name', 'stock_warehouses.location'] as $column) {
                    $q->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
        $sort = match ($f['sort_by']) {
            'product_name', 'sku' => 'stock_products.'.$f['sort_by'],
            'warehouse_name' => 'stock_warehouses.warehouse_name',
            'available' => 'stocks.direct_issue_quantity',
            default => 'stocks.'.$f['sort_by'],
        };
        if ($f['sort_by'] === 'available') $query->orderByRaw('stocks.direct_issue_quantity IS NULL');

        return $query->orderBy($sort, $f['sort_dir'])->orderBy('stocks.id', $f['sort_dir']);
    }

    public function summary(): object
    {
        return $this->query()->reorder()->toBase()->select([])->selectRaw('COUNT(*) AS rows_count, COUNT(DISTINCT stocks.product_id) AS products_count, COUNT(DISTINCT stocks.warehouse_id) AS warehouses_count')
            ->selectRaw('COALESCE(SUM(stocks.quantity), 0) AS physical, COALESCE(SUM(stocks.quarantined_quantity), 0) AS quarantined')
            ->selectRaw('COALESCE(SUM(stocks.direct_issue_quantity), 0) AS available, COUNT(*) - COUNT(stocks.direct_issue_quantity) AS unknown_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN stocks.pickable_quantity < stocks.min_quantity THEN 1 ELSE 0 END), 0) AS low_count')->first();
    }

    public function suggestions()
    {
        $ids = $this->query(true)->reorder()->select('stocks.product_id');
        return Product::with('category')->whereIn('product_id', $ids)->orderBy('product_name')->orderBy('product_id')
            ->get(['product_id', 'product_name', 'sku', 'category_id'])->map(fn ($product) => ['value' => (string)$product->product_id,
                'name' => $product->product_name, 'detail' => $product->sku.($product->category ? ' · '.$product->category->category_name : '')])->values();
    }

    public function labels(): array
    {
        $f = $this->filters; $labels = [];
        if ($f['search'] !== '') $labels['search'] = 'Keresés: '.$f['search'];
        if ($f['product_id'] !== null) $labels['product_id'] = 'Termék: '.Product::findOrFail($f['product_id'])->product_name;
        if ($f['warehouse_id'] !== null) $labels['warehouse_id'] = 'Raktár: '.Warehouse::findOrFail($f['warehouse_id'])->warehouse_name;
        if ($f['category_id'] !== null) $labels['category_id'] = 'Kategória és alkategóriái: '.Category::findOrFail($f['category_id'])->category_name;
        if ($f['stock_status'] !== 'all') $labels['stock_status'] = self::STATUSES[$f['stock_status']];
        return $labels;
    }
}
