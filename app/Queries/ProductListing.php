<?php

namespace App\Queries;

use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Shared filtering contract for the product list and inventory export. */
class ProductListing
{
    public const SORTS = [
        'created_at' => 'Létrehozás ideje', 'product_name' => 'Terméknév',
        'sku' => 'Cikkszám', 'category_name' => 'Kategória',
        'total_stock' => 'Összkészlet', 'price' => 'Egységár', 'stock_value' => 'Készletérték',
    ];

    public array $filters;
    public Collection $categories;

    public function __construct(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', 'exists:categories,category_id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,warehouse_id'],
            'stock_status' => ['nullable', Rule::in(['low', 'out', 'ok'])],
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->filters = [
            'search' => trim($data['search'] ?? ''),
            'category_id' => $data['category_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'stock_status' => $data['stock_status'] ?? null,
            'sort_by' => $data['sort_by'] ?? 'created_at',
            'sort_dir' => $data['sort_dir'] ?? 'desc',
            'per_page' => (int) ($data['per_page'] ?? 10),
        ];
        $this->categories = Category::orderBy('category_name')->get();
    }

    public function categoryOptions(): array
    {
        $options = [];
        $visited = [];
        $walk = function ($parentId, int $depth) use (&$walk, &$options, &$visited) {
            foreach ($this->categories->where('parent_id', $parentId) as $category) {
                if (isset($visited[$category->category_id])) {
                    continue;
                }
                $visited[$category->category_id] = true;
                $options[] = ['id' => $category->category_id, 'name' => $category->category_name, 'depth' => $depth];
                $walk($category->category_id, $depth + 1);
            }
        };
        $walk(null, 0);
        return $options;
    }

    private function descendantIds(int $id): array
    {
        $ids = [$id];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($this->categories->where('parent_id', $ids[$i]) as $child) {
                if (!in_array($child->category_id, $ids, true)) {
                    $ids[] = $child->category_id;
                }
            }
        }
        return $ids;
    }

    public function query(): Builder
    {
        $f = $this->filters;
        $query = Product::with(['category.parent', 'stocks.warehouse'])
            ->withSum('stocks as total_stock', 'quantity');
        foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $token) {
            // Escape LIKE metacharacters consistently for MySQL and SQLite.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $token).'%';
            $query->where(function (Builder $q) use ($pattern) {
                $q->whereRaw("products.product_name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("products.sku LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('category', fn (Builder $c) => $c->whereRaw("category_name LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        if ($f['category_id']) {
            $query->whereIn('products.category_id', $this->descendantIds((int) $f['category_id']));
        }
        // Warehouse filters product presence; quantities and thresholds remain global.
        if ($f['warehouse_id']) {
            $query->whereHas('stocks', fn (Builder $q) => $q
                ->where('warehouse_id', $f['warehouse_id'])->where('quantity', '>', 0));
        }
        $sum = '(SELECT COALESCE(SUM(stocks.quantity), 0) FROM stocks WHERE stocks.product_id = products.product_id)';
        $min = 'COALESCE(products.min_stock_override, (SELECT categories.default_item_min_stock FROM categories WHERE categories.category_id = products.category_id), 0)';
        match ($f['stock_status']) {
            'low' => $query->whereRaw("{$sum} < {$min}"),
            'out' => $query->whereRaw("{$sum} = 0"),
            'ok' => $query->whereRaw("{$sum} >= {$min} AND {$sum} > 0"),
            default => null,
        };
        if ($f['sort_by'] === 'category_name') {
            $query->orderBy(Category::select('category_name')->whereColumn('categories.category_id', 'products.category_id'), $f['sort_dir']);
        } elseif (in_array($f['sort_by'], ['total_stock', 'stock_value'], true)) {
            $expression = $f['sort_by'] === 'stock_value' ? "({$sum} * products.price)" : $sum;
            $query->orderByRaw($expression.' '.$f['sort_dir']);
        } else {
            $query->orderBy('products.'.$f['sort_by'], $f['sort_dir']);
        }
        return $query->orderBy('products.product_id', $f['sort_dir']);
    }

    public function filterInfo(): array
    {
        $f = $this->filters;
        $info = [];
        if ($f['search'] !== '') {
            $info[] = 'Keresés: '.$f['search'];
        }
        if ($f['category_id']) {
            $info[] = 'Kategória és alkategóriái: '.$this->categories->firstWhere('category_id', $f['category_id'])->category_name;
        }
        if ($f['warehouse_id']) {
            $info[] = 'Készleten ebben a raktárban: '.Warehouse::findOrFail($f['warehouse_id'])->warehouse_name.' (a mennyiségek minden raktárt tartalmaznak)';
        }
        if ($f['stock_status']) {
            $info[] = 'Összkészlet állapota: '.['low' => 'Minimum alatt', 'out' => 'Kifogyott', 'ok' => 'Megfelelő'][$f['stock_status']];
        }
        return $info;
    }
}
