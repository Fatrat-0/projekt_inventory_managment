<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShipmentRequest;
use App\Models\{Product, Shipment, ShipmentStop, Warehouse};
use App\Services\ShipmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use App\Queries\ShipmentListing;
use App\Support\{ListingDates, ShipmentListContext};

class ShipmentController extends Controller
{
    public function index(Request $request)
    {
        $listing = new ShipmentListing($request);
        $filters = $listing->filters;
        return view('shipments.index', [
            'shipments' => $listing->query()->paginate($filters['per_page'])->withQueryString(), 'filters' => $filters,
            'labels' => $listing->labels(), 'datePresets' => ListingDates::presets('shipments.index', $filters),
            'context' => ShipmentListContext::encode($filters + ['page' => $request->integer('page', 1)]),
            'statusCounts' => $listing->query(true)->reorder()->toBase()->select('shipments.status')->selectRaw('COUNT(*) as total')->groupBy('shipments.status')->pluck('total', 'status')->all(),
            'products' => Product::orderBy('product_name')->get(), 'warehouses' => Warehouse::orderBy('warehouse_name')->get(),
        ]);
    }
    public function create(Request $request) { return $this->form($request); }
    public function edit(Request $request, Shipment $shipment) { abort_unless($shipment->status === 'draft', 409, 'Csak tervezet szerkeszthető.'); return $this->form($request, $shipment); }
    private function form(Request $request, ?Shipment $shipment = null)
    {
        $shipment?->load('stops.items');
        $defaults = $shipment?->stops->map(fn($stop) => ['warehouse_id' => $stop->warehouse_id,
            'items' => $stop->items->map(fn($item) => ['product_id' => $item->product_id, 'quantity' => $item->quantity])->all()])->all()
            ?? [['warehouse_id' => '', 'items' => [['product_id' => '', 'quantity' => 1]]]];
        $stops = old('stops', $defaults);
        $stops = is_array($stops) ? array_slice(array_values($stops), 0, 20) : $defaults;
        $scalar = fn($value) => is_scalar($value) ? (string)$value : '';
        $stops = array_map(function ($stop) use ($scalar) {
            $stop = is_array($stop) ? $stop : [];
            $items = is_array($stop['items'] ?? null) ? array_slice(array_values($stop['items']), 0, 50) : [];
            return ['warehouse_id' => $scalar($stop['warehouse_id'] ?? ''), 'items' => array_map(fn($item) => [
                'product_id' => $scalar(is_array($item) ? ($item['product_id'] ?? '') : ''),
                'quantity' => $scalar(is_array($item) ? ($item['quantity'] ?? 1) : 1),
            ], $items) ?: [['product_id' => '', 'quantity' => 1]]];
        }, $stops) ?: $defaults;
        $values = [];
        foreach (['reference', 'vehicle_label', 'note', 'origin_warehouse_id', 'version'] as $key) $values[$key] = $scalar(old($key, $shipment?->$key ?? ''));
        $context = ShipmentListContext::token(old('context', $request->query('context', '')));
        $returnUrl = $shipment ? route('shipments.show', ['shipment' => $shipment] + ($context !== '' ? ['context' => $context] : [])) : ShipmentListContext::url($context);
        return view('shipments.form', ['shipment' => $shipment, 'values' => $values, 'stops' => $stops,
            'context' => $context, 'returnUrl' => $returnUrl,
            'products' => Product::orderBy('product_name')->get(), 'warehouses' => Warehouse::orderBy('warehouse_name')->get()]);
    }
    public function store(ShipmentRequest $request, ShipmentService $service)
    {
        $shipment = $service->save($request->validated(), $request->user()->id);
        return $this->redirectToShow($request, $shipment)->with('success', 'A tervezet elkészült. A készlet még nem változott.');
    }
    public function update(ShipmentRequest $request, Shipment $shipment, ShipmentService $service)
    {
        $service->save($request->validated(), $request->user()->id, $shipment);
        return $this->redirectToShow($request, $shipment)->with('success', 'A rakodási terv frissült.');
    }
    public function show(Request $request, Shipment $shipment)
    {
        $shipment->load(['origin', 'user', 'stops.warehouse', 'stops.receiver', 'stops.items.product']);
        $context = ShipmentListContext::token($request->query('context', ''));
        $returnUrl = ShipmentListContext::url($context);
        return view('shipments.show', compact('shipment', 'context', 'returnUrl'));
    }
    public function start(Request $request, Shipment $shipment, ShipmentService $service)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $service->start($shipment, $request->user()->id, (int)$data['version']);
        return $this->redirectToShow($request, $shipment)->with('success', 'A szállítmány úton van. A forráskészlet levonása megtörtént.');
    }
    public function receive(Request $request, Shipment $shipment, ShipmentStop $stop, ShipmentService $service)
    {
        $service->receive($shipment, $stop, $request->user()->id);
        return $this->redirectToShow($request, $shipment)->with('success', 'A megálló átvétele rögzítve.');
    }
    public function cancel(Request $request, Shipment $shipment, ShipmentService $service)
    {
        $service->cancel($shipment);
        return $this->redirectToShow($request, $shipment)->with('success', 'A szállítmány visszavonva. A még át nem vett tételek visszakerültek a forrásraktárba.');
    }
    public function pdf(Request $request, Shipment $shipment)
    {
        $options = \App\Support\LoadingPlanPdfOptions::fromRequest($request);
        $shipment->load(['origin', 'stops.warehouse', 'stops.items.product']);
        $pdf = Pdf::loadView('pdf.loading-plan', compact('shipment', 'options'))->setPaper('a4', $options['orientation']);
        $filename = $shipment->shipment_number.'-rakodasi-terv.pdf';
        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }
    private function redirectToShow(Request $request, Shipment $shipment)
    {
        $context = ShipmentListContext::token($request->input('context', ''));
        return redirect()->route('shipments.show', ['shipment' => $shipment] + ($context !== '' ? ['context' => $context] : []));
    }
}
