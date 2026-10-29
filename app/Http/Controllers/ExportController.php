<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\Warehouse;
use App\Queries\WarehouseStockListing;
use App\Support\WarehouseInventoryPdfOptions;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportController extends Controller
{
    public function warehouseInventoryPdf(Request $request, Warehouse $warehouse)
    {
        $options = WarehouseInventoryPdfOptions::fromRequest($request);
        $listing = new WarehouseStockListing($request, $warehouse);
        $stocks = $listing->query()->with('product.category')->get();
        $filterInfo = $listing->filterInfo(!$options['blind_mode'], $options['show_sku']);
        $partial = $listing->filters['search'] !== '' || $listing->filters['product_id'] !== '' || $listing->filters['stock'] !== 'all';
        $exportedAt = now();
        $pdf = Pdf::loadView('pdf.warehouse-inventory', compact('warehouse', 'stocks', 'options', 'filterInfo', 'partial', 'exportedAt'))
            ->setPaper('a4', $options['orientation']);
        $filename = 'raktar_'.$warehouse->warehouse_id.'_leltariv_'.$exportedAt->format('Y-m-d').'.pdf';
        return $request->boolean('preview') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    // 1. HIVATALOS LELTÁRÍV PDF (Szinkronizálva az intelligens szűrőkkel és rendezéssel)
    public function inventoryPdf(Request $request)
    {
        $request->validate(['orientation' => ['nullable', 'in:portrait,landscape']]);
        $listing = new \App\Queries\ProductListing($request);
        $products = $listing->query()->get();
        $filterInfo = $listing->filterInfo();

        $options = [
            'show_filters'    => $request->boolean('show_filters'),
            'show_sku'        => $request->boolean('show_sku', true),
            'show_category'   => $request->boolean('show_category', true),
            'show_warehouses' => $request->boolean('show_warehouses', true),
            'show_prices'     => $request->boolean('show_prices'),
            'show_count_col'  => $request->boolean('show_count_col'),
            'blind_mode'      => $request->boolean('blind_mode'),
            'show_signatures' => $request->boolean('show_signatures'),
            'orientation'     => $request->input('orientation', 'portrait'),
        ];

        $pdf = Pdf::loadView('pdf.inventory', compact('products', 'filterInfo', 'options'))
                  ->setPaper('a4', $options['orientation']);

        if ($request->boolean('preview')) {
            return $pdf->stream('leltariv_elonezet.pdf');
        }

        return $pdf->download('leltariv_' . date('Y-m-d') . '.pdf');
    }

    public function movementsPdf(Request $request)
    {
        $request->validate(['orientation' => ['nullable', 'in:portrait,landscape']]);
        $listing = new \App\Queries\MovementListing($request);
        $movements = $listing->query()->get();
        $filterInfo = array_values($listing->labels());

        $options = [
            'type_filter'     => $listing->filters['type'] ?? 'all',
            'show_warehouse'  => $request->boolean('show_warehouse', true),
            'show_reference'  => $request->boolean('show_reference', true),
            'show_user'       => $request->boolean('show_user', true),
            'show_summary'    => $request->boolean('show_summary', true),
            'show_signatures' => $request->boolean('show_signatures', true),
            'orientation'     => $request->input('orientation', 'portrait'),
        ];

        $pdf = Pdf::loadView('pdf.movements', compact('movements', 'options', 'filterInfo'))
                  ->setPaper('a4', $options['orientation']);

        if ($request->boolean('preview')) {
            return $pdf->stream('mozgasnaplo_elonezet.pdf');
        }

        return $pdf->download('keszletmozgasok_' . date('Y-m-d') . '.pdf');
    }

    // 3. RAKTÁRKÖZI SZÁLLÍTÓLEVÉL (EGYEDI BIZONYLAT) PDF
    public function transferPdf(Request $request, InventoryTransfer $transfer)
    {
        $transfer->load(['product.category', 'fromWarehouse', 'toWarehouse']);

        $pdf = Pdf::loadView('pdf.transfer', compact('transfer'))
                  ->setPaper('a4', 'portrait');

        if ($request->boolean('preview')) {
            return $pdf->stream('szallitolevel_' . $transfer->getKey() . '.pdf');
        }

        return $pdf->download('szallitolevel_' . $transfer->getKey() . '.pdf');
    }
}
