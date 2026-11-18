<?php

namespace App\Http\Controllers;

use App\Models\{Product, TransferIntent, Warehouse};
use App\Policies\TransferIntentPolicy;
use App\Support\{ListingSort, ListingSuggestions, TransferIntentDisplay};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Gate, Validator};
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TransferIntentPageController extends Controller
{
    public const SORTS = [
        'reference' => 'Igényazonosító', 'product_name' => 'Termék', 'from_warehouse' => 'Forrásraktár',
        'to_warehouse' => 'Célraktár', 'quantity' => 'Mennyiség', 'status' => 'Állapot', 'priority' => 'Prioritás',
        'created_at' => 'Létrehozva', 'requested_arrival_at' => 'Határidő',
    ];

    public function index(Request $request, TransferIntentPolicy $policy): View|JsonResponse
    {
        Gate::authorize('viewAny', TransferIntent::class);
        $user = $request->user();
        $scope = $policy->visibleTo(TransferIntent::query(), $user);
        $validator = Validator::make($request->query(), [
            'search' => 'nullable|string|max:200',
            'status' => ['nullable', Rule::in(array_keys(TransferIntentDisplay::STATUS))],
            'priority' => ['nullable', Rule::in(array_keys(TransferIntentDisplay::PRIORITY))],
            'warehouse_id' => 'nullable|integer|min:1',
            ...ListingSort::rules(self::SORTS),
            'page' => 'sometimes|integer|min:1|max:2147483647',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ], [
            'search.*' => 'A keresés legfeljebb 200 karakteres szöveg lehet.',
            'status.*' => 'Válassz érvényes állapotot.',
            'priority.*' => 'Válassz érvényes prioritást.',
            'warehouse_id.*' => 'Válassz érvényes raktárat.',
            ...ListingSort::messages(),
            'page.*' => 'Adj meg érvényes oldalszámot.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti találatszám adható meg.',
        ]);
        $suggestions = ListingSuggestions::requested($request);
        if ($suggestions) $validator->validate();
        $filterErrors = $validator->errors();
        $filters = array_diff_key($request->only(array_keys($validator->getRules())),
            array_flip($filterErrors->keys()));
        $filters = ListingSort::normalize($filters, self::SORTS, 'created_at');
        if ($suggestions && !ListingSuggestions::hasQuery($filters)) return ListingSuggestions::response();
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

        $this->sort($query, $filters);
        if ($suggestions) {
            return ListingSuggestions::response($query->limit(9)->get(), fn (TransferIntent $intent) => [
                'key' => 'intent-'.$intent->id,
                'value' => $intent->reference,
                'name' => $intent->product->product_name,
                'detail' => $intent->reference.' · '.$intent->product->sku.' · '.$intent->fromWarehouse->warehouse_name.' → '.$intent->toWarehouse->warehouse_name,
            ]);
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
            'intents' => $query->paginate($filters['per_page'] ?? 25, page: $filters['page'] ?? 1)->appends($filters),
            'warehouses' => $warehouseQuery->orderBy('warehouse_name')->get(['warehouse_id', 'warehouse_name']),
            'canCreate' => $user->role === 'admin' || $requestWarehouseIds !== [],
            'filters' => $filters,
            'filterErrors' => $filterErrors,
        ]);
    }

    private function sort(Builder $query, array $filters): void
    {
        foreach (ListingSort::levels($filters, self::SORTS, 'created_at') as $level) {
            $sort = $level['field'];
            $direction = $level['direction'];
            if (in_array($sort, ['requested_arrival_at', 'created_at'], true)) {
                $query->orderByRaw('transfer_intents.'.$sort.' IS NULL');
            }
            $column = match ($sort) {
                'product_name' => Product::select('product_name')->whereColumn('products.product_id', 'transfer_intents.product_id'),
                'from_warehouse' => Warehouse::select('warehouse_name')->whereColumn('warehouses.warehouse_id', 'transfer_intents.from_warehouse_id'),
                'to_warehouse' => Warehouse::select('warehouse_name')->whereColumn('warehouses.warehouse_id', 'transfer_intents.to_warehouse_id'),
                default => 'transfer_intents.'.$sort,
            };
            // Business order: normal < urgent < critical; pending < approved < rejected < cancelled.
            if ($sort === 'priority') {
                $query->orderByRaw("CASE priority WHEN 'normal' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END ".$direction);
            } elseif ($sort === 'status') {
                $query->orderByRaw("CASE status WHEN 'pending_approval' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END ".$direction);
            } else {
                $query->orderBy($column, $direction);
            }
        }
        $query->orderBy('transfer_intents.id', $filters['sort_dir']);
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
