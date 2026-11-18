<?php

namespace App\Http\Controllers;

use App\Models\{Shipment, ShipmentStop};
use App\Services\{ShipmentReceiving, ShipmentReceivingReader};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Validator};

class ShipmentReceivingController extends Controller
{
    public function index(Request $request, ShipmentReceivingReader $reader): JsonResponse
    {
        $filters = ShipmentReceivingReader::listFilters($request->query())->validate();

        return $this->response(DB::transaction(fn () => $reader->openStops($request->user(), $filters)));
    }

    public function show(Request $request, Shipment $shipment, ShipmentStop $stop, ShipmentReceivingReader $reader): JsonResponse
    {
        $filters = Validator::make($request->query(), ['remaining_only' => 'sometimes|boolean'], [
            'remaining_only.boolean' => 'Az átvehető tételek szűréséhez adj meg 0 vagy 1 értéket.',
        ])->validate();

        return $this->response(DB::transaction(fn () => $reader->show($request->user(), $shipment, $stop, (bool) ($filters['remaining_only'] ?? false))));
    }

    public function history(Request $request, Shipment $shipment, ShipmentStop $stop, ShipmentReceivingReader $reader): JsonResponse
    {
        $filters = $this->filters($request);

        return $this->response(DB::transaction(fn () => $reader->history($request->user(), $shipment, $stop, $filters)));
    }

    public function pendingPutaway(Request $request, ShipmentReceivingReader $reader): JsonResponse
    {
        $filters = ShipmentReceivingReader::listFilters($request->query(), putaway: true)->validate();

        return $this->response(DB::transaction(fn () => $reader->pendingPutaway($request->user(), $filters)));
    }

    public function store(Request $request, Shipment $shipment, ShipmentStop $stop, ShipmentReceiving $receiving): JsonResponse
    {
        return $this->response($receiving->receive($request->user(), $shipment, $stop, $request->all()), 201);
    }

    private function filters(Request $request, array $rules = []): array
    {
        return Validator::make($request->query(), $rules + [
            'page' => 'sometimes|integer|min:1|max:2147483647',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ], [
            'warehouse_id.*' => 'Adj meg érvényes raktárazonosítót.',
            'shipment_id.*' => 'Adj meg érvényes fuvarazonosítót.',
            'page.*' => 'Adj meg érvényes oldalszámot.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti találatszám adható meg.',
        ])->validate();
    }

    private function response(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
