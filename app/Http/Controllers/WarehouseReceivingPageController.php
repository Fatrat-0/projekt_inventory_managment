<?php

namespace App\Http\Controllers;

use App\Models\{Shipment, ShipmentReceiptItem, ShipmentStop};
use App\Services\{ShipmentReceiptPutawayReader, ShipmentReceivingReader};
use App\Support\{ListingSort, ListingSuggestions};
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Support\Facades\{DB, Gate};

class WarehouseReceivingPageController extends Controller
{
    public function index(Request $request, ShipmentReceivingReader $reader): Response|JsonResponse
    {
        return $this->queue($request, $reader, false);
    }

    public function putawayIndex(Request $request, ShipmentReceivingReader $reader): Response|JsonResponse
    {
        return $this->queue($request, $reader, true);
    }

    public function show(Request $request, Shipment $shipment, ShipmentStop $stop, ShipmentReceivingReader $reader): Response
    {
        $actor = $request->user();
        [$snapshot, $history] = DB::transaction(fn () => [
            $reader->show($actor, $shipment, $stop, false),
            $reader->history($actor, $shipment, $stop, $this->historyFilters($request, 'receipt_history_page')),
        ]);
        $history->setPageName('receipt_history_page');

        return $this->page('warehouse-receiving.show', compact('shipment', 'stop', 'snapshot', 'history'));
    }

    public function putaway(Request $request, ShipmentReceiptItem $item, ShipmentReceiptPutawayReader $reader): Response
    {
        $actor = $request->user();
        [$detail, $history] = DB::transaction(fn () => [
            $reader->show($actor, $item),
            $reader->history($actor, $item, $this->historyFilters($request, 'putaway_history_page')),
        ]);
        $history->setPageName('putaway_history_page');
        $shipmentUrl = route('shipments.show', $detail['shipment']['id']);
        $receivingUrl = route('warehouse-receiving-pages.show', [$detail['shipment']['id'], $detail['item']['shipment_stop_id']]);

        return $this->page('warehouse-putaway.show', compact('detail', 'history', 'shipmentUrl', 'receivingUrl', 'item'));
    }

    private function queue(Request $request, ShipmentReceivingReader $reader, bool $putaway): Response|JsonResponse
    {
        Gate::authorize('viewAnyReceiving', Shipment::class);
        $actor = $request->user();
        $validation = ShipmentReceivingReader::listFilters($request->query(), $putaway);
        $suggestions = ListingSuggestions::requested($request);
        if ($suggestions) $validation->validate();
        $filterErrors = $validation->errors();
        $allowed = array_keys($validation->getRules());
        $filters = array_diff_key($request->only($allowed), array_flip($filterErrors->keys()));
        $filters = ListingSort::normalize($filters,
            $putaway ? ShipmentReceivingReader::PUTAWAY_SORTS : ShipmentReceivingReader::RECEIVING_SORTS,
            $putaway ? 'id' : 'shipment_id');
        if ($suggestions && !ListingSuggestions::hasQuery($filters)) return ListingSuggestions::response();
        if ($suggestions) {
            $searchFilters = ListingSuggestions::filters($filters);
            $rows = DB::transaction(fn () => $putaway ? $reader->pendingPutaway($actor, $searchFilters) : $reader->openStops($actor, $searchFilters));

            return ListingSuggestions::response($rows->items(), $putaway
                ? fn (array $row) => [
                    'key' => 'putaway-'.$row['id'],
                    'value' => $row['sku'],
                    'name' => $row['product_name'],
                    'detail' => $row['sku'].' · '.$row['shipment_number'].' · '.$row['warehouse_name'],
                ]
                : fn (array $row) => [
                    'key' => 'receiving-'.$row['stop']['id'],
                    'value' => $row['shipment']['shipment_number'],
                    'name' => $row['shipment']['shipment_number'].' · '.$row['shipment']['reference'],
                    'detail' => $row['shipment']['origin_warehouse_name'].' → '.$row['stop']['warehouse_name'],
                ]);
        }
        $warehouses = $reader->warehouses($actor)->get(['warehouse_id', 'warehouse_name']);
        $rows = DB::transaction(fn () => $putaway ? $reader->pendingPutaway($actor, $filters) : $reader->openStops($actor, $filters));

        return $this->page($putaway ? 'warehouse-putaway.index' : 'warehouse-receiving.index',
            [$putaway ? 'items' : 'stops' => $rows] + compact('filters', 'filterErrors', 'warehouses'));
    }

    private function historyFilters(Request $request, string $pageName): array
    {
        $page = filter_var($request->query($pageName), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);

        return ['per_page' => 20, 'page' => $page ?: 1];
    }

    private function page(string $view, array $data): Response
    {
        return response()->view($view, $data)->header('Cache-Control', 'private, no-store');
    }
}
