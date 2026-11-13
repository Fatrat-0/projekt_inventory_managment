<?php

namespace App\Http\Controllers;

use App\Models\{Product, TransferIntent, Warehouse};
use App\Policies\TransferIntentPolicy;
use App\Support\TransferIntentDisplay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Validator};
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransferIntentPageController extends Controller
{
    public function index(Request $request, TransferIntentPolicy $policy): View
    {
        Gate::authorize('viewAny', TransferIntent::class);
        $user = $request->user();
        $scope = $policy->visibleTo(TransferIntent::query(), $user);
        $validator = Validator::make($request->query(), [
            'search' => 'nullable|string|max:200',
            'status' => ['nullable', Rule::in(array_keys(TransferIntentDisplay::STATUS))],
            'priority' => ['nullable', Rule::in(array_keys(TransferIntentDisplay::PRIORITY))],
            'warehouse_id' => 'nullable|integer|min:1',
        ], [
            'search.*' => 'A keresés legfeljebb 200 karakteres szöveg lehet.',
            'status.*' => 'Válassz érvényes állapotot.',
            'priority.*' => 'Válassz érvényes prioritást.',
            'warehouse_id.*' => 'Válassz érvényes raktárat.',
        ]);
        $filterErrors = $validator->errors();
        $filters = array_diff_key($request->only(['search', 'status', 'priority', 'warehouse_id']),
            array_flip($filterErrors->keys()));
        $query = (clone $scope)->with([
            'product:product_id,product_name,sku',
            'fromWarehouse:warehouse_id,warehouse_name',
            'toWarehouse:warehouse_id,warehouse_name',
        ]);

        foreach (['status', 'priority'] as $field) {
            if (!empty($filters[$field])) $query->where($field, $filters[$field]);
        }
        if (!empty($filters['warehouse_id'])) {
            $id = (int) $filters['warehouse_id'];
            $query->where(fn (Builder $route) => $route->where('from_warehouse_id', $id)->orWhere('to_warehouse_id', $id));
        }
        foreach (preg_split('/\s+/u', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(fn (Builder $match) => $match->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('product', fn (Builder $product) => $product
                    ->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('fromWarehouse', fn (Builder $warehouse) => $warehouse->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('toWarehouse', fn (Builder $warehouse) => $warehouse->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern])));
        }

        $requestWarehouseIds = $policy->requestWarehouseIds($user);
        $warehouseQuery = Warehouse::query();
        if ($user->role !== 'admin') {
            $warehouseQuery->where(fn (Builder $warehouses) => $warehouses
                ->whereIn('warehouse_id', $requestWarehouseIds)
                ->orWhereIn('warehouse_id', (clone $scope)->select('from_warehouse_id'))
                ->orWhereIn('warehouse_id', (clone $scope)->select('to_warehouse_id')));
        }

        return view('transfer-intents.index', [
            'intents' => $query->latest('id')->paginate(25)->appends($filters),
            'warehouses' => $warehouseQuery->orderBy('warehouse_name')->get(['warehouse_id', 'warehouse_name']),
            'canCreate' => $user->role === 'admin' || $requestWarehouseIds !== [],
            'filters' => $filters,
            'filterErrors' => $filterErrors,
        ]);
    }

    public function create(Request $request, TransferIntentPolicy $policy): View
    {
        $user = $request->user();
        $requestWarehouseIds = $policy->requestWarehouseIds($user);
        if ($user->role !== 'admin' && $requestWarehouseIds === []) {
            abort(403, 'Az igény benyújtásához raktári igénylési jogosultság szükséges.');
        }
        $warehouses = Warehouse::orderBy('warehouse_name')->get(['warehouse_id', 'warehouse_name']);
        if ($user->role === 'admin') {
            $requestWarehouseIds = $warehouses->pluck('warehouse_id')->map(fn ($id) => (string) $id)->all();
        }

        return view('transfer-intents.create', [
            'products' => Product::select('product_id', 'product_name', 'sku')
                ->with('defaultPackaging:id,product_id,name,pieces_per_package')->orderBy('product_name')->get(),
            'warehouses' => $warehouses,
            'requestWarehouseIds' => $requestWarehouseIds,
        ]);
    }

    public function show(Request $request, TransferIntent $transferIntent, TransferIntentPolicy $policy): View
    {
        Gate::authorize('view', $transferIntent);
        $transferIntent->load([
            'product:product_id,product_name,sku',
            'fromWarehouse:warehouse_id,warehouse_name',
            'toWarehouse:warehouse_id,warehouse_name',
            'requester:id,name',
        ]);

        return view('transfer-intents.show', [
            'intent' => $transferIntent,
            'workflow' => TransferIntentDisplay::workflow($transferIntent, $request->user(), $policy),
        ]);
    }
}
