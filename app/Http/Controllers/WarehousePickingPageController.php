<?php

namespace App\Http\Controllers;

use App\Models\{TransferIntent, Warehouse, WarehouseTransferTask};
use App\Policies\TransferIntentPolicy;
use App\Services\WarehouseTransferTaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{Request, Response};
use Illuminate\Support\Facades\{Gate, Validator};
use Illuminate\Validation\Rule;

class WarehousePickingPageController extends Controller
{
    public function index(Request $request, TransferIntentPolicy $policy): Response
    {
        Gate::authorize('viewPickingTaskList', TransferIntent::class);
        $validator = Validator::make($request->query(), [
            'search' => 'nullable|string|max:200',
            'status' => ['nullable', Rule::in([
                WarehouseTransferTask::PENDING, WarehouseTransferTask::IN_PROGRESS,
                WarehouseTransferTask::PICKED, WarehouseTransferTask::CANCELLED,
            ])],
            'warehouse_id' => 'nullable|integer|min:1',
        ], [
            'search.*' => 'A keresés legfeljebb 200 karakteres szöveg lehet.',
            'status.*' => 'Válassz érvényes feladatállapotot.',
            'warehouse_id.*' => 'Válassz érvényes forrásraktárat.',
        ]);
        $filterErrors = $validator->errors();
        $filters = array_diff_key($request->only(['search', 'status', 'warehouse_id']), array_flip($filterErrors->keys()));
        $query = $policy->visiblePickingTasks(WarehouseTransferTask::query(), $request->user())
            ->with(['intent.product:product_id,product_name,sku',
                'intent.fromWarehouse:warehouse_id,warehouse_name', 'intent.toWarehouse:warehouse_id,warehouse_name']);

        if (!empty($filters['status'])) $query->where('status', $filters['status']);
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

        return response()->view('warehouse-picking.index', [
            'tasks' => $query->latest('id')->paginate(25)->appends($filters),
            'warehouses' => $policy->pickingWarehouses(Warehouse::query(), $request->user())
                ->orderBy('warehouse_name')->get(['warehouse_id', 'warehouse_name']),
            'filters' => $filters,
            'filterErrors' => $filterErrors,
        ])->header('Cache-Control', 'private, no-store');
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
