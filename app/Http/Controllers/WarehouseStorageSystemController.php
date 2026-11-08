<?php

namespace App\Http\Controllers;

use App\Models\{Category, Warehouse, WarehouseLocation, WarehouseStorageSystem};
use App\Services\{WarehouseCapacity, WarehouseStorageDesigner};
use App\Support\{WarehouseListContext, WarehouseStockContext, WarehouseStorageOverview};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseStorageSystemController extends Controller
{
    public function show(Request $request, Warehouse $warehouse, int $system)
    {
        [$record, $overview, $bins, $products] = $this->overview($warehouse, $system, true);
        $bin = $bins->get($request->integer('bin')) ?? $bins->first();
        $capacity = $overview['levels']->flatMap(fn ($level) => $level['bins'])->firstWhere('id', $bin?->id);
        $canManage = in_array($request->user()->role, ['admin', 'manager'], true);
        $categories = $canManage ? Category::orderBy('category_name')->orderBy('category_id')->get() : collect();

        return view('warehouses.locations.system', $this->context($request, $warehouse, $record)
            + compact('warehouse', 'record', 'overview', 'bin', 'capacity', 'products', 'canManage', 'categories'));
    }

    public function capacity(Request $request, Warehouse $warehouse, int $system)
    {
        [$record, $overview, , $products] = $this->overview($warehouse, $system);

        return view('warehouses.locations.system-capacity', $this->context($request, $warehouse, $record)
            + compact('record', 'overview', 'products'));
    }

    public function updateBin(Request $request, Warehouse $warehouse, int $system, int $bin, WarehouseStorageDesigner $designer)
    {
        $size = 'required|integer|min:1|max:5000';
        $data = $request->validate(['width_cm' => $size, 'depth_cm' => $size, 'height_cm' => 'required|integer|min:1|max:3000',
            'max_weight_kg' => 'required|'.WarehouseCapacity::WEIGHT_RULE, 'categories' => 'sometimes|array|max:1000',
            'categories.*' => 'integer|distinct|exists:categories,category_id', 'active' => 'required|boolean',
            'lock_version' => 'required|integer|min:1'], [
            'required' => 'Töltsd ki a rekesz kötelező mezőit.', 'integer' => 'Egész számot adj meg.',
            'min' => 'A megadott érték túl kicsi.', 'max' => 'A megadott érték túl nagy.', 'numeric' => 'Számszerű értéket adj meg.',
            'decimal' => 'Legfeljebb három tizedesjegyet adhatsz meg.', 'array' => 'Érvénytelen kategórialista.',
            'categories.*.distinct' => 'Egy kategória csak egyszer választható.', 'categories.*.exists' => 'A kiválasztott kategória nem létezik.',
            'boolean' => 'Válassz érvényes állapotot.',
        ]);
        $designer->updateBin($warehouse, $system, $bin, $data);

        return redirect(route('warehouses.storage-systems.show', ['warehouse' => $warehouse, 'system' => $system, 'bin' => $bin] + $this->tokens($request)).'#rack-bin')
            ->with('success', 'A rekesz beállításai mentve.');
    }

    private function overview(Warehouse $warehouse, int $system, bool $includeCategories = false): array
    {
        return DB::transaction(function () use ($warehouse, $system, $includeCategories) {
            $current = Warehouse::whereKey($warehouse->getKey())->sharedLock()->firstOrFail();
            $record = $current->storageSystems()->with(['zone', 'levels'])->findOrFail($system);
            $bins = $record->locations()->withCapacityUsage()->when($includeCategories, fn ($query) => $query->with('allowedCategories'))
                ->orderBy('bin_number')->orderBy('code')->get()->keyBy('id');
            $products = WarehouseStorageOverview::products($current->getKey(), $record->id);
            $overview = WarehouseStorageOverview::system($record, $bins->toBase()->map(fn (WarehouseLocation $bin) =>
                WarehouseStorageOverview::location($bin, $products->get($bin->id, collect()))));

            return [$record, $overview, $bins, $products];
        });
    }

    private function context(Request $request, Warehouse $warehouse, WarehouseStorageSystem $system): array
    {
        return ['params' => ['warehouse' => $warehouse] + $this->tokens($request), 'systemParams' => ['warehouse' => $warehouse, 'system' => $system->id] + $this->tokens($request)];
    }

    private function tokens(Request $request): array
    {
        return array_filter(['context' => WarehouseListContext::token($request->query('context')),
            'detail_context' => WarehouseStockContext::token($request->query('detail_context'))]);
    }
}
