<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Policies\ShipmentPolicy;
use App\Support\{ListingSort, ListingSuggestions};
use App\Services\{ShipmentPlanReader, ShipmentReceivingReader};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};

class ShipmentPlanPageController extends Controller
{
    public function index(Request $request, ShipmentPolicy $policy, ShipmentPlanReader $reader)
    {
        Gate::authorize('viewAnyPlan', Shipment::class);
        $validation = ShipmentPlanReader::listFilters($request->query());
        $suggestions = ListingSuggestions::requested($request);
        if ($suggestions) $validation->validate();
        $filterErrors = $validation->errors();
        $filters = array_diff_key($request->only(array_keys($validation->getRules())), array_flip($filterErrors->keys()));
        $filters = ListingSort::normalize($filters, ShipmentPlanReader::SORTS, 'created_at');
        if ($suggestions && !ListingSuggestions::hasQuery($filters)) return ListingSuggestions::response();
        $query = $policy->visiblePlans(Shipment::query(), $request->user());

        if ($suggestions) {
            $plans = DB::transaction(fn () => $reader->plans($query, ListingSuggestions::filters($filters)));

            return ListingSuggestions::response($plans->items(), fn (array $plan) => [
                'key' => 'shipment-'.$plan['id'],
                'value' => $plan['shipment_number'],
                'name' => $plan['shipment_number'].' · '.$plan['reference'],
                'detail' => $plan['origin_warehouse_name'].($plan['vehicle_label'] ? ' · '.$plan['vehicle_label'] : ''),
            ]);
        }

        return $this->page('shipments.index', [
            'plans' => DB::transaction(fn () => $reader->plans($query, $filters)),
            'warehouses' => $policy->warehousesFor($request->user())->get(['warehouse_id', 'warehouse_name']),
            'canCreate' => Gate::allows('createAnyPlan', Shipment::class),
            'filters' => $filters,
            'filterErrors' => $filterErrors->all(),
        ]);
    }

    public function create(Request $request, ShipmentPolicy $policy)
    {
        Gate::authorize('createAnyPlan', Shipment::class);

        return $this->page('shipments.create-plan', [
            'warehouses' => $policy->warehousesFor($request->user(), true)->get(['warehouse_id', 'warehouse_name']),
        ]);
    }

    public function show(Request $request, Shipment $shipment, ShipmentController $legacy, ShipmentReceivingReader $receiving)
    {
        if (!$shipment->isTransferIntentPlan()) {
            abort_unless(in_array($request->user()->role, ['admin', 'manager', 'worker'], true), 403, 'Nincs jogosultságod ehhez a szállítmányhoz.');

            return $legacy->show($request, $shipment);
        }
        $sourceAccess = Gate::allows('viewPlan', $shipment);
        $receivingStops = DB::transaction(fn () => $receiving->shipmentStops($request->user(), $shipment));
        $shipment->load('origin:warehouse_id,warehouse_name');
        if (!$sourceAccess) {
            return $this->page('shipments.receiving', compact('shipment', 'receivingStops'));
        }

        return $this->page('shipments.plan', [
            'shipment' => $shipment,
            'receivingStops' => $receivingStops,
            'canManage' => $shipment->status === 'draft' && Gate::allows('managePlan', $shipment),
            'canOverrideDedicated' => $shipment->status === 'draft' && Gate::allows('overrideDedicated', $shipment),
        ]);
    }

    public function edit(Shipment $shipment)
    {
        if ($shipment->isTransferIntentPlan()) Gate::authorize('managePlan', $shipment);
        abort_unless($shipment->status === 'draft', 409, 'Csak tervezet szerkeszthető.');

        return redirect()->route('shipments.show', $shipment);
    }

    private function page(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'private, no-store');
    }
}
