<?php

namespace App\Queries;

use App\Models\{InventoryTransfer, Product, Warehouse};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TransferListing
{
    public const SORTS = ['created_at' => 'Dátum', 'product_name' => 'Termék', 'quantity' => 'Mennyiség', 'status' => 'Állapot'];
    public array $filters;
    public function __construct(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:200', 'status' => ['nullable', Rule::in(array_keys(InventoryTransfer::STATUSES))],
            'product_id' => 'nullable|integer|exists:products,product_id',
            'from_warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id', 'to_warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id',
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))], 'sort_dir' => 'nullable|in:asc,desc',
            'per_page' => ['nullable', 'integer', Rule::in([10,25,50,100])], 'page' => 'nullable|integer|min:1',
        ]);
        $this->filters = array_replace(array_fill_keys(['search', 'status', 'product_id', 'from_warehouse_id', 'to_warehouse_id'], null), $data);
        $this->filters['search'] = trim($data['search'] ?? '');
        $this->filters['sort_by'] = $data['sort_by'] ?? 'created_at';
        $this->filters['sort_dir'] = $data['sort_dir'] ?? 'desc';
        $this->filters['per_page'] = (int)($data['per_page'] ?? 10);
        unset($this->filters['page']);
    }
    public function query()
    {
        $f = $this->filters;
        $query = InventoryTransfer::with(['product', 'fromWarehouse', 'toWarehouse', 'user', 'shipmentItem.stop.shipment']);
        foreach (['status', 'product_id', 'from_warehouse_id', 'to_warehouse_id'] as $key) if ($f[$key]) $query->where($key, $f[$key]);
        foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(function ($q) use ($pattern) {
                $q->whereHas('product', fn($p) => $p->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('fromWarehouse', fn($w) => $w->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('toWarehouse', fn($w) => $w->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('shipmentItem.stop.shipment', fn($s) => $s->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        $sort = $f['sort_by'] === 'product_name' ? Product::select('product_name')->whereColumn('products.product_id', 'inventory_transfers.product_id') : 'inventory_transfers.'.$f['sort_by'];
        return $query->orderBy($sort, $f['sort_dir'])->orderBy('inventory_transfers.id', $f['sort_dir']);
    }
    public function labels(): array
    {
        $f = $this->filters; $labels = [];
        if ($f['search'] !== '') $labels['search'] = 'Keresés: '.$f['search'];
        if ($f['status']) $labels['status'] = InventoryTransfer::STATUSES[$f['status']];
        if ($f['product_id']) $labels['product_id'] = 'Termék: '.Product::findOrFail($f['product_id'])->product_name;
        foreach (['from_warehouse_id' => 'Forrás', 'to_warehouse_id' => 'Cél'] as $key => $label) if ($f[$key]) $labels[$key] = $label.': '.Warehouse::findOrFail($f[$key])->warehouse_name;
        return $labels;
    }
}
