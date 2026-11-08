<?php

namespace App\Http\Controllers;

use App\Models\{Warehouse, WarehouseLocation, WarehousePlacement};
use App\Services\WarehouseCapacity;
use App\Support\{WarehouseListContext, WarehouseStockContext, WarehouseStorageOverview};
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WarehouseLocationController extends Controller
{
    public function index(Request $request, Warehouse $warehouse)
    {
        $systems = $warehouse->storageSystems()->with(['zone', 'levels'])->withCount('locations')
            ->orderBy('warehouse_storage_zones.name')->orderBy('warehouse_storage_systems.code')->orderBy('warehouse_storage_systems.id')
            ->paginate(24, pageName: 'system_page')->withQueryString();
        $summaries = WarehouseStorageOverview::systemSummaries($systems->getCollection());
        $locations = $warehouse->storageLocations()->whereNull('system_id')->withCapacityUsage()->orderByDesc('active')->orderBy('code')
            ->paginate(24, pageName: 'location_page')->withQueryString();
        $products = $locations->isEmpty() ? collect() : WarehouseStorageOverview::products($warehouse->getKey(), locationIds: $locations->getCollection()->modelKeys());
        $unassignedIncoming = $warehouse->incomingTransfers()->where('status', 'pending')->whereNull('to_location_id')->count();

        return view('warehouses.locations.index', $this->viewData($request, $warehouse) + compact('locations', 'systems', 'summaries', 'products', 'unassignedIncoming'));
    }

    public function show(Request $request, Warehouse $warehouse, WarehouseLocation $warehouseLocation)
    {
        $location = $warehouse->storageLocations()->withCapacityUsage()->findOrFail($warehouseLocation->id);
        $sharedFreeWeight = WarehouseStorageOverview::locationFreeWeights($location->newCollection([$location]));
        $placements = $location->placements()->with('stock.product')->latest('id')->paginate(20)->withQueryString();
        $allowed = $location->system_id === null ? collect() : $location->allowedCategories()->pluck('categories.category_id');
        $stocks = $warehouse->stocks()->with('product')->withSum('placements as assigned_quantity', 'quantity')
            ->when($allowed->isNotEmpty(), fn ($query) => $query->whereHas('product', fn ($products) => $products->whereIn('category_id', $allowed)))
            ->whereColumn('quantity', '>', 'quarantined_quantity')->get()
            ->filter(fn ($stock) => $stock->available_quantity > $stock->assigned_quantity)
            ->sortBy('product.product_name');

        return view('warehouses.locations.show', $this->viewData($request, $warehouse) + compact('location', 'placements', 'stocks', 'sharedFreeWeight'));
    }

    public function store(Request $request, Warehouse $warehouse, WarehouseCapacity $capacity)
    {
        $location = $capacity->saveLocation($warehouse, $this->locationData($request));

        return $this->backToLocation($request, $warehouse, $location, 'A tárhely létrejött.');
    }

    public function update(Request $request, Warehouse $warehouse, WarehouseLocation $warehouseLocation, WarehouseCapacity $capacity)
    {
        $location = $warehouse->storageLocations()->findOrFail($warehouseLocation->id);
        $capacity->saveLocation($warehouse, $this->locationData($request, true), $location);

        return $this->backToLocation($request, $warehouse, $location, 'A tárhely adatai frissültek.');
    }

    public function place(Request $request, Warehouse $warehouse, WarehouseLocation $warehouseLocation, WarehouseCapacity $capacity)
    {
        $location = $warehouse->storageLocations()->findOrFail($warehouseLocation->id);
        $data = $request->validate([
            'request_key' => 'required|uuid',
            'product_id' => 'required|integer|exists:products,product_id',
            'quantity' => 'required|integer|min:1|max:2147483647',
            'cargo_weight_kg' => 'required|'.WarehouseCapacity::WEIGHT_RULE,
            'pallet_slots' => 'nullable|'.WarehouseCapacity::SLOT_RULE,
        ], [], ['cargo_weight_kg' => 'teljes tömeg', 'pallet_slots' => 'raklapok száma']);
        $capacity->place($location, $data + ['user_id' => $request->user()->id]);

        return $this->backToLocation($request, $warehouse, $location, 'A készlet a tárhelyhez rendelve.');
    }

    public function release(Request $request, Warehouse $warehouse, WarehouseLocation $warehouseLocation, WarehousePlacement $placement, WarehouseCapacity $capacity)
    {
        $location = $warehouse->storageLocations()->findOrFail($warehouseLocation->id);
        abort_unless((int) $placement->warehouse_location_id === $location->id, 404);
        $capacity->release($location, $placement);

        return $this->backToLocation($request, $warehouse, $location, 'A tétel kikerült a tárhelyről, kiadásra előkészíthető.');
    }

    private function locationData(Request $request, bool $editing = false): array
    {
        if (is_string($request->input('code'))) $request->merge(['code' => Str::upper(trim($request->input('code')))]);
        $rules = [
            'code' => ['required', 'string', 'max:32', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/'],
            'name' => 'required|string|max:100',
            'kind' => 'required|in:shelf,pallet',
            'max_weight_kg' => 'required|'.WarehouseCapacity::WEIGHT_RULE,
            'max_pallet_slots' => 'exclude_unless:kind,pallet|required|'.WarehouseCapacity::SLOT_RULE,
        ];
        if ($editing) $rules += ['active' => 'required|boolean', 'lock_version' => 'required|integer|min:1'];

        return $request->validate($rules, ['code.regex' => 'A kódban betű, szám, pont, kötőjel és aláhúzás szerepelhet.'], [
            'code' => 'tárhelykód', 'name' => 'megnevezés', 'kind' => 'tárhely típusa',
            'max_weight_kg' => 'maximális terhelés', 'max_pallet_slots' => 'raklapférőhely',
        ]);
    }

    private function context(Request $request): array
    {
        return array_filter([
            'context' => WarehouseListContext::token($request->query('context')),
            'detail_context' => WarehouseStockContext::token($request->query('detail_context')),
        ], fn ($value) => $value !== '');
    }

    private function viewData(Request $request, Warehouse $warehouse): array
    {
        $canManage = in_array($request->user()->role, ['admin', 'manager'], true);
        $context = $this->context($request);
        return compact('warehouse', 'canManage') + ['params' => ['warehouse' => $warehouse] + $context];
    }

    private function backToLocation(Request $request, Warehouse $warehouse, WarehouseLocation $location, string $message)
    {
        return redirect()->route('warehouses.locations.show', ['warehouse' => $warehouse, 'warehouseLocation' => $location] + $this->context($request))
            ->with('success', $message);
    }
}
