<?php

namespace App\Queries;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseListing
{
    public const SORTS = ['warehouse_name' => 'Raktár neve', 'location' => 'Cím', 'products_count' => 'Készleten lévő termékfajták', 'total_quantity' => 'Összkészlet'];
    public array $filters;

    public function __construct(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:200',
            'warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id',
            'stock' => 'nullable|in:stocked,empty',
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'sort_dir' => 'nullable|in:asc,desc',
            'per_page' => ['nullable', 'integer', Rule::in([15,25,50,100])],
            'page' => 'nullable|integer|min:1',
        ]);
        $this->filters = [
            'search' => trim($data['search'] ?? ''), 'stock' => $data['stock'] ?? '',
            'warehouse_id' => $data['warehouse_id'] ?? '',
            'sort_by' => $data['sort_by'] ?? 'warehouse_name', 'sort_dir' => $data['sort_dir'] ?? 'asc',
            'per_page' => (int) ($data['per_page'] ?? 15),
        ];
    }

    public function query()
    {
        $query = Warehouse::withUsage()
            ->withCount(['stocks as products_count' => fn($stock) => $stock->where('quantity', '>', 0)])
            ->selectRaw('(SELECT COALESCE(SUM(quantity), 0) FROM stocks WHERE stocks.warehouse_id = warehouses.warehouse_id) as total_quantity');
        if ($this->filters['warehouse_id'] !== '') $query->whereKey($this->filters['warehouse_id']);
        foreach (preg_split('/\s+/u', $this->filters['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(fn($q) => $q->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("location LIKE ? ESCAPE '!'", [$pattern]));
        }
        if ($this->filters['stock'] === 'stocked') $query->whereHas('stocks', fn($q) => $q->where('quantity', '>', 0));
        if ($this->filters['stock'] === 'empty') $query->whereDoesntHave('stocks', fn($q) => $q->where('quantity', '>', 0));
        return $query->orderBy($this->filters['sort_by'], $this->filters['sort_dir'])->orderBy('warehouses.warehouse_id');
    }
}
