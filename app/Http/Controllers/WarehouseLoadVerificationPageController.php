<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Policies\{WarehouseLoadVerificationPolicy, WarehouseRelocationPolicy};
use App\Services\WarehouseLoadVerificationService;
use App\Support\{WarehouseListContext, WarehouseStockContext};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WarehouseLoadVerificationPageController extends Controller
{
    public function __construct(
        private WarehouseLoadVerificationService $verifications,
        private WarehouseLoadVerificationPolicy $policy,
        private WarehouseRelocationPolicy $warehouseAccess,
    ) {}

    public function index(Request $request, Warehouse $warehouse)
    {
        $actor = $request->user();
        $warehouseId = (int) $warehouse->getKey();
        $this->policy->read($actor, $warehouseId);
        $this->assertReady();
        $filters = $request->validate([
            'warehouse_id' => 'sometimes|required|integer|min:1',
            'state' => 'sometimes|required|in:active,pending,discrepancy_open,history',
            'q' => 'nullable|string|max:100',
            'location_id' => 'sometimes|required|integer|min:1',
            'placement_id' => 'sometimes|required|integer|min:1',
        ]);
        $params = $this->params($request, $warehouse);
        if (isset($filters['warehouse_id']) && (int) $filters['warehouse_id'] !== $warehouseId) {
            $this->policy->read($actor, (int) $filters['warehouse_id']);
            $destination = $this->warehouseAccess->warehouses($actor)->findOrFail($filters['warehouse_id']);

            return redirect()->route('warehouses.load-checks.index', ['warehouse' => $destination]
                + array_intersect_key($filters, array_flip(['state', 'q'])));
        }

        $filters = array_diff_key($filters, ['warehouse_id' => true]) + ['state' => 'active', 'q' => ''];
        $history = $filters['state'] === 'history';
        $filters['q'] = trim($filters['q'] ?? '');
        $items = $history ? $this->verifications->historyPage($actor, $warehouse, $filters)
            : $this->verifications->pendingPage($actor, $warehouse, $filters + ['prioritize_discrepancies' => true]);
        $items->appends(array_filter($filters + array_diff_key($params, ['warehouse' => true]), fn ($value) => $value !== ''));
        $warehouses = $this->warehouseAccess->warehouses($actor)->orderBy('warehouse_name')->orderBy('warehouse_id')
            ->get(['warehouse_id', 'warehouse_name']);
        $counts = $this->verifications->counts($actor, $warehouse);
        $location = isset($filters['location_id']) ? $warehouse->storageLocations()->findOrFail($filters['location_id']) : null;

        return response()->view('warehouses.load-checks.index', compact('warehouse', 'params', 'warehouseId', 'warehouses', 'history',
            'filters', 'items', 'counts', 'location'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Warehouse $warehouse, int $placement)
    {
        $this->policy->read($request->user(), (int) $warehouse->getKey());
        $this->assertReady();
        $worksheet = $this->verifications->show($request->user(), $warehouse, $placement);
        $params = $this->params($request, $warehouse);
        $row = $worksheet['placement'];
        $location = $row['location'];
        $locationUrl = route('warehouses.locations.show', $params + ['warehouseLocation' => $location['id']]);
        $planUrl = $location['on_plan'] ? route('warehouses.floor-plan.show', $params + (
            $location['system_id'] ? ['system' => $location['system_id'], 'bin' => $location['id']] : ['location' => $location['id']]
        )) : null;
        $relocateUrl = route('warehouses.relocations.create', $params + ['from_location_id' => $location['id']]);
        $historyUrl = route('warehouses.load-checks.index', $params + ['state' => 'history', 'placement_id' => $placement]);
        $api = [
            'show' => route('warehouse-load-verifications.show', ['warehouse' => $warehouse, 'placement' => $placement]),
            'preview' => route('warehouse-load-verifications.preview', ['warehouse' => $warehouse, 'placement' => $placement]),
            'store' => route('warehouse-load-verifications.store', ['warehouse' => $warehouse, 'placement' => $placement]),
        ];

        return response()->view('warehouses.load-checks.show', compact('warehouse', 'worksheet', 'params',
            'locationUrl', 'planUrl', 'relocateUrl', 'historyUrl', 'api'))->header('Cache-Control', 'private, no-store');
    }

    private function assertReady(): void
    {
        abort_unless(Schema::hasColumn('warehouse_load_verifications', 'capacity_violation_mask'), 503,
            'A terhelésellenőrzés az adatbázis frissítése után érhető el.');
    }

    private function params(Request $request, Warehouse $warehouse): array
    {
        return array_filter(['warehouse' => $warehouse, 'context' => WarehouseListContext::token($request->query('context')),
            'detail_context' => WarehouseStockContext::token($request->query('detail_context'))]);
    }
}
