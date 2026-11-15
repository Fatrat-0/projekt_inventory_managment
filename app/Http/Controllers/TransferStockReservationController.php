<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransferIntentDecisionRequest;
use App\Models\TransferIntent;
use App\Services\TransferStockReservationService;
use Illuminate\Http\{JsonResponse, Request};

class TransferStockReservationController extends Controller
{
    public function show(Request $request, TransferIntent $transferIntent, TransferStockReservationService $service): JsonResponse
    {
        return response()->json($service->status($request->user(), $transferIntent))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(TransferIntentDecisionRequest $request, TransferIntent $transferIntent, TransferStockReservationService $service): JsonResponse
    {
        return response()->json($service->reserve($request->user(), $transferIntent, $request->validated()), 201);
    }

    public function release(TransferIntentDecisionRequest $request, TransferIntent $transferIntent, TransferStockReservationService $service): JsonResponse
    {
        return response()->json($service->release($request->user(), $transferIntent, $request->validated()));
    }
}
