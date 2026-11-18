<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShipmentRequest;
use App\Models\{Shipment, ShipmentStop};
use App\Services\ShipmentService;
use App\Support\{LoadingPlanPdfOptions, ShipmentListContext, ShipmentLoadingPlanPdf};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};

class ShipmentController extends Controller
{
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
        $this->requireLegacyView($shipment);
        $shipment->load(['origin', 'user', 'stops.warehouse', 'stops.receiver', 'stops.items.product', 'stops.items.destinationLocation']);
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
        [$loadingPlan, $options] = DB::transaction(function () use ($request, $shipment) {
            $current = Shipment::findOrFail($shipment->id);
            if ($current->isTransferIntentPlan()) Gate::authorize('viewPlan', $current);

            $options = LoadingPlanPdfOptions::fromRequest($request);

            return [ShipmentLoadingPlanPdf::forShipment($current), $options];
        });
        $pdf = Pdf::loadView('pdf.loading-plan', compact('shipment', 'loadingPlan', 'options'))->setPaper('a4', $options['orientation']);
        $filename = $loadingPlan['shipment_number'].'-rakodasi-terv.pdf';
        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }
    private function redirectToShow(Request $request, Shipment $shipment)
    {
        $context = ShipmentListContext::token($request->input('context', ''));
        return redirect()->route('shipments.show', ['shipment' => $shipment] + ($context !== '' ? ['context' => $context] : []));
    }

    private function requireLegacyView(Shipment $shipment): void
    {
        abort_if($shipment->isTransferIntentPlan(), 404, 'Ez a fuvar a fuvartervezési végpontokon érhető el.');
    }
}
