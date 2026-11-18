<?php

namespace App\Http\Controllers;

use App\Models\ShipmentReceiptItem;
use App\Services\{ShipmentReceiptPutawayReader, ShipmentReceiptPutawayService};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Validator};

class ShipmentReceiptPutawayController extends Controller
{
    public function show(Request $request, ShipmentReceiptItem $item, ShipmentReceiptPutawayReader $reader): JsonResponse
    {
        return $this->response(DB::transaction(fn () => $reader->show($request->user(), $item)));
    }

    public function history(Request $request, ShipmentReceiptItem $item, ShipmentReceiptPutawayReader $reader): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'page' => 'sometimes|integer|min:1|max:2147483647',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ], [
            'page.*' => 'Adj meg érvényes oldalszámot.',
            'per_page.*' => 'Oldalanként 1 és 100 közötti találatszám adható meg.',
        ])->validate();

        return $this->response(DB::transaction(fn () => $reader->history($request->user(), $item, $filters)));
    }

    public function recommendations(Request $request, ShipmentReceiptItem $item,
        ShipmentReceiptPutawayService $service, ShipmentReceiptPutawayReader $reader): JsonResponse
    {
        return $this->response(DB::transaction(function () use ($request, $item, $service, $reader) {
            $data = $service->recommendations($request->user(), $item, $request->all());
            $data['recommendations'] = $reader->withLocationMetadata($data['recommendations'], $data['warehouse_id'], $item);
            $data['rejected'] = $reader->withLocationMetadata($data['rejected'], $data['warehouse_id'], $item);

            return $data;
        }));
    }

    public function locations(Request $request, ShipmentReceiptItem $item,
        ShipmentReceiptPutawayService $service, ShipmentReceiptPutawayReader $reader): JsonResponse
    {
        return $this->response(DB::transaction(function () use ($request, $item, $service, $reader) {
            $data = $service->locations($request->user(), $item, $request->all());
            $data['locations'] = $reader->withLocationMetadata($data['locations'], $data['warehouse_id'], $item);

            return $data;
        }));
    }

    public function store(Request $request, ShipmentReceiptItem $item,
        ShipmentReceiptPutawayService $service, ShipmentReceiptPutawayReader $reader): JsonResponse
    {
        $actor = $request->user();
        $result = $service->putaway($actor, $item, $request->all());

        return $this->response($result + [
            'location_view_url' => $reader->placedLocationViewUrl($actor, $item, $result['placement']['location_id']),
        ], 201);
    }

    private function response(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
