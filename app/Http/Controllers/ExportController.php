<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportController extends Controller
{
    // 1. HIVATALOS LELTÁRÍV PDF
    public function inventoryPdf(Request $request)
    {
        $query = Product::with(['category', 'stocks.warehouse']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        $selectedCategory = null;
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
            $selectedCategory = Category::find($request->input('category_id'));
        }

        $products = $query->orderBy('product_name')->get();

        $filterInfo = [];
        if ($selectedCategory) {
            $filterInfo[] = 'Kategória: ' . $selectedCategory->category_name;
        }
        if ($request->filled('search')) {
            $filterInfo[] = 'Keresőszó: "' . $request->input('search') . '"';
        }

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

    // 2. KÉSZLETMOZGÁSOK (FORGALMI NAPLÓ) PDF
    public function movementsPdf(Request $request)
    {
        $query = InventoryMovement::with(['product', 'warehouse', 'user'])->latest();

        // Szűrés mozgás típusára (in = bevételezés, out = kiadás)
        if ($request->filled('type') && in_array($request->type, ['in', 'out'])) {
            $query->where('type', $request->type);
        }

        $movements = $query->get();

        $options = [
            'type_filter'     => $request->input('type', 'all'),
            'show_warehouse'  => $request->boolean('show_warehouse', true),
            'show_reference'  => $request->boolean('show_reference', true),
            'show_user'       => $request->boolean('show_user', true),
            'show_summary'    => $request->boolean('show_summary', true),
            'show_signatures' => $request->boolean('show_signatures', true),
            'orientation'     => $request->input('orientation', 'portrait'),
        ];

        $pdf = Pdf::loadView('pdf.movements', compact('movements', 'options'))
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