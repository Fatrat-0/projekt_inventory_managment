<?php

namespace App\Http\Controllers;

use App\Models\{ManualReceiptBatch, WarehouseLocation};
use App\Policies\ShipmentPolicy;
use App\Services\{ManualReceiptPutawayService, ReceiptPutawayCargo};
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ManualReceiptController extends Controller
{
    public function index(Request $request, ShipmentPolicy $policy): JsonResponse
    {
        $filters = $this->filters($request, true);
        $actor = $request->user();
        $query = ManualReceiptBatch::with(['movement.user:id,name', 'product', 'warehouse'])->latest('id');
        if ($actor->role !== 'admin') {
            $warehouses = $policy->receivingWarehouseIds($actor);
            abort_unless((clone $warehouses)->exists(), 403, 'A bevételezési tételekhez célraktári átvételi jogosultság szükséges.');
            $query->whereIn('warehouse_id', $warehouses);
        }
        foreach (['product_id', 'warehouse_id'] as $field) {
            if (isset($filters[$field])) $query->where($field, $filters[$field]);
        }
        $status = $filters['status'] ?? 'open';
        if ($status !== 'all') $query->where('pending_putaway_quantity', $status === 'completed' ? '=' : '>', 0);
        if ($status === 'pending') $query->whereColumn('pending_putaway_quantity', 'received_quantity');
        if ($status === 'partial') $query->whereColumn('pending_putaway_quantity', '<', 'received_quantity');

        return $this->response($query->paginate($filters['per_page'] ?? 25)->appends($filters)
            ->through(fn (ManualReceiptBatch $batch) => $this->summary($batch)));
    }

    public function show(Request $request, ManualReceiptBatch $batch, ManualReceiptPutawayService $service): JsonResponse
    {
        $service->authorize($request->user(), $batch);

        return $this->response($this->detail($batch));
    }

    public function page(Request $request, ManualReceiptBatch $batch, ManualReceiptPutawayService $service): Response
    {
        $service->authorize($request->user(), $batch);
        $detail = $this->detail($batch);
        $detail['item'] = $detail['batch'];
        $history = $this->historyPage($batch, $this->filters($request) + ['per_page' => 20]);
        $manual = true;

        return response()->view('warehouse-putaway.show', compact('manual', 'detail', 'history'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, ManualReceiptBatch $batch, ManualReceiptPutawayService $service): JsonResponse
    {
        $service->authorize($request->user(), $batch);
        return $this->response($this->historyPage($batch, $this->filters($request)));
    }

    public function recommendations(Request $request, ManualReceiptBatch $batch, ManualReceiptPutawayService $service): JsonResponse
    {
        $data = $service->recommendations($request->user(), $batch, $request->all());
        foreach (['recommendations', 'rejected'] as $key) $data[$key] = $this->withLinks($batch, $data[$key]);

        return $this->response($data);
    }

    public function locations(Request $request, ManualReceiptBatch $batch, ManualReceiptPutawayService $service): JsonResponse
    {
        $data = $service->locations($request->user(), $batch, $request->all());
        $data['locations'] = $this->withLinks($batch, $data['locations']);

        return $this->response($data);
    }

    public function preview(Request $request, ManualReceiptBatch $batch, ManualReceiptPutawayService $service): JsonResponse
    {
        $data = $service->preview($request->user(), $batch, $request->all());
        $data['location'] = $this->withLinks($batch, [$data['location']])[0];

        return $this->response($data);
    }

    public function store(Request $request, ManualReceiptBatch $batch, ManualReceiptPutawayService $service): JsonResponse
    {
        $data = $service->putaway($request->user(), $batch, $request->all());
        $links = $this->withLinks($batch, [$data['placement']])[0];

        return $this->response($data + ['location_view_url' => $links['location_view_url']], 201);
    }

    private function detail(ManualReceiptBatch $batch): array
    {
        return DB::transaction(function () use ($batch) {
            $batch = $batch->fresh(['movement.user:id,name', 'warehouse', 'product.defaultPackaging']);

            return ['batch' => $this->summary($batch), 'packaging' => ReceiptPutawayCargo::packagingData($batch->product->defaultPackaging)];
        });
    }

    private function historyPage(ManualReceiptBatch $batch, array $filters): LengthAwarePaginator
    {
        $history = $batch->putaways()->latest('id')->paginate($filters['per_page'] ?? 25)->appends($filters);
        $rows = $history->getCollection()->map(fn ($event) => $event->eventData() + ['location_id' => $event->warehouse_location_id]);

        return $history->setCollection(collect($this->withLinks($batch, $rows->all())));
    }

    private function withLinks(ManualReceiptBatch $batch, array $rows): array
    {
        if ($rows === []) return [];
        $locations = WarehouseLocation::where('warehouse_id', $batch->warehouse_id)
            ->whereKey(array_column($rows, 'location_id'))->with(['system.zone', 'level'])->get()->keyBy('id');

        return array_map(function (array $row) use ($batch, $locations) {
            $location = $locations->get($row['location_id']);
            $object = $location?->system ?? $location;
            $plan = $object?->floor_x !== null && $object?->floor_y !== null
                ? route('warehouse-putaway-pages.manual-plan', [$batch, $location]) : null;

            return $row + [
                'position' => $location?->pickingPosition(),
                'putaway_floor_plan_url' => $plan,
                'location_view_url' => $plan ?? ($location ? route('warehouses.locations.show', [$batch->warehouse_id, $location]) : null),
            ];
        }, $rows);
    }

    private function summary(ManualReceiptBatch $batch): array
    {
        return $batch->state() + [
            'movement_number' => $batch->movement->movement_number,
            'movement_url' => route('movements.show', $batch->movement_id),
            'product_name' => $batch->product->product_name, 'sku' => $batch->product->sku,
            'warehouse_name' => $batch->warehouse->warehouse_name,
            'stock_before' => $batch->movement->stock_before, 'stock_after' => $batch->movement->stock_after,
            'partner_id' => $batch->movement->partner_id, 'partner_name' => $batch->movement->partner_name,
            'reference' => $batch->movement->reference,
            'received_by' => $batch->movement->user_id, 'received_by_name' => $batch->movement->user?->name,
        ];
    }

    private function filters(Request $request, bool $list = false): array
    {
        return $request->validate([
            'page' => 'sometimes|integer|min:1|max:2147483647',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ] + ($list ? [
            'status' => 'sometimes|in:open,pending,partial,completed,all',
            'warehouse_id' => 'sometimes|integer|min:1',
            'product_id' => 'sometimes|integer|min:1',
        ] : []), [
            'page.*' => 'Adj meg érvényes oldalszámot.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti találatszám adható meg.',
            'status.*' => 'Válassz érvényes állapotot.',
            'warehouse_id.*' => 'Válassz érvényes raktárat.',
            'product_id.*' => 'Válassz érvényes terméket.',
        ]);
    }

    private function response(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
