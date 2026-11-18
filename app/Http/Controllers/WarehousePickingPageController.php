<?php

namespace App\Http\Controllers;

use App\Models\{Product, TransferIntent, Warehouse, WarehouseTransferTask};
use App\Policies\TransferIntentPolicy;
use App\Services\WarehouseTransferTaskService;
use App\Support\{ListingSort, ListingSuggestions};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Validation\Rule;

class WarehousePickingPageController extends Controller
{
    public const SORTS = [
        'id' => 'Feladat azonosítója', 'reference' => 'Igényazonosító', 'product_name' => 'Termék', 'from_warehouse' => 'Forrásraktár',
        'to_warehouse' => 'Célraktár', 'remaining_quantity' => 'Hátralévő', 'status' => 'Állapot', 'priority' => 'Prioritás',
    ];

    public function index(Request $request, TransferIntentPolicy $policy): Response|JsonResponse
    {
        Gate::authorize('viewPickingTaskList', TransferIntent::class);
        $validator = Validator::make($request->query(), [
            'search' => 'nullable|string|max:200',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1|max:2147483647',
            'status' => ['nullable', Rule::in([
                'open', 'all', WarehouseTransferTask::PENDING, WarehouseTransferTask::IN_PROGRESS,
                WarehouseTransferTask::PICKED, WarehouseTransferTask::CANCELLED,
            ])],
            'warehouse_id' => 'nullable|integer|min:1',
        ] + ListingSort::rules(self::SORTS), [
            'search.*' => 'A keresés legfeljebb 200 karakteres szöveg lehet.',
            'sort_by.*' => 'Válassz érvényes rendezési oszlopot.',
            'sort_dir.*' => 'Válassz érvényes rendezési irányt.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti találatszám adható meg.',
            'page.*' => 'Adj meg érvényes oldalszámot.',
            'status.*' => 'Válassz érvényes feladatállapotot.',
            'warehouse_id.*' => 'Válassz érvényes forrásraktárat.',
        ] + ListingSort::messages());
        $suggestions = ListingSuggestions::requested($request);
        if ($suggestions) $validator->validate();
        $filterErrors = $validator->errors();
        $filters = ListingSort::normalize(array_diff_key(
            $request->only(array_keys($validator->getRules())), array_flip($filterErrors->keys())
        ), self::SORTS, 'id');
        if ($suggestions && !ListingSuggestions::hasQuery($filters)) return ListingSuggestions::response();
        $query = $policy->visiblePickingTasks(WarehouseTransferTask::query(), $request->user())
            ->with(['intent.product:product_id,product_name,sku',
                'intent.fromWarehouse:warehouse_id,warehouse_name', 'intent.toWarehouse:warehouse_id,warehouse_name']);

        $status = $filters['status'] ?? 'open';
        if ($status === 'open') {
            $query->whereIn('status', WarehouseTransferTask::OPEN_STATUSES);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $query->whereHas('intent', function (Builder $intent) use ($filters) {
            if (!empty($filters['warehouse_id'])) $intent->where('from_warehouse_id', (int) $filters['warehouse_id']);
            $words = preg_split('/\s+/u', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
                $intent->where(fn (Builder $match) => $match->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->where(fn (Builder $fields) => $fields->whereRaw("product_name LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern])))
                    ->orWhereHas('fromWarehouse', fn (Builder $warehouse) => $warehouse->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('toWarehouse', fn (Builder $warehouse) => $warehouse->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern])));
            }
        });

        if ($suggestions) {
            return ListingSuggestions::response($this->orderedTasks($query, $filters)->limit(9)->get(), fn (WarehouseTransferTask $task) => [
                'key' => 'picking-'.$task->id,
                'value' => $task->intent->reference,
                'name' => $task->intent->product->product_name,
                'detail' => $task->intent->reference.' · '.$task->intent->product->sku.' · '.$task->intent->fromWarehouse->warehouse_name.' → '.$task->intent->toWarehouse->warehouse_name,
            ]);
        }

        return response()->view('warehouse-picking.index', [
            'tasks' => $this->orderedTasks($query, $filters)->paginate($filters['per_page'] ?? 25, page: $filters['page'] ?? 1)->appends($filters),
            'warehouses' => $policy->pickingWarehouses(Warehouse::query(), $request->user())
                ->orderBy('warehouse_name')->get(['warehouse_id', 'warehouse_name']),
            'filters' => $filters,
            'filterErrors' => $filterErrors,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function orderedTasks(Builder $query, array $filters): Builder
    {
        $levels = ListingSort::levels($filters, self::SORTS, 'id');
        foreach ($levels as ['field' => $sort, 'direction' => $direction]) {
            $column = match ($sort) {
                'reference' => TransferIntent::select('reference')->whereColumn('transfer_intents.id', 'warehouse_transfer_tasks.transfer_intent_id'),
                'product_name' => Product::select('product_name')->join('transfer_intents', 'transfer_intents.product_id', '=', 'products.product_id')
                    ->whereColumn('transfer_intents.id', 'warehouse_transfer_tasks.transfer_intent_id'),
                'from_warehouse', 'to_warehouse' => Warehouse::select('warehouse_name')
                    ->join('transfer_intents', 'transfer_intents.'.($sort === 'from_warehouse' ? 'from' : 'to').'_warehouse_id', '=', 'warehouses.warehouse_id')
                    ->whereColumn('transfer_intents.id', 'warehouse_transfer_tasks.transfer_intent_id'),
                'remaining_quantity' => DB::raw('warehouse_transfer_tasks.expected_quantity - warehouse_transfer_tasks.staged_quantity'),
                'priority' => TransferIntent::selectRaw("CASE priority WHEN 'critical' THEN 2 WHEN 'urgent' THEN 1 ELSE 0 END")
                    ->whereColumn('transfer_intents.id', 'warehouse_transfer_tasks.transfer_intent_id'),
                // Workflow order: pending, in progress, picked, cancelled.
                'status' => DB::raw("CASE warehouse_transfer_tasks.status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'picked' THEN 2 ELSE 3 END"),
                default => 'warehouse_transfer_tasks.id',
            };
            $query->orderBy($column, $direction);
        }

        return $query->orderBy('warehouse_transfer_tasks.id', $levels[0]['direction']);
    }

    public function show(Request $request, TransferIntent $transferIntent, WarehouseTransferTaskService $service): Response
    {
        $taskId = WarehouseTransferTaskController::selectedTaskId($request);
        $snapshot = $service->status($request->user(), $transferIntent, $taskId);
        abort_if($snapshot['task'] === null, 404, 'Az igényhez még nem készült kitárolási feladat.');
        $intent = $transferIntent->load([
            'product:product_id,product_name,sku',
            'fromWarehouse:warehouse_id,warehouse_name',
            'toWarehouse:warehouse_id,warehouse_name',
        ]);

        return response()->view('warehouse-picking.show', ['intent' => $intent, 'snapshot' => $snapshot, 'task' => $snapshot['task']])
            ->header('Cache-Control', 'private, no-store');
    }
}
