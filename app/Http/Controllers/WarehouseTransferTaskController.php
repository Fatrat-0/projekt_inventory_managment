<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransferIntentDecisionRequest;
use App\Models\TransferIntent;
use App\Services\WarehouseTransferTaskService;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Validator;

class WarehouseTransferTaskController extends Controller
{
    public function show(Request $request, TransferIntent $transferIntent, WarehouseTransferTaskService $service): JsonResponse
    {
        return response()->json($service->status($request->user(), $transferIntent, self::selectedTaskId($request)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(TransferIntentDecisionRequest $request, TransferIntent $transferIntent, WarehouseTransferTaskService $service): JsonResponse
    {
        return response()->json($service->create($request->user(), $transferIntent, $request->validated()), 201);
    }

    public function recommendations(Request $request, TransferIntent $transferIntent, WarehouseTransferTaskService $service): JsonResponse
    {
        $data = Validator::make($request->query(), [
            'quantity' => 'bail|sometimes|required|integer|min:1|max:'.TransferIntent::MAX_QUANTITY,
        ], [
            'quantity.*' => 'Az ajánláshoz pozitív egész darabszámot adj meg, legfeljebb 2 147 483 647-et.',
        ])->validate();

        return response()->json($service->recommendations(
            $request->user(), $transferIntent, isset($data['quantity']) ? (int) $data['quantity'] : null, self::selectedTaskId($request),
        ))->header('Cache-Control', 'private, no-store');
    }

    public static function selectedTaskId(Request $request): ?int
    {
        if (!$request->query->has('task')) return null;

        $data = Validator::make($request->query(), ['task' => 'required|integer|min:1'],
            ['task.*' => 'Adj meg érvényes kitárolási feladatazonosítót.'])->validate();

        return (int) $data['task'];
    }

    public function pick(Request $request, TransferIntent $transferIntent, WarehouseTransferTaskService $service): JsonResponse
    {
        $data = $request->only(['operation_key', 'version', 'reason', 'quantity', 'source_allocations', 'unassigned_confirmation']);

        return response()->json($service->pick($request->user(), $transferIntent, $data));
    }
}
