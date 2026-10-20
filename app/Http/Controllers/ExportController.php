<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Category;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportController extends Controller
{
    public function inventoryPdf(Request $request)
    {
        $query = Product::with(['category', 'stocks.warehouse']);

        // 1. Szöveges keresés szűrő
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        // 2. Kategória szűrő
        $selectedCategory = null;
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
            $selectedCategory = Category::find($request->input('category_id'));
        }

        $products = $query->orderBy('product_name')->get();

        // Szűrő információk összeállítása
        $filterInfo = [];
        if ($selectedCategory) {
            $filterInfo[] = 'Kategória: ' . $selectedCategory->category_name;
        }
        if ($request->filled('search')) {
            $filterInfo[] = 'Keresőszó: "' . $request->input('search') . '"';
        }

        // 3. PDF megjelenítési és oszlop opciók kiolvasása
        $options = [
            'show_filters'    => $request->boolean('show_filters'),
            'show_sku'        => $request->boolean('show_sku', true),
            'show_category'   => $request->boolean('show_category', true),
            'show_warehouses' => $request->boolean('show_warehouses', true),
            'show_prices'     => $request->boolean('show_prices'),
            'show_count_col'  => $request->boolean('show_count_col'), // Üres oszlop a kézi számoláshoz
            'blind_mode'      => $request->boolean('blind_mode'),     // Vakleltár (mennyiség elrejtése)
            'show_signatures' => $request->boolean('show_signatures'),
            'orientation'     => $request->input('orientation', 'portrait'), // portrait vagy landscape
        ];

        $pdf = Pdf::loadView('pdf.inventory', compact('products', 'filterInfo', 'options'))
                  ->setPaper('a4', $options['orientation']);

        if ($request->boolean('preview')) {
            return $pdf->stream('leltariv_elonezet.pdf');
        }

        return $pdf->download('leltariv_' . date('Y-m-d') . '.pdf');

        $pdf = Pdf::loadView('pdf.inventory', compact('products', 'filterInfo', 'options'));

        // Ha előnézetet kér a felület, megjelenítjük a böngészőben (stream)
        if ($request->boolean('preview')) {
            return $pdf->stream('leltariv_elonezet.pdf');
        }

        // Különben elindítjuk a fájl letöltését (download)
        return $pdf->download('leltariv_' . date('Y-m-d') . '.pdf');
    }
}