<?php

namespace App\Queries;

use App\Models\{InventoryTransfer, Product, Warehouse};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\{ListingDates, ListingNumber};

class TransferListing
{
    public const SORTS = ['created_at' => 'Dátum', 'product_name' => 'Termék', 'quantity' => 'Mennyiség', 'status' => 'Állapot'];
    public array $filters;
    public function __construct(Request $request)
    {
        $data = $request->validate(ListingDates::rules($request) + [
            'search' => 'nullable|string|max:200', 'status' => ['nullable', Rule::in(array_keys(InventoryTransfer::STATUSES))],
            'product_id' => 'nullable|integer|exists:products,product_id',
            'from_warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id', 'to_warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id',
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))], 'sort_dir' => 'nullable|in:asc,desc',
            'per_page' => ['nullable', 'integer', Rule::in([10,25,50,100])], 'page' => 'nullable|integer|min:1',
        ]);
        $this->filters = array_replace(array_fill_keys(['search', 'status', 'product_id', 'from_warehouse_id', 'to_warehouse_id', 'date_from', 'date_to'], null), $data);
        $this->filters['search'] = trim($data['search'] ?? '');
        $this->filters['sort_by'] = $data['sort_by'] ?? 'created_at';
        $this->filters['sort_dir'] = $data['sort_dir'] ?? 'desc';
        $this->filters['per_page'] = (int)($data['per_page'] ?? 10);
        unset($this->filters['page']);
    }
    public function query(bool $ignoreStatus = false)
    {
        $f = $this->filters;
        $query = InventoryTransfer::with(['product', 'fromWarehouse', 'toWarehouse', 'user', 'shipmentItem.stop.shipment']);
        foreach (['status', 'product_id', 'from_warehouse_id', 'to_warehouse_id'] as $key) {
            if ($key === 'status' && $ignoreStatus) continue;
            if ($f[$key]) $query->where($key, $f[$key]);
        }
        ListingDates::apply($query, $f, 'inventory_transfers.created_at');
        foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $id = ListingNumber::id($word, 'ATM');
            $shipmentId = ListingNumber::id($word, 'SZALL');
            $query->where(function ($q) use ($pattern, $id, $shipmentId) {
                $q->whereHas('product', fn($p) => $p->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('fromWarehouse', fn($w) => $w->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('toWarehouse', fn($w) => $w->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('shipmentItem.stop.shipment', fn($s) => $s->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern]));
                if ($id !== null) $q->orWhere('inventory_transfers.id', $id);
                if ($shipmentId !== null) $q->orWhereHas('shipmentItem.stop', fn($s) => $s->where('shipment_id', $shipmentId));
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
        if ($f['date_from']) $labels['date_from'] = 'Ettől: '.$f['date_from'];
        if ($f['date_to']) $labels['date_to'] = 'Eddig: '.$f['date_to'];
        if ($f['product_id']) $labels['product_id'] = 'Termék: '.Product::findOrFail($f['product_id'])->product_name;
        foreach (['from_warehouse_id' => 'Forrás', 'to_warehouse_id' => 'Cél'] as $key => $label) if ($f[$key]) $labels[$key] = $label.': '.Warehouse::findOrFail($f[$key])->warehouse_name;
        return $labels;
    }
}
