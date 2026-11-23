<?php

namespace App\Http\Controllers;

use App\Models\{Shipment, TransferIntent, Warehouse};
use App\Policies\ShipmentPolicy;
use App\Services\{ShipmentCargoPreparation, ShipmentDispatchBlocked, ShipmentDispatchService, ShipmentPlanningService, ShipmentPlanReader, ShipmentPlanReadiness};
use App\Support\PlanningTime;
use Closure;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Gate, Validator};

class ShipmentPlanningController extends Controller
{
    public function index(Request $request, ShipmentPolicy $policy, ShipmentPlanReader $reader): JsonResponse
    {
        Gate::authorize('viewAnyPlan', Shipment::class);
        $filters = ShipmentPlanReader::listFilters($request->query())->validate();
        $plans = $policy->visiblePlans(Shipment::query(), $request->user());

        return $this->response(DB::transaction(fn () => $reader->plans($plans, $filters)));
    }

    public function eligible(Request $request, ShipmentPlanReader $reader): JsonResponse
    {
        $filters = $this->eligibleFilters($request);
        $origin = (int) $filters['origin_warehouse_id'];
        Gate::authorize('createPlan', [Shipment::class, $origin]);
        abort_unless(Warehouse::whereKey($origin)->exists(), 404, 'Az induló raktár nem található.');
        if (isset($filters['shipment_id'])) {
            $shipment = Shipment::where('planning_mode', Shipment::MODE_TRANSFER_INTENT)
                ->where('origin_warehouse_id', $origin)->findOrFail($filters['shipment_id']);
            Gate::authorize('managePlan', $shipment);
        }

        return $this->response(DB::transaction(fn () => $reader->eligible($origin, $filters)));
    }

    public function store(Request $request, ShipmentPlanningService $service): JsonResponse
    {
        return $this->response($service->create($request->user(), $request->all()), 201);
    }

    public function show(Shipment $shipment, ShipmentPlanReader $reader): JsonResponse
    {
        return $this->read($shipment, fn (Shipment $current) => $reader->detail($current) + [
            'can_manage' => $current->status === 'draft' && Gate::allows('managePlan', $current),
            'can_override_dedicated' => $current->status === 'draft' && Gate::allows('overrideDedicated', $current),
        ]);
    }

    public function updateDeparture(Request $request, Shipment $shipment, ShipmentPlanningService $service): JsonResponse
    {
        return $this->response($service->updateDeparture($request->user(), $shipment, $request->all()));
    }

    public function attach(Request $request, Shipment $shipment, ShipmentPlanningService $service): JsonResponse
    {
        return $this->response($service->attach($request->user(), $shipment, $request->all()));
    }

    public function detach(Request $request, Shipment $shipment, TransferIntent $transferIntent, ShipmentPlanningService $service): JsonResponse
    {
        return $this->response($service->detach($request->user(), $shipment, $transferIntent, $request->all()));
    }

    public function stops(Shipment $shipment, ShipmentPlanReader $reader): JsonResponse
    {
        return $this->read($shipment, fn (Shipment $current) => [
            'version' => $current->version,
            'data' => $reader->stops($current, $current->intentAssignments()->active()->orderBy('id')->get()),
        ]);
    }

    public function reorder(Request $request, Shipment $shipment, ShipmentPlanningService $service): JsonResponse
    {
        return $this->response($service->reorder($request->user(), $shipment, $request->all()));
    }

    public function loadingOrder(Shipment $shipment, ShipmentPlanReader $reader): JsonResponse
    {
        return $this->read($shipment, fn (Shipment $current) => [
            'version' => $current->version,
            'strategy' => 'reverse_stop_order',
            'message' => 'A későbbi megállók áruját rakodják fel először. A sorrend a lerakodást segíti. A rakomány fizikai elhelyezhetősége külön ellenőrzést igényel.',
            'data' => $reader->stops($current, $current->intentAssignments()->active()->orderBy('id')->get(), true),
        ]);
    }

    public function readiness(Shipment $shipment, ShipmentPlanReadiness $readiness): JsonResponse
    {
        return $this->read($shipment, function (Shipment $current) use ($readiness) {
            $result = $readiness->assess($current,
                $current->intentAssignments()->active()->with(['intent', 'stop:id,warehouse_id,received_at'])->orderBy('id')->get());
            $result['minimum_planned_departure_at'] = PlanningTime::localMinimum();
            $result['has_dispatch_permission'] = Gate::allows('dispatchPlan', $current);
            $result['can_dispatch'] = $result['can_dispatch'] && $result['has_dispatch_permission'];
            // Plan viewers have the same origin access as picking task viewers.
            $result['intents'] = $result['intents']->map(fn (array $intent) => $intent + [
                'can_view_picking_task' => $intent['picking_task_id'] !== null,
            ]);

            return $result;
        });
    }

    public function cargo(Shipment $shipment, ShipmentCargoPreparation $cargo): JsonResponse
    {
        return $this->read($shipment, fn (Shipment $current) => $cargo->details($current, $current->intentAssignments()->active()->get()) + [
            'can_manage' => $current->status === 'draft' && Gate::allows('managePlan', $current),
        ]);
    }

    public function selectVehicle(Request $request, Shipment $shipment, ShipmentPlanningService $service): JsonResponse
    {
        return $this->response($service->selectVehicle($request->user(), $shipment, $request->all()));
    }

    public function saveCargoItem(Request $request, Shipment $shipment, string $assignment, ShipmentPlanningService $service): JsonResponse
    {
        $assignmentId = filter_var($assignment, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($assignmentId === false, 404);

        return $this->response($service->saveCargoItem($request->user(), $shipment, $assignmentId, $request->all()));
    }

    public function confirmCargo(Request $request, Shipment $shipment, ShipmentPlanningService $service): JsonResponse
    {
        return $this->response($service->confirmCargo($request->user(), $shipment, $request->all()));
    }

    public function dispatchState(Shipment $shipment, ShipmentDispatchService $service): JsonResponse
    {
        return $this->read($shipment, fn (Shipment $current) => $service->state($current));
    }

    public function dispatch(Request $request, Shipment $shipment, ShipmentPlanningService $service): JsonResponse
    {
        try {
            return $this->response($service->dispatch($request->user(), $shipment, $request->all()));
        } catch (ShipmentDispatchBlocked $blocked) {
            return $this->response(['message' => $blocked->getMessage(), 'blockers' => $blocked->blockers], 409);
        }
    }

    private function read(Shipment $shipment, Closure $output): JsonResponse
    {
        $data = DB::transaction(function () use ($shipment, $output) {
            $current = Shipment::where('planning_mode', Shipment::MODE_TRANSFER_INTENT)->findOrFail($shipment->id);
            Gate::authorize('viewPlan', $current);

            return $output($current);
        });

        return $this->response($data);
    }

    private function response(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }

    private function eligibleFilters(Request $request): array
    {
        $rules = [
            'origin_warehouse_id' => 'required|integer|min:1',
            'destination_warehouse_id' => 'sometimes|integer|min:1',
            'shipment_id' => 'sometimes|integer|min:1',
            'search' => 'nullable|string|max:200',
            'page' => 'sometimes|integer|min:1|max:2147483647',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];

        return Validator::make($request->query(), $rules, [
            'origin_warehouse_id.*' => 'Adj meg érvényes induló raktárat.',
            'destination_warehouse_id.*' => 'Adj meg érvényes célraktárat.',
            'shipment_id.*' => 'Adj meg érvényes fuvarazonosítót.',
            'search.*' => 'A keresés legfeljebb 200 karakteres szöveg lehet.',
            'page.*' => 'Adj meg érvényes oldalszámot.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti találatszám adható meg.',
        ])->validate();
    }
}
