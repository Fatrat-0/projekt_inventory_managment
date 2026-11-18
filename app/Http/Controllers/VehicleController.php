<?php

namespace App\Http\Controllers;

use App\Models\{Shipment, Vehicle};
use App\Policies\ShipmentPolicy;
use App\Services\VehicleRegistry;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\Gate;

class VehicleController extends Controller
{
    public function page(Request $request): Response
    {
        Gate::authorize('viewAnyPlan', Shipment::class);

        return response()->view('vehicles.index', ['canManageVehicles' => $request->user()->role === 'admin'])
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAnyPlan', Shipment::class);

        $vehicles = Vehicle::query()
            ->when($request->user()->role !== 'admin', fn ($query) => $query->where('active', true))
            ->orderBy('name')->orderBy('id')->get()->map(fn (Vehicle $vehicle) => $vehicle->registrySnapshot());

        return $this->response(['data' => $vehicles]);
    }

    public function relatedShipments(Request $request, Vehicle $vehicle, ShipmentPolicy $policy): JsonResponse
    {
        Gate::authorize('viewAnyPlan', Shipment::class);
        abort_unless($vehicle->active || $request->user()->role === 'admin', 404, 'A jármű nem található.');

        $filters = $request->validate([
            'status' => 'sometimes|in:all,draft,in_transit,history',
            'page' => 'sometimes|integer|min:1|max:2147483647',
        ], [
            'status.*' => 'Válassz érvényes fuvarállapotot.',
            'page.*' => 'Adj meg érvényes oldalszámot.',
        ]);
        $statuses = match ($filters['status'] ?? 'all') {
            'draft' => ['draft'],
            'in_transit' => ['in_transit'],
            'history' => ['completed', 'cancelled'],
            default => null,
        };

        $shipments = $policy->visiblePlans(Shipment::query(), $request->user())->whereBelongsTo($vehicle)
            ->when($statuses, fn ($query) => $query->whereIn('status', $statuses))
            ->with('origin:warehouse_id,warehouse_name')->latest('id')
            ->paginate(12, ['id', 'reference', 'status', 'origin_warehouse_id'], 'page', $filters['page'] ?? 1)
            ->through(fn (Shipment $shipment) => [
                'id' => $shipment->id,
                'shipment_number' => $shipment->shipment_number,
                'reference' => $shipment->reference,
                'status' => $shipment->status,
                'status_label' => Shipment::STATUSES[$shipment->status] ?? 'Ismeretlen állapot',
                'origin_warehouse_name' => $shipment->origin->warehouse_name,
                'url' => route('shipments.show', $shipment),
            ]);

        return $this->response($shipments->toArray());
    }

    public function store(Request $request, VehicleRegistry $registry): JsonResponse
    {
        return $this->response($registry->save($request->user(), $request->all()), 201);
    }

    public function update(Request $request, Vehicle $vehicle, VehicleRegistry $registry): JsonResponse
    {
        return $this->response($registry->save($request->user(), $request->all(), $vehicle));
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
