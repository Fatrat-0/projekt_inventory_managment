<?php

namespace App\Queries;

use App\Models\{Product, Stock, Warehouse};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseStockListing
{
    public const SORTS = ['product_name' => 'Terméknév', 'sku' => 'Cikkszám', 'quantity' => 'Helyi készlet'];
    public array $filters;

    public function __construct(Request $request, private Warehouse $warehouse)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:200',
            'product_id' => ['nullable', 'integer', Rule::exists('stocks', 'product_id')->where('warehouse_id', $warehouse->warehouse_id)],
            'stock' => 'nullable|in:positive,zero,all',
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'sort_dir' => 'nullable|in:asc,desc',
            'per_page' => ['nullable', 'integer', Rule::in([15,25,50,100])],
            'page' => 'nullable|integer|min:1',
        ]);
        $this->filters = [
            'search' => trim($data['search'] ?? ''), 'product_id' => $data['product_id'] ?? '',
            'stock' => $data['stock'] ?? 'positive', 'sort_by' => $data['sort_by'] ?? 'product_name',
            'sort_dir' => $data['sort_dir'] ?? 'asc', 'per_page' => (int)($data['per_page'] ?? 15),
        ];
    }

    public function query()
    {
        $f = $this->filters;
        $query = Stock::with('product')->where('warehouse_id', $this->warehouse->warehouse_id);
        if ($f['product_id'] !== '') $query->where('product_id', $f['product_id']);
        if ($f['stock'] === 'positive') $query->where('quantity', '>', 0);
        if ($f['stock'] === 'zero') $query->where('quantity', 0);
        foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->whereHas('product', fn($q) => $q->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]));
        }
        $sort = $f['sort_by'] === 'quantity' ? 'stocks.quantity' : Product::select($f['sort_by'])->whereColumn('products.product_id', 'stocks.product_id');
        return $query->orderBy($sort, $f['sort_dir'])->orderBy('stocks.id');
    }

    public function filterInfo(bool $includeStock = true, bool $includeSku = true): array
    {
        $f = $this->filters;
        $info = [];
        if ($f['search'] !== '') $info[] = 'Keresés: '.$f['search'];
        if ($f['product_id'] !== '') {
            $product = Product::findOrFail($f['product_id']);
            $info[] = 'Termék: '.$product->product_name.($includeSku ? ' ('.$product->sku.')' : '');
        }
        if ($includeStock) $info[] = 'Készletsorok: '.['positive' => 'pozitív készlet', 'zero' => 'nulla készlet', 'all' => 'összes helyi készletsor'][$f['stock']];
        $info[] = 'Rendezés: '.self::SORTS[$f['sort_by']].' ('.($f['sort_dir'] === 'asc' ? 'növekvő' : 'csökkenő').')';
        return $info;
    }
}
