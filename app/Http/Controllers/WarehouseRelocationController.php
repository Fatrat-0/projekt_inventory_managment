<?php

namespace App\Http\Controllers;

use App\Models\{Product, User, Warehouse, WarehouseLocation, WarehouseRelocation};
use App\Policies\WarehouseRelocationPolicy;
use App\Services\{WarehouseRelocationService, WarehouseRelocationSources, WarehouseStockAvailability};
use App\Support\{ListingSort, ListingSuggestions, WarehouseListContext, WarehouseStockContext};
use Illuminate\Http\{JsonResponse, RedirectResponse, Request, Response};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarehouseRelocationController extends Controller
{
    private const SORTS = ['relocated_at' => 'Időpont', 'reference' => 'Azonosító', 'product_name' => 'Termék',
        'warehouse_name' => 'Raktár', 'from_location_code' => 'Forrásrekesz', 'to_location_code' => 'Célrekesz',
        'quantity' => 'Mennyiség', 'actor_name' => 'Végrehajtó'];

    public function sources(
        Request $request, WarehouseRelocationPolicy $policy, WarehouseStockAvailability $availability, WarehouseRelocationSources $reader,
    ): JsonResponse {
        $filters = $request->validate([
            'warehouse_id' => 'required_with:from_location_id|nullable|integer|min:1',
            'mode' => 'nullable|in:products',
            'product_id' => 'nullable|integer|min:1',
            'from_location_id' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1|max:2147483647',
        ], $this->filterMessages());
        $actor = $request->user();
        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;
        $warehouses = $this->allowedWarehouses($policy, $actor);
        if ($warehouseId !== null) $policy->authorize($actor, $warehouseId);

        $locationId = isset($filters['from_location_id']) ? (int) $filters['from_location_id'] : null;
        if (($filters['mode'] ?? null) === 'products') {
            if ($locationId !== null) $this->activeSource($warehouseId, $locationId);

            return $this->response(['warehouse_id' => $warehouseId, 'products' =>
                $reader->products($warehouses, $warehouseId, Str::trim($filters['search'] ?? ''), (int) ($filters['per_page'] ?? 25), $locationId)]);
        }
        if ($warehouseId !== null && $productId === null) {
            $request->validate(['product_id' => 'required_with:from_location_id'], $this->filterMessages());

            return $this->response(['warehouse_id' => $warehouseId, 'products' =>
                $reader->products($warehouses, $warehouseId, Str::trim($filters['search'] ?? ''), (int) ($filters['per_page'] ?? 25))]);
        }
        if ($warehouseId === null) {
            if ($productId !== null) {
                $warehouses->whereIn('warehouse_id', $reader->eligible(clone $warehouses)
                    ->where('stock.product_id', $productId)->select('stock.warehouse_id'));
            }

            return $this->response(['warehouses' => $warehouses->orderBy('warehouse_name')->orderBy('warehouse_id')
                ->get(['warehouse_id', 'warehouse_name'])]);
        }
        $warehouse = Warehouse::findOrFail($warehouseId);

        return $this->response(DB::transaction(function () use ($warehouse, $warehouseId, $filters, $availability, $reader) {
            $productId = (int) $filters['product_id'];
            $product = Product::with('defaultPackaging')->findOrFail($productId);
            $stock = $availability->snapshot($warehouseId, $productId);
            $locationId = $filters['from_location_id'] ?? null;
            $locations = $warehouse->storageLocations()->with(['system.zone', 'level']);
            if ($locationId !== null) $locations->whereKey($locationId);
            else $locations->whereIn('id', array_column($stock['locations'], 'location_id'));
            $locations = $locations->orderBy('code')->orderBy('id')->get();
            if ($locationId !== null && $locations->isEmpty()) {
                abort(404, 'A forrásrekesz nem található ebben a raktárban.');
            }
            $placements = $reader->placements($stock, $locations->modelKeys())->groupBy('warehouse_location_id');
            $sources = $locations->map(fn ($location) => $reader->describe($location, $stock, $placements->get($location->id, collect())));

            return [
                'warehouse_id' => $warehouseId, 'product_id' => $productId, 'consistent' => $stock['consistent'],
                'packaging' => $product->defaultPackaging ? ['pieces' => $product->defaultPackaging->pieces_per_package] : null,
                'balances' => array_intersect_key($stock, array_flip(['physical_quantity', 'pickable_quantity',
                    'source_reserved_quantity', 'staged_quantity', 'pending_putaway_quantity', 'quarantined_quantity'])),
            ] + ($locationId === null ? ['sources' => $sources] : ['source' => $sources->first()]);
        }));
    }

    public function candidates(Request $request, WarehouseRelocationService $service): JsonResponse
    {
        $result = $service->candidates($request->user(), $request->all());
        $results = array_merge($result['recommendations'] ?? [], $result['rejected'] ?? [], $result['locations'] ?? []);
        if ($results) {
            $locations = $this->locations($result['warehouse_id'], array_column($results, 'location_id'));
            foreach (['recommendations', 'rejected', 'locations'] as $key) {
                if (isset($result[$key])) {
                    $result[$key] = array_map(fn ($row) => $row + ($locations[$row['location_id']] ?? []), $result[$key]);
                }
            }
        }

        return $this->response($result);
    }

    public function preview(Request $request, WarehouseRelocationService $service): JsonResponse
    {
        return $this->response($service->preview($request->user(), $request->all()));
    }

    public function store(Request $request, WarehouseRelocationService $service): JsonResponse
    {
        return $this->response(['relocation' => $service->relocate($request->user(), $request->all())], 201);
    }

    public function create(
        Request $request, WarehouseRelocationPolicy $policy, WarehouseRelocationSources $reader, ?Warehouse $warehouse = null,
    ): Response|RedirectResponse {
        $filters = $request->validate([
            'warehouse_id' => 'nullable|integer|min:1', 'from_location_id' => 'nullable|integer|min:1',
        ], $this->filterMessages());
        if ($warehouse === null && isset($filters['warehouse_id'])) {
            $policy->authorize($request->user(), (int) $filters['warehouse_id']);

            return redirect()->route('warehouses.relocations.create', ['warehouse' => $filters['warehouse_id']] + $request->except('warehouse_id'));
        }
        if ($warehouse !== null) {
            $policy->authorize($request->user(), (int) $warehouse->getKey());
            $this->assertWarehouseFilter($warehouse, $filters);
        }
        $from = null;
        $initialProduct = null;
        if (isset($filters['from_location_id'])) {
            abort_if($warehouse === null, 404, 'A forrásrekeszhez előbb válassz raktárat.');
            $from = $this->activeSource((int) $warehouse->getKey(), (int) $filters['from_location_id']);
            $products = $reader->products($policy->warehouses($request->user()), (int) $warehouse->getKey(), '', 2, $from->id);
            if ($products->total() === 1) $initialProduct = $products->getCollection()->first();
        }
        $warehouses = $warehouse === null
            ? $this->allowedWarehouses($policy, $request->user())->orderBy('warehouse_name')->orderBy('warehouse_id')->get(['warehouse_id', 'warehouse_name'])
            : collect([$warehouse->only(['warehouse_id', 'warehouse_name'])]);
        $moduleContext = $this->warehouseContext($request);

        return response()->view('warehouse-relocations.create', compact('warehouse', 'warehouses', 'from', 'initialProduct', 'moduleContext'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function legacyDetail(Request $request, WarehouseRelocation $relocation, WarehouseRelocationPolicy $policy): RedirectResponse
    {
        $policy->authorize($request->user(), (int) $relocation->warehouse_id);

        return redirect()->route('warehouses.relocations.show', ['warehouse' => $relocation->warehouse_id, 'relocation' => $relocation]
            + $this->warehouseContext($request))->header('Cache-Control', 'private, no-store');
    }

    public function detail(Request $request, WarehouseRelocationPolicy $policy, Warehouse $warehouse, WarehouseRelocation $relocation): Response
    {
        $policy->authorize($request->user(), (int) $warehouse->getKey());
        abort_unless((int) $relocation->warehouse_id === (int) $warehouse->getKey(), 404, 'Az áthelyezés nem ehhez a raktárhoz tartozik.');
        $locations = $this->locations($relocation->warehouse_id, [$relocation->from_location_id, $relocation->to_location_id]);
        $moduleContext = $this->warehouseContext($request);

        return response()->view('warehouse-relocations.show', compact('warehouse', 'relocation', 'locations', 'moduleContext'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function page(Request $request, WarehouseRelocationPolicy $policy, ?Warehouse $warehouse = null): Response|JsonResponse
    {
        $filters = $this->historyFilters($request);
        if ($warehouse !== null) {
            $policy->authorize($request->user(), (int) $warehouse->getKey());
            $this->assertWarehouseFilter($warehouse, $filters);
            $filters['warehouse_id'] = (int) $warehouse->getKey();
        }
        $query = $this->history($request->user(), $filters, $policy);
        if (ListingSuggestions::requested($request)) {
            if (!ListingSuggestions::hasQuery($filters)) return ListingSuggestions::response();

            return ListingSuggestions::response($query->limit(9)->get(), fn ($row) => [
                'key' => $row->reference, 'value' => $row->reference, 'name' => $row->product_name,
                'detail' => $row->reference.' · '.$row->sku.' · '.$row->from_location_code.' → '.$row->to_location_code,
            ]);
        }
        $warehouses = $warehouse === null
            ? $this->allowedWarehouses($policy, $request->user())->orderBy('warehouse_name')->orderBy('warehouse_id')->get(['warehouse_id', 'warehouse_name'])
            : collect();
        $rows = $query->paginate($filters['per_page'] ?? 25)->withQueryString();
        $sorts = $warehouse === null ? self::SORTS : array_diff_key(self::SORTS, ['warehouse_name' => true]);
        $moduleContext = $this->warehouseContext($request);

        return response()->view('warehouse-relocations.index', compact('warehouse', 'warehouses', 'rows', 'filters', 'sorts', 'moduleContext'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, WarehouseRelocationPolicy $policy): JsonResponse
    {
        $filters = $this->historyFilters($request);

        return $this->response($this->history($request->user(), $filters, $policy)
            ->paginate($filters['per_page'] ?? 25)->withQueryString());
    }

    private function historyFilters(Request $request): array
    {
        $filters = $request->validate([
            'warehouse_id' => 'nullable|integer|min:1', 'product_id' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100', 'page' => 'nullable|integer|min:1|max:2147483647',
        ] + ListingSort::rules(self::SORTS), $this->filterMessages() + ListingSort::messages());

        return ListingSort::normalize($filters, self::SORTS, 'relocated_at');
    }

    private function history(User $actor, array $filters, WarehouseRelocationPolicy $policy): \Illuminate\Database\Eloquent\Builder
    {
        $query = WarehouseRelocation::query();
        if (isset($filters['warehouse_id'])) {
            $policy->authorize($actor, (int) $filters['warehouse_id']);
            $query->where('warehouse_id', $filters['warehouse_id']);
        } else {
            $query->whereIn('warehouse_id', $this->allowedWarehouses($policy, $actor)->select('warehouse_id'));
        }
        if (isset($filters['product_id'])) $query->where('product_id', $filters['product_id']);
        if (($search = Str::trim($filters['search'] ?? '')) !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function ($matches) use ($pattern) {
                foreach (['reference', 'product_name', 'sku', 'from_location_code', 'to_location_code'] as $column) {
                    $matches->orWhereRaw("$column LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        foreach (ListingSort::levels($filters, self::SORTS, 'relocated_at') as ['field' => $field, 'direction' => $direction]) {
            $query->orderBy($field, $direction);
        }

        return $query->orderByDesc('id');
    }

    public function show(Request $request, WarehouseRelocation $relocation, WarehouseRelocationPolicy $policy): JsonResponse
    {
        $policy->authorize($request->user(), (int) $relocation->warehouse_id);

        return $this->response(['relocation' => $relocation]);
    }

    private function locations(int $warehouseId, array $ids): array
    {
        return WarehouseLocation::where('warehouse_id', $warehouseId)->whereKey($ids)->with(['system.zone', 'level'])
            ->get()->mapWithKeys(function (WarehouseLocation $location) {
                $object = $location->system ?? $location;
                $plan = $object->floor_x !== null && $object->floor_y !== null
                    ? route('warehouses.relocations.plan', [$location->warehouse_id, $location]) : null;

                return [$location->id => ['position' => $location->pickingPosition(), 'floor_plan_url' => $plan,
                    'view_url' => $plan ?? route('warehouses.locations.show', [$location->warehouse_id, $location])]];
            })->all();
    }

    private function activeSource(int $warehouseId, int $locationId): WarehouseLocation
    {
        $location = WarehouseLocation::where('warehouse_id', $warehouseId)->whereKey($locationId)->where('active', true)->first();
        abort_if($location === null, 404, 'A forrásrekesz nem elérhető ebben a raktárban.');

        return $location;
    }

    private function assertWarehouseFilter(Warehouse $warehouse, array $filters): void
    {
        abort_if(isset($filters['warehouse_id']) && (int) $filters['warehouse_id'] !== (int) $warehouse->getKey(),
            404, 'A megadott raktár nem egyezik az oldalhoz tartozó raktárral.');
    }

    private function warehouseContext(Request $request): array
    {
        return array_filter([
            'context' => WarehouseListContext::token($request->query('context')),
            'detail_context' => WarehouseStockContext::token($request->query('detail_context')),
        ]);
    }

    private function allowedWarehouses(WarehouseRelocationPolicy $policy, User $actor): \Illuminate\Database\Eloquent\Builder
    {
        $query = $policy->warehouses($actor);
        abort_unless($actor->role === 'admin' || (clone $query)->exists(), 403,
            'Az áthelyezéshez raktári operátori vagy felügyelői jogosultság szükséges.');

        return $query;
    }

    private function filterMessages(): array
    {
        return [
            'warehouse_id.*' => 'Válassz érvényes raktárt.', 'product_id.*' => 'Válassz érvényes terméket.',
            'from_location_id.*' => 'Válassz érvényes forrásrekeszt.', 'search.*' => 'A keresés legfeljebb 100 karakter lehet.',
            'mode.*' => 'Válassz érvényes keresési módot.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti találatot kérhetsz.', 'page.*' => 'Adj meg érvényes oldalszámot.',
        ];
    }

    private function response(mixed $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'private, no-store');
    }
}
