<?php

namespace App\Http\Controllers;

use App\Http\Requests\{StoreTransferIntentRequest, TransferIntentDecisionRequest};
use App\Models\TransferIntent;
use App\Policies\TransferIntentPolicy;
use App\Services\TransferIntentService;
use App\Support\TransferIntentDisplay;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Gate;

class TransferIntentController extends Controller
{
    public function index(Request $request, TransferIntentPolicy $policy): JsonResponse
    {
        Gate::authorize('viewAny', TransferIntent::class);
        $intents = $policy->visibleTo(TransferIntent::query(), $request->user())
            ->orderByDesc('id')->paginate(25);

        return response()->json($intents);
    }

    public function store(StoreTransferIntentRequest $request, TransferIntentService $service): JsonResponse
    {
        return response()->json($service->create($request->user(), $request->validated()), 201);
    }

    public function show(Request $request, TransferIntent $transferIntent, TransferIntentPolicy $policy): JsonResponse
    {
        Gate::authorize('view', $transferIntent);
        if ($request->boolean('workflow')) {
            $workflow = TransferIntentDisplay::workflow($transferIntent, $request->user(), $policy);
            $transferIntent->events->each(fn ($event) => $event->unsetRelation('actor'));

            return response()->json(['data' => $transferIntent, 'workflow' => $workflow])
                ->header('Cache-Control', 'private, no-store');
        }

        $transferIntent->load(['events' => fn ($events) => $events
            ->select(['id', 'transfer_intent_id', 'event_type', 'from_status', 'to_status', 'actor_id', 'occurred_at', 'reason'])
            ->orderBy('occurred_at')->orderBy('id')]);

        return response()->json(['data' => $transferIntent]);
    }

    public function approve(TransferIntentDecisionRequest $request, TransferIntent $transferIntent, TransferIntentService $service): JsonResponse
    {
        return response()->json($service->approve($request->user(), $transferIntent, $request->validated()));
    }

    public function reject(TransferIntentDecisionRequest $request, TransferIntent $transferIntent, TransferIntentService $service): JsonResponse
    {
        return response()->json($service->reject($request->user(), $transferIntent, $request->validated()));
    }

    public function cancel(TransferIntentDecisionRequest $request, TransferIntent $transferIntent, TransferIntentService $service): JsonResponse
    {
        return response()->json($service->cancel($request->user(), $transferIntent, $request->validated()));
    }
}
