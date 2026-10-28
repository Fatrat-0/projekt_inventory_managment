<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShipmentRequest;
use App\Models\{Product, Shipment, ShipmentStop, Warehouse};
use App\Services\ShipmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShipmentController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:200', 'status' => ['nullable', Rule::in(array_keys(Shipment::STATUSES))], 'page' => 'nullable|integer|min:1']);
        $query = Shipment::with(['origin', 'stops.warehouse'])->withCount('stops');
        if ($filters['status'] ?? null) $query->where('status', $filters['status']);
        foreach (preg_split('/\s+/u', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(fn($q) => $q->whereRaw("reference LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("vehicle_label LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('origin', fn($w) => $w->whereRaw("warehouse_name LIKE ? ESCAPE '!'", [$pattern])));
        }
        return view('shipments.index', ['shipments' => $query->latest('id')->paginate(15)->withQueryString(), 'filters' => $filters]);
    }
    public function create() { return $this->form(); }
    public function edit(Shipment $shipment) { abort_unless($shipment->status === 'draft', 409, 'Csak tervezet szerkeszthető.'); return $this->form($shipment); }
    private function form(?Shipment $shipment = null)
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
        return view('shipments.form', ['shipment' => $shipment, 'values' => $values, 'stops' => $stops,
            'products' => Product::orderBy('product_name')->get(), 'warehouses' => Warehouse::orderBy('warehouse_name')->get()]);
    }
    public function store(ShipmentRequest $request, ShipmentService $service)
    {
        $shipment = $service->save($request->validated(), $request->user()->id);
        return redirect()->route('shipments.show', $shipment)->with('success', 'A tervezet elkészült. A készlet még nem változott.');
    }
    public function update(ShipmentRequest $request, Shipment $shipment, ShipmentService $service)
    {
        $service->save($request->validated(), $request->user()->id, $shipment);
        return redirect()->route('shipments.show', $shipment)->with('success', 'A rakodási terv frissült.');
    }
    public function show(Shipment $shipment)
    {
        $shipment->load(['origin', 'user', 'stops.warehouse', 'stops.receiver', 'stops.items.product', 'stops.items.transfer']);
        return view('shipments.show', compact('shipment'));
    }
    public function start(Request $request, Shipment $shipment, ShipmentService $service)
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $service->start($shipment, $request->user()->id, (int)$data['version']);
        return redirect()->route('shipments.show', $shipment)->with('success', 'A szállítmány úton van. A forráskészlet levonása megtörtént.');
    }
    public function receive(Request $request, Shipment $shipment, ShipmentStop $stop, ShipmentService $service)
    {
        $service->receive($shipment, $stop, $request->user()->id);
        return redirect()->route('shipments.show', $shipment)->with('success', 'A megálló átvétele rögzítve.');
    }
    public function cancel(Shipment $shipment, ShipmentService $service)
    {
        $service->cancel($shipment);
        return redirect()->route('shipments.show', $shipment)->with('success', 'A szállítmány visszavonva. A még át nem vett tételek visszakerültek a forrásraktárba.');
    }
    public function pdf(Shipment $shipment)
    {
        $shipment->load(['origin', 'stops.warehouse', 'stops.items.product']);
        return Pdf::loadView('pdf.loading-plan', compact('shipment'))->setPaper('a4', 'portrait')->download($shipment->shipment_number.'-rakodasi-terv.pdf');
    }
}
