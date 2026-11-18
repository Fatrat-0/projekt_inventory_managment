<?php

namespace App\Services;

use App\Models\{Shipment, ShipmentIntentAssignment, ShipmentPlanOperation, ShipmentStop, TransferIntent, TransferStockReservation, Warehouse, WarehouseTransferTask};
use App\Support\{ListingNumber, ListingSort};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ShipmentPlanReader
{
    public const SORTS = [
        'id' => 'Fuvarazonosító', 'reference' => 'Hivatkozás', 'origin_warehouse' => 'Induló raktár',
        'planned_departure_at' => 'Tervezett indulás', 'vehicle_label' => 'Jármű', 'status' => 'Állapot',
        'created_at' => 'Létrehozva',
    ];

    public function __construct(private ShipmentPlanReadiness $readiness) {}

    public static function listFilters(array $query): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($query, [
            'origin_warehouse_id' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:200',
            'status' => ['nullable', Rule::in(array_keys(Shipment::STATUSES))],
            ...ListingSort::rules(self::SORTS),
            'page' => 'sometimes|integer|min:1|max:2147483647',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ], [
            'origin_warehouse_id.*' => 'Adj meg érvényes induló raktárat.',
            'search.*' => 'A keresés legfeljebb 200 karakteres szöveg lehet.',
            'status.*' => 'Válassz érvényes fuvarállapotot.',
            ...ListingSort::messages(),
            'page.*' => 'Adj meg érvényes oldalszámot.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti találatszám adható meg.',
        ]);
    }

    public function plans(Builder $query, array $filters): LengthAwarePaginator
    {
        $filters = ListingSort::normalize($filters, self::SORTS, 'created_at');
        if (isset($filters['origin_warehouse_id'])) {
            $query->where('origin_warehouse_id', $filters['origin_warehouse_id']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['search'])) {
            $pattern = $this->pattern($filters['search']);
            $shipmentId = ListingNumber::id($filters['search'], 'SZALL');
            $query->where(function (Builder $match) use ($pattern, $shipmentId) {
                $match->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("vehicle_label LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('origin', fn (Builder $warehouse) => $warehouse->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern]));
                if ($shipmentId !== null) $match->orWhere('shipments.id', $shipmentId);
            });
        }

        foreach (ListingSort::levels($filters, self::SORTS, 'created_at') as $level) {
            $sort = $level['field'];
            $direction = $level['direction'];
            if (in_array($sort, ['planned_departure_at', 'created_at'], true)) {
                $query->orderByRaw('shipments.'.$sort.' IS NULL');
            }
            if ($sort === 'vehicle_label') $query->orderByRaw("vehicle_label IS NULL OR vehicle_label = ''");
            $column = $sort === 'origin_warehouse'
                ? Warehouse::select('warehouse_name')->whereColumn('warehouses.warehouse_id', 'shipments.origin_warehouse_id')
                : 'shipments.'.$sort;
            // Workflow order: draft < in_transit < completed < cancelled.
            if ($sort === 'status') {
                $query->orderByRaw("CASE status WHEN 'draft' THEN 0 WHEN 'in_transit' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END ".$direction);
            } else {
                $query->orderBy($column, $direction);
            }
        }
        $query->orderBy('shipments.id', $filters['sort_dir']);
        $plans = $query->with('origin:warehouse_id,warehouse_name')->withCount([
            'intentAssignments as assignment_count' => fn (Builder $assignments) => $assignments->active(),
            'stops as stop_count' => fn (Builder $stops) => $stops->whereHas('intentAssignments', fn (Builder $assignments) => $assignments->active()),
        ])->paginate($filters['per_page'] ?? 25, page: $filters['page'] ?? 1)->appends($filters);
        $summaries = $this->readiness->summaries($plans->getCollection());

        return $plans->through(fn (Shipment $shipment) => $this->shipment($shipment) + [
            'assignment_count' => $shipment->assignment_count,
            'stop_count' => $shipment->stop_count,
        ] + $summaries[$shipment->id]);
    }

    public function eligible(int $origin, array $filters): LengthAwarePaginator
    {
        $query = TransferIntent::query()->where('status', 'approved')->where('from_warehouse_id', $origin)
            ->whereDoesntHave('shipmentAssignments', fn (Builder $assignments) => $assignments->active());
        if (isset($filters['destination_warehouse_id'])) {
            $query->where('to_warehouse_id', $filters['destination_warehouse_id']);
        }
        if (isset($filters['shipment_id'])) {
            $query->whereDoesntHave('shipmentAssignments', fn (Builder $assignments) => $assignments->where('shipment_id', $filters['shipment_id']));
        }
        if (isset($filters['search'])) {
            $pattern = $this->pattern($filters['search']);
            $query->where(fn (Builder $match) => $match->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('product', fn (Builder $product) => $product->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern])));
        }

        $page = $query->with(['product:product_id,product_name,sku', 'fromWarehouse:warehouse_id,warehouse_name', 'toWarehouse:warehouse_id,warehouse_name'])
            ->latest('id')->paginate($filters['per_page'] ?? 25, page: $filters['page'] ?? 1)->appends($filters);
        $ids = $page->getCollection()->modelKeys();
        $tasks = WarehouseTransferTask::whereIntegerInRaw('active_intent_id', $ids)->with('reservation:id,status')
            ->get(['id', 'transfer_intent_id', 'transfer_stock_reservation_id', 'status', 'staged_quantity'])->keyBy('transfer_intent_id');
        $holds = TransferStockReservation::whereIntegerInRaw('active_intent_id', $ids)->get(['transfer_intent_id', 'status'])->keyBy('transfer_intent_id');

        return $page->through(function (TransferIntent $intent) use ($tasks, $holds) {
            $task = $tasks->get($intent->id);

            return $this->intent($intent) + [
                'from_warehouse_name' => $intent->fromWarehouse->warehouse_name,
                'reservation_status' => ($task?->reservation ?? $holds->get($intent->id))?->status ?? 'unreserved',
                'picking_status' => $task?->status,
                'staged_quantity' => $task?->staged_quantity ?? 0,
            ];
        });
    }

    public function detail(Shipment $shipment): array
    {
        $shipment->load('origin:warehouse_id,warehouse_name');
        $history = $shipment->intentAssignments()->with(['intent.product:product_id,product_name,sku',
            'intent.toWarehouse:warehouse_id,warehouse_name'])->orderBy('id')->get();
        $active = $history->whereNull('detached_at')->values();
        $operations = $shipment->planOperations()->latest('id')->limit(51)->get(['id', 'action', 'actor_id', 'reason', 'occurred_at']);

        return [
            'shipment' => $this->shipment($shipment),
            'assignments' => $active->map(fn (ShipmentIntentAssignment $assignment) => $this->assignment($assignment))->all(),
            'stops' => $this->stops($shipment, $active),
            'detached_assignments' => $history->whereNotNull('detached_at')->values()
                ->map(fn (ShipmentIntentAssignment $assignment) => $this->assignment($assignment))->all(),
            'operations' => $operations->take(50)->map(fn (ShipmentPlanOperation $operation) => [
                'id' => $operation->id,
                'action' => $operation->action,
                'actor_id' => $operation->actor_id,
                'reason' => $operation->reason,
                'occurred_at' => $operation->occurred_at->toISOString(),
            ])->all(),
            'operations_truncated' => $operations->count() > 50,
        ];
    }

    public function stops(Shipment $shipment, Collection $assignments, bool $loadingOrder = false): array
    {
        $groups = $assignments->groupBy('shipment_stop_id');
        $stops = $shipment->stops()->whereKey($groups->keys())->with('warehouse:warehouse_id,warehouse_name')->get();
        if ($loadingOrder) {
            $stops = Shipment::loadingStops($stops);
        }

        return $stops->map(fn (ShipmentStop $stop, int $index) => [
            'id' => $stop->id,
            'warehouse_id' => (int) $stop->warehouse_id,
            'warehouse_name' => $stop->warehouse->warehouse_name,
            'position' => $stop->position,
            'intent_ids' => $groups->get($stop->id)->pluck('transfer_intent_id')->all(),
        ] + ($loadingOrder ? ['loading_position' => $index + 1] : []))->all();
    }

    private function shipment(Shipment $shipment): array
    {
        return [
            'id' => $shipment->id,
            'shipment_number' => $shipment->shipment_number,
            'origin_warehouse_id' => (int) $shipment->origin_warehouse_id,
            'origin_warehouse_name' => $shipment->origin->warehouse_name,
            'reference' => $shipment->reference,
            'vehicle_label' => $shipment->vehicle_label,
            'vehicle_id' => $shipment->vehicle_id,
            'note' => $shipment->note,
            'status' => $shipment->status,
            'version' => $shipment->version,
            'planning_mode' => $shipment->planning_mode,
            'planned_departure_at' => $shipment->planned_departure_at?->toISOString(),
            'created_at' => $shipment->created_at?->toISOString(),
        ];
    }

    private function assignment(ShipmentIntentAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'transfer_intent_id' => $assignment->transfer_intent_id,
            'shipment_stop_id' => $assignment->shipment_stop_id,
            'assigned_by' => $assignment->assigned_by,
            'assigned_at' => $assignment->assigned_at->toISOString(),
            'detached_by' => $assignment->detached_by,
            'detached_at' => $assignment->detached_at?->toISOString(),
            'detach_reason' => $assignment->detach_reason,
            'intent' => $this->intent($assignment->intent),
        ];
    }

    private function intent(TransferIntent $intent): array
    {
        return [
            'id' => $intent->id,
            'reference' => $intent->reference,
            'from_warehouse_id' => (int) $intent->from_warehouse_id,
            'to_warehouse_id' => (int) $intent->to_warehouse_id,
            'to_warehouse_name' => $intent->toWarehouse->warehouse_name,
            'product_id' => (int) $intent->product_id,
            'product_name' => $intent->product->product_name,
            'sku' => $intent->product->sku,
            'quantity' => $intent->quantity,
            'priority' => $intent->priority,
            'shipping_preference' => $intent->shipping_preference,
            'requested_arrival_at' => $intent->requested_arrival_at?->toISOString(),
            'status' => $intent->status,
        ];
    }

    private function pattern(string $search): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }
}
