<?php

namespace App\Queries;

use App\Models\{Product, Shipment, Warehouse};
use App\Support\{ListingDates, ListingNumber};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShipmentListing
{
    public const SORTS = ['created_at' => 'Létrehozás ideje', 'reference' => 'Hivatkozás', 'status' => 'Állapot'];
    public array $filters;
    public function __construct(Request $request)
    {
        $data = $request->validate(ListingDates::rules($request) + [
            'search' => 'nullable|string|max:200', 'status' => ['nullable', Rule::in(array_keys(Shipment::STATUSES))],
            'origin_warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id',
            'destination_warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id',
            'product_id' => 'nullable|integer|exists:products,product_id',
            'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))], 'sort_dir' => 'nullable|in:asc,desc',
            'per_page' => ['nullable', 'integer', Rule::in([15,25,50,100])], 'page' => 'nullable|integer|min:1',
        ]);
        $this->filters = array_replace(array_fill_keys(['search','status','origin_warehouse_id','destination_warehouse_id','product_id','date_from','date_to'], null), $data);
        $this->filters['search'] = trim($data['search'] ?? '');
        $this->filters['sort_by'] = $data['sort_by'] ?? 'created_at';
        $this->filters['sort_dir'] = $data['sort_dir'] ?? 'desc';
        $this->filters['per_page'] = (int)($data['per_page'] ?? 15);
        unset($this->filters['page']);
    }
    public function query(bool $ignoreStatus = false)
    {
        $f = $this->filters;
        $query = Shipment::with(['origin', 'stops.warehouse'])->withCount(['stops', 'stops as received_stops_count' => fn($q) => $q->whereNotNull('received_at')]);
        if (!$ignoreStatus && $f['status']) $query->where('shipments.status', $f['status']);
        if ($f['origin_warehouse_id']) $query->where('origin_warehouse_id', $f['origin_warehouse_id']);
        // When both are supplied, the product must be delivered at THAT destination.
        if ($f['destination_warehouse_id'] || $f['product_id']) $query->whereHas('stops', function ($stop) use ($f) {
            if ($f['destination_warehouse_id']) $stop->where('warehouse_id', $f['destination_warehouse_id']);
            if ($f['product_id']) $stop->whereHas('items', fn($item) => $item->where('product_id', $f['product_id']));
        });
        ListingDates::apply($query, $f, 'shipments.created_at');
        foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $id = ListingNumber::id($word, 'SZALL');
            $query->where(function ($q) use ($pattern, $id) {
                $q->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("vehicle_label LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('origin', fn($w) => $w->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('stops.warehouse', fn($w) => $w->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('stops.items.product', fn($p) => $p->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]));
                if ($id !== null) $q->orWhere('shipments.id', $id);
            });
        }
        return $query->orderBy('shipments.'.$f['sort_by'], $f['sort_dir'])->orderBy('shipments.id', $f['sort_dir']);
    }
    public function labels(): array
    {
        $f = $this->filters; $labels = [];
        if ($f['search'] !== '') $labels['search'] = 'Keresés: '.$f['search'];
        if ($f['status']) $labels['status'] = Shipment::STATUSES[$f['status']];
        foreach (['origin_warehouse_id' => 'Indulás', 'destination_warehouse_id' => 'Cél'] as $key => $label) if ($f[$key]) $labels[$key] = $label.': '.Warehouse::findOrFail($f[$key])->warehouse_name;
        if ($f['product_id']) $labels['product_id'] = 'Termék: '.Product::findOrFail($f['product_id'])->product_name;
        if ($f['date_from']) $labels['date_from'] = 'Ettől: '.$f['date_from'];
        if ($f['date_to']) $labels['date_to'] = 'Eddig: '.$f['date_to'];
        return $labels;
    }
}
