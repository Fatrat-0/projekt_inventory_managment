<?php

namespace App\Http\Controllers;

use App\Models\{Shipment, ShipmentReceiptItem, TransferIntent, Warehouse, WarehouseLocation, WarehouseStorageSystem};
use App\Services\WarehouseFloorPlan;
use App\Services\{WarehouseCapacity, WarehouseStorageDesigner};
use App\Support\{WarehouseListContext, WarehouseStockContext, WarehouseStorageOverview};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Collection;

class WarehouseFloorPlanController extends Controller
{
    public function show(Request $request, Warehouse $warehouse)
    {
        return $this->renderPlan($request, $warehouse);
    }

    public function picking(Request $request, TransferIntent $transferIntent, WarehouseLocation $warehouseLocation)
    {
        Gate::authorize('viewPickingTask', $transferIntent);
        $taskId = WarehouseTransferTaskController::selectedTaskId($request);
        $tasks = $transferIntent->pickingTasks();
        $task = $taskId === null ? $tasks->latest('id')->firstOrFail() : $tasks->findOrFail($taskId);
        $warehouse = $transferIntent->fromWarehouse;
        abort_unless((int) $warehouseLocation->warehouse_id === (int) $warehouse->getKey()
            && (int) $task->warehouse_id === (int) $warehouse->getKey()
            && ($warehouseLocation->placements()->where('stock_id', $task->stock_id)->exists()
                || $task->allocations()->where('warehouse_location_id', $warehouseLocation->id)->exists()),
            404, 'A forrásrekesz nem található ehhez a kitárolási feladathoz.');
        [$warehouse, $pickingSource, $plan] = DB::transaction(fn () => $this->locationPreview($warehouse, $warehouseLocation));
        $pickingBackUrl = route('warehouse-picking-pages.show', ['transferIntent' => $transferIntent, 'task' => $task->id]);

        return response()->view('warehouses.floor-plan', compact('warehouse', 'plan', 'pickingSource', 'pickingBackUrl') + [
            'context' => null, 'detailContext' => null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function putaway(ShipmentReceiptItem $item, WarehouseLocation $warehouseLocation)
    {
        [$warehouse, $putawayTarget, $plan] = DB::transaction(function () use ($item, $warehouseLocation) {
            Gate::authorize('putawayReceiptItem', [Shipment::class, $item]);
            abort_unless((int) $warehouseLocation->warehouse_id === (int) $item->to_warehouse_id, 404,
                'A célrekesz nem ehhez az átvételi raktárhoz tartozik.');
            $preview = $this->locationPreview($item->warehouse, $warehouseLocation);
            $object = $preview[1]->system ?? $preview[1];
            abort_if($object->floor_x === null || $object->floor_y === null, 404, 'A rekesz nincs elhelyezve az alaprajzon.');

            return $preview;
        });
        $putawayBackUrl = route('warehouse-putaway-pages.show', $item);

        return response()->view('warehouses.floor-plan', compact('warehouse', 'plan', 'putawayTarget', 'putawayBackUrl') + [
            'pickingSource' => null, 'context' => null, 'detailContext' => null,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function locationPreview(Warehouse $warehouse, WarehouseLocation $source): array
    {
        $warehouse = Warehouse::whereKey($warehouse->getKey())->sharedLock()->firstOrFail();
        $source = $warehouse->storageLocations()->with(['system.zone', 'level'])->findOrFail($source->id);
        $locations = $warehouse->storageLocations()->whereNull('system_id')
            ->where(fn ($query) => $query->whereNotNull('floor_x')->orWhere('id', $source->id))
            ->orderBy('code')->get(['id', 'code', 'name', 'kind', 'floor_x', 'floor_y', 'floor_width', 'floor_height']);
        $systems = $warehouse->storageSystems()
            ->where(fn ($query) => $query->whereNotNull('warehouse_storage_systems.floor_x')
                ->orWhere('warehouse_storage_systems.id', $source->system_id))
            ->with('zone:id,name')
            ->get(['warehouse_storage_systems.id', 'warehouse_storage_systems.code', 'warehouse_storage_systems.name',
                'warehouse_storage_systems.zone_id', 'warehouse_storage_systems.kind', 'warehouse_storage_systems.floor_x',
                'warehouse_storage_systems.floor_y', 'warehouse_storage_systems.floor_width', 'warehouse_storage_systems.floor_height',
                'warehouse_storage_systems.height_cm']);

        $levels = collect();
        if ($source->system) {
            $bins = $warehouse->storageLocations()->where('system_id', $source->system_id)->orderBy('bin_number')->orderBy('id')
                ->get(['id', 'code', 'level_id']);
            $levels = $source->system->kind === 'floor'
                ? collect([['name' => 'Padlóhelyek', 'bins' => $bins->map->only(['id', 'code'])]])
                : $source->system->levels()->get(['id', 'number'])->map(fn ($level) => [
                    'name' => $level->number.'. szint',
                    'bins' => $bins->where('level_id', $level->id)->values()->map->only(['id', 'code']),
                ]);
        }

        return [$warehouse, $source, [
            'columns' => (int) $warehouse->floor_columns, 'rows' => (int) $warehouse->floor_rows,
            'minSize' => WarehouseFloorPlan::MIN_SIZE, 'maxSize' => WarehouseFloorPlan::MAX_SIZE,
            'canEdit' => false, 'zones' => [], 'pickingPreview' => true,
            'focus' => $source->system_id === null ? (int) $source->id : 'system-'.$source->system_id,
            'focusBin' => (int) $source->id,
            'locations' => $locations->map(fn ($location) => [
                'id' => $location->id, 'code' => $location->code, 'name' => $location->name,
                'kindLabel' => WarehouseLocation::KINDS[$location->kind],
                'x' => $location->floor_x, 'y' => $location->floor_y, 'width' => $location->floor_width, 'height' => $location->floor_height,
            ]),
            'systems' => $systems->map(fn ($system) => [
                'id' => $system->id, 'code' => $system->displayCode(), 'name' => $system->name, 'zone' => $system->zone->name,
                'kind' => $system->kind, 'kindLabel' => WarehouseStorageSystem::KINDS[$system->kind],
                'x' => $system->floor_x, 'y' => $system->floor_y, 'width' => $system->floor_width, 'height' => $system->floor_height,
                'rackHeight' => $system->height_cm === null ? null : $system->height_cm / 100,
                'levels' => $system->id === $source->system_id ? $levels : [],
            ]),
        ]];
    }

    private function renderPlan(Request $request, Warehouse $warehouse)
    {
        $context = WarehouseListContext::token($request->query('context'));
        $detailContext = WarehouseStockContext::token($request->query('detail_context'));
        $params = array_filter(['warehouse' => $warehouse, 'context' => $context, 'detail_context' => $detailContext]);
        $canEdit = in_array($request->user()->role, ['admin', 'manager'], true);
        [$warehouse, $locations, $products, $systems, $zones] = DB::transaction(function () use ($warehouse) {
            $current = Warehouse::whereKey($warehouse->getKey())->sharedLock()->firstOrFail();
            $locations = $current->storageLocations()->withCapacityUsage()->orderBy('code')->get();
            $products = WarehouseStorageOverview::products($current->getKey());

            return [$current, $locations, $products, $current->storageSystems()->with(['zone', 'levels'])->get(),
                DB::table('warehouse_storage_zones')->where('warehouse_id', $current->getKey())->orderBy('name')->pluck('name')];
        });

        $plan = [
            'columns' => (int) $warehouse->floor_columns, 'rows' => (int) $warehouse->floor_rows, 'version' => (int) $warehouse->floor_version,
            'url' => route('warehouses.floor-plan.update', $warehouse), 'canEdit' => $canEdit,
            'systemUrl' => route('warehouses.floor-plan.systems.store', $warehouse), 'zones' => $zones,
            'minSize' => WarehouseFloorPlan::MIN_SIZE, 'maxSize' => WarehouseFloorPlan::MAX_SIZE,
            'locations' => $locations->map(function (WarehouseLocation $location) use ($params, $products) {
                $stored = $products->get($location->id, collect());
                $weight = (int) $location->occupied_weight + (int) $location->reserved_weight;
                $slots = (int) $location->occupied_slots + (int) $location->reserved_slots;

                return WarehouseStorageOverview::location($location, $stored) + [
                    'kind' => $location->kind, 'kindLabel' => WarehouseLocation::KINDS[$location->kind],
                    'x' => $location->floor_x, 'y' => $location->floor_y, 'width' => $location->floor_width, 'height' => $location->floor_height,
                    'url' => route('warehouses.locations.show', $params + ['warehouseLocation' => $location]),
                    'usage' => max($weight / $location->max_weight_grams, $location->max_pallet_slots ? $slots / $location->max_pallet_slots : 0),
                    'freeWeight' => WarehouseLocation::formatWeight($location->freeWeight()),
                    'reservedWeight' => WarehouseLocation::formatWeight((int) $location->reserved_weight),
                    'maxWeight' => WarehouseLocation::formatWeight($location->max_weight_grams),
                ] + $this->inventorySummary($stored);
            })->values(),
        ];

        $bins = $plan['locations']->whereNotNull('system_id')->groupBy('system_id');
        $requestedBin = $request->integer('bin');
        $plan['systems'] = $systems->map(function ($system) use ($bins, $products, $warehouse, $params, $requestedBin) {
            $systemBins = $bins->get($system->id, collect());
            $bin = $requestedBin && $systemBins->contains('id', $requestedBin) ? ['bin' => $requestedBin] : [];
            $stored = $products->only($systemBins->pluck('id')->all())->collapse()->groupBy('product_id')
                ->map(fn ($rows) => (object) ['product_id' => $rows->first()->product_id, 'product_name' => $rows->first()->product_name,
                    'quantity' => $rows->sum('quantity')])->sortBy('product_name');

            return WarehouseStorageOverview::system($system, $systemBins) + $this->inventorySummary($stored)
                + ['url' => route('warehouses.floor-plan.systems.update', [$warehouse, $system->id]),
                    'frontUrl' => route('warehouses.storage-systems.show', $params + ['system' => $system->id] + $bin).($bin ? '#rack-bin' : '')];
        })->values();
        $plan['locations'] = $plan['locations']->whereNull('system_id')->values();

        return view('warehouses.floor-plan', compact('warehouse', 'context', 'detailContext', 'plan') + ['pickingSource' => null]);
    }

    private function inventorySummary(Collection $products): array
    {
        return ['products' => $products->take(5)->map(fn ($product) => ['id' => $product->product_id,
            'name' => $product->product_name, 'quantity' => (int) $product->quantity])->values(), 'productCount' => $products->count()];
    }

    public function update(Request $request, Warehouse $warehouse, WarehouseFloorPlan $floorPlan)
    {
        $size = 'required|integer|min:'.WarehouseFloorPlan::MIN_SIZE.'|max:'.WarehouseFloorPlan::MAX_SIZE;
        $rules = [
            'version' => 'required|integer|min:1', 'columns' => $size, 'rows' => $size,
            'positions' => 'present|array|max:10000', 'positions.*' => 'required|array:id,x,y,width,height',
            'positions.*.id' => 'required|integer|min:1',
            'positions.*.x' => 'required|integer|min:0|max:99', 'positions.*.y' => 'required|integer|min:0|max:99',
            'positions.*.width' => 'required|integer|min:1|max:100', 'positions.*.height' => 'required|integer|min:1|max:100',
        ];
        $rules['systems'] = 'sometimes|array|max:10000';
        foreach (array_keys($rules) as $key) {
            if (str_starts_with($key, 'positions.')) $rules['systems'.substr($key, strlen('positions'))] = $rules[$key];
        }
        $data = $request->validate($rules, $this->messages());

        return response()->json(['version' => $floorPlan->save($warehouse, $data)]);
    }

    public function storeSystem(Request $request, Warehouse $warehouse, WarehouseStorageDesigner $designer)
    {
        $rules = $this->systemRules() + [
            'kind' => 'required|in:rack,pallet,floor', 'levels' => 'exclude_if:kind,floor|required|integer|min:1|max:12',
            'bins' => 'required|integer|min:1|max:24', 'x' => 'required|integer|min:0|max:99', 'y' => 'required|integer|min:0|max:99',
            'width' => 'required|integer|min:1|max:100', 'height' => 'required|integer|min:1|max:100',
            'rotated' => 'sometimes|boolean',
        ];
        $rules['rack_height'] = 'exclude_if:kind,floor|required|numeric|decimal:0,2|min:0.5|max:30';
        $rules['level_weight'] = 'exclude_if:kind,floor|required|'.WarehouseCapacity::WEIGHT_RULE;
        $rules['level_weights'] = 'exclude_if:kind,floor|sometimes|array|max:12';
        $rules['level_weights.*'] = 'required|'.WarehouseCapacity::WEIGHT_RULE;
        $rules['bin_weight'] = 'required|'.WarehouseCapacity::WEIGHT_RULE;
        $rules['code'] = ['sometimes', 'required', 'string', 'max:24', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', 'not_regex:/\AT[0-9]+\z/i'];
        $rules['bin_dimensions'] = 'sometimes|array:width_cm,depth_cm,height_cm';
        $rules['bin_dimensions.width_cm'] = 'sometimes|required|integer|min:1|max:5000';
        $rules['bin_dimensions.depth_cm'] = 'sometimes|required|integer|min:1|max:5000';
        $rules['bin_dimensions.height_cm'] = 'sometimes|required|integer|min:1|max:3000';

        return response()->json($designer->create($warehouse, $request->validate($rules, $this->messages())), 201);
    }

    public function updateSystem(Request $request, Warehouse $warehouse, int $system, WarehouseStorageDesigner $designer)
    {
        $designer->update($warehouse, $system, $request->validate($this->systemRules(), $this->messages()));

        return response()->noContent();
    }

    private function systemRules(): array
    {
        return ['version' => 'required|integer|min:1', 'name' => 'required|string|max:80', 'zone' => 'required|string|max:80',
            'bin_weight' => 'sometimes|required|'.WarehouseCapacity::WEIGHT_RULE, 'level_weight' => 'nullable|'.WarehouseCapacity::WEIGHT_RULE,
            'system_weight' => 'required|'.WarehouseCapacity::WEIGHT_RULE, 'rack_height' => 'nullable|numeric|decimal:0,2|min:0.5|max:30'];
    }

    private function messages(): array
    {
        return ['required' => 'Töltsd ki a tervrajz kötelező mezőit.', 'present' => 'Hiányzik az elrendezés.',
            'integer' => 'A méret, a koordináta és a darabszám egész szám legyen.', 'min' => 'A megadott érték túl kicsi.',
            'max' => 'A megadott érték túl nagy.', 'array' => 'Az elrendezés formátuma érvénytelen.', 'string' => 'Szöveges értéket adj meg.',
            'numeric' => 'Számszerű értéket adj meg.', 'decimal' => 'A terhelés legfeljebb három tizedesjegyet tartalmazhat.',
            'rack_height.decimal' => 'Az állvány magassága legfeljebb két tizedesjegyet tartalmazhat.',
            'code.regex' => 'Az azonosító betűvel vagy számmal kezdődjön. Betű, szám, kötőjel, pont és aláhúzás használható.',
            'code.not_regex' => 'A T betűből és számokból álló azonosítók a korábbi rendszerekhez vannak fenntartva.',
            'boolean' => 'A megadott kapcsoló értéke érvénytelen.', 'in' => 'Válassz érvényes tárolótípust.'];
    }
}
