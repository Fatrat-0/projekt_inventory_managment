<?php

namespace App\Services;

use App\Models\{Product, ShipmentDispatch, Shipment, ShipmentReceipt, ShipmentReceiptItem, ShipmentStop, ShipmentTransitItem, User, Warehouse};
use App\Support\{ListingNumber, ListingSort};
use App\Policies\ShipmentPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Validation\Validator as Validation;

class ShipmentReceivingReader
{
    public const RECEIVING_SORTS = [
        'shipment_id' => 'Fuvarazonosító', 'reference' => 'Fuvarhivatkozás',
        'origin_warehouse' => 'Forrásraktár', 'warehouse_name' => 'Célraktár',
        'item_count' => 'Tételszám', 'remaining_quantity' => 'Még átvehető', 'status' => 'Átvételi állapot',
        'dispatched_at' => 'Tényleges feladás',
    ];

    public const PUTAWAY_SORTS = [
        'id' => 'Átvételi tétel', 'product_name' => 'Termék', 'warehouse_name' => 'Célraktár',
        'received_quantity' => 'Átvett', 'placed_quantity' => 'Betárolt', 'pending_putaway_quantity' => 'Betárolásra vár',
        'received_at' => 'Átvétel időpontja', 'status' => 'Állapot',
    ];

    public function __construct(private ShipmentPolicy $policy) {}

    public static function listFilters(array $query, bool $putaway = false): Validation
    {
        $statuses = $putaway ? 'open,pending,partial,completed,all' : 'open,pending,partial,received,all';
        $rules = [
            'warehouse_id' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:200',
            'status' => 'nullable|in:'.$statuses,
            'page' => 'sometimes|integer|min:1|max:2147483647',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ] + ListingSort::rules($putaway ? self::PUTAWAY_SORTS : self::RECEIVING_SORTS);
        if ($putaway) $rules += ['receipt_id' => 'nullable|integer|min:1', 'shipment_id' => 'nullable|integer|min:1'];

        return Validator::make($query, $rules, [
            'warehouse_id.*' => 'Válassz érvényes célraktárat.',
            'search.*' => 'A keresés legfeljebb 200 karakter lehet.',
            'sort_by.*' => 'Válassz érvényes rendezési oszlopot.',
            'sort_dir.*' => 'Válassz érvényes rendezési irányt.',
            'status.*' => 'Válassz érvényes állapotot.',
            'receipt_id.*' => 'Adj meg érvényes átvételi azonosítót.',
            'shipment_id.*' => 'Adj meg érvényes fuvarazonosítót.',
            'page.*' => 'Adj meg érvényes oldalszámot.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti találatszám adható meg.',
        ] + ListingSort::messages());
    }

    public function warehouses(User $actor): Builder
    {
        $warehouses = Warehouse::query();
        if ($actor->role !== 'admin') $warehouses->whereIn('warehouse_id', $this->policy->receivingWarehouseIds($actor));

        return $warehouses->orderBy('warehouse_name')->orderBy('warehouse_id');
    }

    public function openStops(User $actor, array $filters): LengthAwarePaginator
    {
        $filters = ListingSort::normalize($filters, self::RECEIVING_SORTS, 'shipment_id');
        Gate::forUser($actor)->authorize('viewAnyReceiving', Shipment::class);
        $stops = $this->withStopDetails($this->dispatchedStops());
        $this->scopeWarehouses($stops, 'warehouse_id', $actor, $filters);
        $status = $filters['status'] ?? 'open';
        if (in_array($status, ['open', 'pending', 'partial'], true)) {
            $this->withPendingReceipt($stops);
        } else {
            $stops->whereHas('transitItems');
        }
        if ($status === 'received') $stops->whereNotNull('received_at');
        if ($status === 'pending') $stops->whereDoesntHave('receipts');
        if ($status === 'partial') $stops->whereHas('receipts');
        if (isset($filters['search'])) {
            $pattern = $this->pattern($filters['search']);
            $stops->where(fn (Builder $match) => $match
                ->whereHas('shipment', fn (Builder $shipment) => $this->matchShipment($shipment, $filters['search'], $pattern))
                ->orWhereHas('transitItems.product', fn (Builder $product) => $this->matchProduct($product, $pattern)));
        }

        return $this->orderStops($stops, $filters)
            ->paginate($filters['per_page'] ?? 25, page: $filters['page'] ?? 1)->appends($filters)
            ->through(fn (ShipmentStop $stop) => $this->stopSummary($stop));
    }

    /** @return array{receiving: int, putaway: int}|array{} */
    public function openTaskCounts(User $actor): array
    {
        if (Gate::forUser($actor)->denies('viewAnyReceiving', Shipment::class)) return [];
        $stops = $this->dispatchedStops();
        $this->scopeWarehouses($stops, 'warehouse_id', $actor, []);
        $items = ShipmentReceiptItem::query();
        $this->scopeWarehouses($items, 'to_warehouse_id', $actor, []);

        return [
            'receiving' => $this->withPendingReceipt($stops)->count(),
            'putaway' => $this->withPendingPutaway($items)->count(),
        ];
    }

    private function withPendingReceipt(Builder $stops): Builder
    {
        return $stops->whereNull('received_at')->whereHas('transitItems', fn (Builder $rows) => $rows->where('remaining_quantity', '>', 0));
    }

    private function withPendingPutaway(Builder $items): Builder
    {
        return $items->where('pending_putaway_quantity', '>', 0);
    }

    public function shipmentStops(User $actor, Shipment $shipment): array
    {
        $sourceAccess = Gate::forUser($actor)->allows('viewPlan', $shipment);
        if (!$sourceAccess) Gate::forUser($actor)->authorize('viewAnyReceiving', Shipment::class);
        $stops = $this->withStopDetails($this->dispatchedStops())->where('shipment_id', $shipment->id)->whereHas('transitItems');
        if (!$sourceAccess) $this->scopeWarehouses($stops, 'warehouse_id', $actor, []);

        $permitted = $actor->role === 'admin' ? null : $this->policy->receivingWarehouseIds($actor)->pluck('warehouse_id');
        $rows = $stops->orderBy('position')->get()->map(fn (ShipmentStop $stop) => $this->stopSummary($stop) + [
            'can_open_receiving' => $permitted === null || $permitted->contains($stop->warehouse_id),
        ])->all();
        abort_if(!$sourceAccess && $rows === [], 403, 'Csak a saját célraktárhoz tartozó feladott szállítmányt nyithatod meg.');

        return $rows;
    }

    private function dispatchedStops(): Builder
    {
        return ShipmentStop::query()
            ->whereHas('shipment', fn (Builder $query) => $query->where('planning_mode', Shipment::MODE_TRANSFER_INTENT)
                ->whereIn('status', ['in_transit', 'completed'])->whereHas('dispatch'));
    }

    private function withStopDetails(Builder $stops): Builder
    {
        return $stops->with(['warehouse:warehouse_id,warehouse_name', 'shipment.origin:warehouse_id,warehouse_name',
                'shipment.dispatch:id,shipment_id,dispatched_at'])
            ->withCount('transitItems')
            ->withSum('transitItems', 'dispatched_quantity')->withSum('transitItems', 'remaining_quantity')
            ->withSum('receiptItems', 'pending_putaway_quantity');
    }

    private function stopSummary(ShipmentStop $stop): array
    {
        return $this->summary($stop->shipment, $stop, (int) $stop->transit_items_sum_dispatched_quantity,
            (int) $stop->transit_items_sum_remaining_quantity, (int) $stop->receipt_items_sum_pending_putaway_quantity,
            (int) $stop->transit_items_count);
    }

    private function orderStops(Builder $stops, array $filters): Builder
    {
        $levels = ListingSort::levels($filters, self::RECEIVING_SORTS, 'shipment_id');
        foreach ($levels as ['field' => $sort, 'direction' => $direction]) {
            $column = match ($sort) {
                'reference' => Shipment::select('reference')->whereColumn('shipments.id', 'shipment_stops.shipment_id'),
                'origin_warehouse' => Warehouse::select('warehouse_name')->join('shipments', 'shipments.origin_warehouse_id', '=', 'warehouses.warehouse_id')
                    ->whereColumn('shipments.id', 'shipment_stops.shipment_id'),
                'warehouse_name' => Warehouse::select('warehouse_name')->whereColumn('warehouses.warehouse_id', 'shipment_stops.warehouse_id'),
                'item_count' => 'transit_items_count',
                'remaining_quantity' => 'transit_items_sum_remaining_quantity',
                'dispatched_at' => ShipmentDispatch::select('dispatched_at')->whereColumn('shipment_dispatches.shipment_id', 'shipment_stops.shipment_id'),
                // Workflow order: pending, partially received, received.
                'status' => DB::raw("CASE WHEN shipment_stops.received_at IS NOT NULL THEN 2
                    WHEN EXISTS (SELECT 1 FROM shipment_receipts WHERE shipment_receipts.shipment_stop_id = shipment_stops.id) THEN 1 ELSE 0 END"),
                default => 'shipment_stops.shipment_id',
            };
            $stops->orderBy($column, $direction);
        }
        // Preserve route order only after both explicit sorting levels have been applied.
        if ($levels[0]['field'] === 'shipment_id') $stops->orderBy('position');

        return $stops->orderBy('shipment_stops.id', $levels[0]['direction']);
    }

    private function orderPutawayItems(Builder $items, array $filters): Builder
    {
        $levels = ListingSort::levels($filters, self::PUTAWAY_SORTS, 'id');
        foreach ($levels as ['field' => $sort, 'direction' => $direction]) {
            $column = match ($sort) {
                'product_name' => Product::select('product_name')->whereColumn('products.product_id', 'shipment_receipt_items.product_id'),
                'warehouse_name' => Warehouse::select('warehouse_name')->whereColumn('warehouses.warehouse_id', 'shipment_receipt_items.to_warehouse_id'),
                'received_quantity' => 'shipment_receipt_items.received_quantity',
                'placed_quantity' => DB::raw('shipment_receipt_items.received_quantity - shipment_receipt_items.pending_putaway_quantity'),
                'pending_putaway_quantity' => 'shipment_receipt_items.pending_putaway_quantity',
                'received_at' => ShipmentReceipt::select('received_at')->whereColumn('shipment_receipts.id', 'shipment_receipt_items.shipment_receipt_id'),
                // Workflow order: pending, partially put away, completed.
                'status' => DB::raw("CASE WHEN pending_putaway_quantity = 0 THEN 2
                    WHEN pending_putaway_quantity < received_quantity THEN 1 ELSE 0 END"),
                default => 'shipment_receipt_items.id',
            };
            $items->orderBy($column, $direction);
        }

        return $items->orderBy('shipment_receipt_items.id', $levels[0]['direction']);
    }

    private function pattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search)).'%';
    }

    private function matchProduct(Builder $product, string $pattern): void
    {
        $product->where(fn (Builder $fields) => $fields->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]));
    }

    private function matchShipment(Builder $shipment, string $search, string $pattern): void
    {
        $shipment->where(function (Builder $fields) use ($search, $pattern) {
            $fields->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern]);
            $id = ListingNumber::id(trim($search), 'SZALL');
            if ($id !== null) $fields->orWhere('shipments.id', $id);
        });
    }

    public function show(User $actor, Shipment $shipment, ShipmentStop $stop, bool $remainingOnly): array
    {
        [$current, $destination] = $this->context($actor, $shipment, $stop);
        $items = $destination->transitItems()->orderBy('id')
            ->with(['intent:id,reference', 'product:product_id,product_name,sku'])
            ->withSum('receiptItems', 'pending_putaway_quantity')->get();
        $selected = $remainingOnly ? $items->where('remaining_quantity', '>', 0) : $items;

        return $this->summary($current, $destination, $items->sum('dispatched_quantity'), $items->sum('remaining_quantity'),
            $items->sum('receipt_items_sum_pending_putaway_quantity'), $items->count()) + [
            'items' => $selected->map(fn (ShipmentTransitItem $item) => [
                'id' => $item->id,
                'shipment_dispatch_id' => (int) $item->shipment_dispatch_id,
                'transfer_intent_id' => (int) $item->transfer_intent_id,
                'intent_reference' => $item->intent->reference,
                'product_id' => (int) $item->product_id,
                'product_name' => $item->product->product_name,
                'sku' => $item->product->sku,
                'dispatched_quantity' => $item->dispatched_quantity,
                'received_quantity' => $item->received_quantity,
                'remaining_quantity' => $item->remaining_quantity,
                'pending_putaway_quantity' => (int) $item->receipt_items_sum_pending_putaway_quantity,
                'status' => $item->status,
            ])->values()->all(),
        ];
    }

    public function history(User $actor, Shipment $shipment, ShipmentStop $stop, array $filters): LengthAwarePaginator
    {
        [, $destination] = $this->context($actor, $shipment, $stop);
        $page = $destination->receipts()->with(['actor:id,name', 'items.intent:id,reference',
            'items.product:product_id,product_name,sku', 'items.movement:id,shipment_receipt_item_id,stock_before,stock_after',
            'items.transitItem:id,shipment_dispatch_id'])
            ->latest('id')->paginate($filters['per_page'] ?? 25, page: $filters['page'] ?? 1)->appends($filters);

        return $page->through(fn (ShipmentReceipt $receipt) => [
            'id' => $receipt->id,
            'shipment_id' => (int) $receipt->shipment_id,
            'shipment_stop_id' => (int) $receipt->shipment_stop_id,
            'to_warehouse_id' => (int) $receipt->to_warehouse_id,
            'received_at' => $receipt->received_at->toISOString(),
            'actor_id' => (int) $receipt->actor_id,
            'actor_name' => $receipt->actor->name,
            'note' => $receipt->note,
            'items' => $this->historyItems($receipt),
        ]);
    }

    public function pendingPutaway(User $actor, array $filters): LengthAwarePaginator
    {
        $filters = ListingSort::normalize($filters, self::PUTAWAY_SORTS, 'id');
        Gate::forUser($actor)->authorize('viewAnyReceiving', Shipment::class);
        $items = ShipmentReceiptItem::query()->with(['receipt:id,shipment_id,received_at,actor_id', 'receipt.shipment:id,reference',
                'warehouse:warehouse_id,warehouse_name', 'transitItem:id,shipment_dispatch_id', 'intent:id,reference',
                'product:product_id,product_name,sku', 'movement:id,shipment_receipt_item_id,stock_before,stock_after']);
        $this->scopeWarehouses($items, 'to_warehouse_id', $actor, $filters);
        $status = $filters['status'] ?? 'open';
        if ($status === 'completed') $items->where('pending_putaway_quantity', 0);
        if (in_array($status, ['open', 'pending', 'partial'], true)) $this->withPendingPutaway($items);
        if ($status === 'pending') $items->whereColumn('pending_putaway_quantity', 'received_quantity');
        if ($status === 'partial') $items->whereColumn('pending_putaway_quantity', '<', 'received_quantity');
        if (isset($filters['receipt_id'])) $items->where('shipment_receipt_id', $filters['receipt_id']);
        if (isset($filters['search'])) {
            $pattern = $this->pattern($filters['search']);
            $items->where(fn (Builder $match) => $match
                ->whereHas('product', fn (Builder $product) => $this->matchProduct($product, $pattern))
                ->orWhereHas('intent', fn (Builder $intent) => $intent->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('receipt.shipment', fn (Builder $shipment) => $this->matchShipment($shipment, $filters['search'], $pattern)));
        }
        if (isset($filters['shipment_id'])) {
            $items->whereHas('receipt', fn (Builder $query) => $query->where('shipment_id', $filters['shipment_id']));
        }
        $page = $this->orderPutawayItems($items, $filters)->paginate($filters['per_page'] ?? 25, page: $filters['page'] ?? 1)->appends($filters);

        return $page->through(fn (ShipmentReceiptItem $item) => $this->item($item) + [
            'shipment_id' => (int) $item->receipt->shipment_id,
            'shipment_number' => $item->receipt->shipment->shipment_number,
            'shipment_reference' => $item->receipt->shipment->reference,
            'warehouse_name' => $item->warehouse->warehouse_name,
            'actor_id' => (int) $item->receipt->actor_id,
            'received_at' => $item->receipt->received_at->toISOString(),
        ]);
    }

    private function context(User $actor, Shipment $shipment, ShipmentStop $stop): array
    {
        $current = Shipment::where('planning_mode', Shipment::MODE_TRANSFER_INTENT)->findOrFail($shipment->id);
        $destination = $current->stops()->whereKey($stop->id)->firstOrFail();
        Gate::forUser($actor)->authorize('receiveStop', [Shipment::class, $destination]);
        $current->load(['origin:warehouse_id,warehouse_name', 'dispatch:id,shipment_id,dispatched_at']);

        abort_unless($current->dispatch, 409, 'A fuvarhoz még nem tartozik tényleges feladás.');

        $destination->load('warehouse:warehouse_id,warehouse_name');

        return [$current, $destination];
    }

    private function scopeWarehouses(Builder $query, string $column, User $actor, array $filters): void
    {
        if ($actor->role !== 'admin') $query->whereIn($column, $this->policy->receivingWarehouseIds($actor));
        if (isset($filters['warehouse_id'])) $query->where($column, $filters['warehouse_id']);
    }

    private function summary(Shipment $shipment, ShipmentStop $stop, int $dispatched, int $remaining, int $pending, int $itemCount): array
    {
        $received = $dispatched - $remaining;
        $receivingStatus = match (true) {
            $remaining === 0 && $dispatched > 0 => 'received',
            $received > 0 => 'partial',
            default => 'pending',
        };

        return [
            'shipment' => [
                'id' => $shipment->id,
                'shipment_number' => $shipment->shipment_number,
                'reference' => $shipment->reference,
                'status' => $shipment->status,
                'version' => $shipment->version,
                'origin_warehouse_id' => (int) $shipment->origin_warehouse_id,
                'origin_warehouse_name' => $shipment->origin->warehouse_name,
                'planned_departure_at' => $shipment->planned_departure_at?->toISOString(),
                'finished_at' => $shipment->finished_at?->toISOString(),
            ],
            'stop' => [
                'id' => $stop->id,
                'warehouse_id' => (int) $stop->warehouse_id,
                'warehouse_name' => $stop->warehouse->warehouse_name,
                'position' => $stop->position,
                'version' => $stop->receiving_version,
                'receiving_status' => $receivingStatus,
                'received_at' => $stop->received_at?->toISOString(),
                'received_by' => $stop->received_by,
                'item_count' => $itemCount,
                'dispatched_quantity' => $dispatched,
                'received_quantity' => $received,
                'remaining_quantity' => $remaining,
                'pending_putaway_quantity' => $pending,
                'can_receive' => $shipment->status === 'in_transit' && $remaining > 0 && $stop->received_at === null
                    && $stop->receiving_version < ShipmentReceiving::MAX_VERSION && $shipment->version < ShipmentReceiving::MAX_VERSION,
            ],
            'dispatch' => [
                'id' => $shipment->dispatch->id,
                'dispatched_at' => $shipment->dispatch->dispatched_at->toISOString(),
            ],
        ];
    }

    private function historyItems(ShipmentReceipt $receipt): array
    {
        $original = collect($receipt->result['items'] ?? [])->keyBy('id');

        return $receipt->items->map(fn (ShipmentReceiptItem $item) => $this->item($item) + [
            'remaining_quantity_after_receipt' => $original->get($item->id)['remaining_quantity'] ?? null,
        ])->all();
    }

    private function item(ShipmentReceiptItem $item): array
    {
        return ShipmentReceiptPutawayService::itemState($item) + [
            'receipt_id' => (int) $item->shipment_receipt_id,
            'shipment_stop_id' => (int) $item->shipment_stop_id,
            'shipment_dispatch_id' => (int) $item->transitItem->shipment_dispatch_id,
            'transit_item_id' => (int) $item->shipment_transit_item_id,
            'transfer_intent_id' => (int) $item->transfer_intent_id,
            'intent_reference' => $item->intent->reference,
            'to_warehouse_id' => (int) $item->to_warehouse_id,
            'product_id' => (int) $item->product_id,
            'product_name' => $item->product->product_name,
            'sku' => $item->product->sku,
            'movement_id' => $item->movement?->id,
            'stock_before' => $item->movement?->stock_before,
            'stock_after' => $item->movement?->stock_after,
            'note' => $item->note,
        ];
    }
}
