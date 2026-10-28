<?php

namespace App\Queries;

use App\Models\{InventoryMovement, Product, Warehouse, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class MovementListing
{
    public const SORTS = ['created_at' => 'Dátum', 'type' => 'Irány', 'product_name' => 'Termék',
        'warehouse_name' => 'Raktár', 'quantity' => 'Mennyiség', 'user_name' => 'Rögzítette'];

    public array $filters;

    public function __construct(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(['in', 'out', 'all'])],
            'product_id' => ['nullable', 'integer', 'exists:products,product_id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,warehouse_id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('date_from') ? ['after_or_equal:date_from'] : []),
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->filters = array_replace(array_fill_keys(['search', 'type', 'product_id', 'warehouse_id', 'user_id', 'date_from', 'date_to'], null), $data);
        $this->filters['search'] = trim($data['search'] ?? '');
        $this->filters['type'] = ($data['type'] ?? null) === 'all' ? null : ($data['type'] ?? null);
        $this->filters['sort_by'] = $data['sort_by'] ?? 'created_at';
        $this->filters['sort_dir'] = $data['sort_dir'] ?? 'desc';
        $this->filters['per_page'] = (int) ($data['per_page'] ?? 10);
        unset($this->filters['page']);
    }

    public function query(): Builder
    {
        $f = $this->filters;
        $query = InventoryMovement::with(['product', 'warehouse', 'user']);
        foreach (['type', 'product_id', 'warehouse_id', 'user_id'] as $key) {
            if ($f[$key] !== null && $f[$key] !== '') $query->where('inventory_movements.'.$key, $f[$key]);
        }
        foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $token).'%';
            $query->where(function (Builder $q) use ($pattern) {
                $q->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('product', fn (Builder $p) => $p->where(function (Builder $p) use ($pattern) {
                        $p->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]);
                    }));
            });
        }
        if ($f['date_from']) $query->where('inventory_movements.created_at', '>=', Carbon::createFromFormat('!Y-m-d', $f['date_from']));
        if ($f['date_to']) $query->where('inventory_movements.created_at', '<', Carbon::createFromFormat('!Y-m-d', $f['date_to'])->addDay());
        $related = match ($f['sort_by']) {
            'product_name' => Product::select('product_name')->whereColumn('products.product_id', 'inventory_movements.product_id'),
            'warehouse_name' => Warehouse::select('warehouse_name')->whereColumn('warehouses.warehouse_id', 'inventory_movements.warehouse_id'),
            'user_name' => User::select('name')->whereColumn('users.id', 'inventory_movements.user_id'),
            default => null,
        };
        $query->orderBy($related ?? 'inventory_movements.'.$f['sort_by'], $f['sort_dir']);
        return $query->orderBy('inventory_movements.id', $f['sort_dir']);
    }

    public function labels(): array
    {
        $f = $this->filters;
        $labels = [];
        if ($f['search'] !== '') $labels['search'] = 'Keresés: '.$f['search'];
        if ($f['type']) $labels['type'] = $f['type'] === 'in' ? 'Bevételezés' : 'Kiadás';
        if ($f['product_id']) $labels['product_id'] = 'Termék: '.Product::findOrFail($f['product_id'])->product_name;
        if ($f['warehouse_id']) $labels['warehouse_id'] = 'Raktár: '.Warehouse::findOrFail($f['warehouse_id'])->warehouse_name;
        if ($f['user_id']) $labels['user_id'] = 'Rögzítette: '.User::findOrFail($f['user_id'])->name;
        if ($f['date_from']) $labels['date_from'] = 'Ettől: '.$f['date_from'];
        if ($f['date_to']) $labels['date_to'] = 'Eddig: '.$f['date_to'];
        return $labels;
    }
}
