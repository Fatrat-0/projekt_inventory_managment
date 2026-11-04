<?php

namespace App\Http\Controllers;

use App\Models\{InventoryMovement, Partner, Product, Warehouse};
use App\Queries\ReturnQualityListing;
use App\Support\MovementListContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReturnQualityQueueController extends Controller
{
    public function index(Request $request)
    {
        try {
            $listing = new ReturnQualityListing($request);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) throw $exception;
            return redirect()->route('quality-returns.index')->withErrors(['filters' => 'A keresési feltételek már nem érvényesek. A szűrőket visszaállítottuk.']);
        }
        $filters = $listing->filters;
        $returns = $listing->query()->paginate($filters['per_page'])->withQueryString();
        if ($returns->currentPage() > $returns->lastPage()) {
            $params = $filters;
            if ($returns->lastPage() > 1) $params['page'] = $returns->lastPage();
            return redirect()->route('quality-returns.index', $params);
        }
        $products = Product::whereIn('product_id', InventoryMovement::whereNotNull('return_of_id')->where('type', 'in')
            ->where('quarantined_quantity', '>', 0)->select('product_id'))->orderBy('product_name')->orderBy('product_id')->get();
        $suggestions = $products->map(fn($product) => ['value' => (string)$product->product_id, 'name' => $product->product_name, 'detail' => $product->sku])->values();
        return view('quality-returns.index', [
            'returns' => $returns, 'filters' => $filters, 'summary' => $listing->summary(), 'filterLabels' => $listing->labels(),
            'suggestions' => $suggestions,
            'warehouses' => Warehouse::orderBy('warehouse_name')->orderBy('warehouse_id')->get(), 'partners' => Partner::orderBy('name')->orderBy('id')->get(),
            'listContext' => MovementListContext::encodeQuality($filters + ['page' => $returns->currentPage()]),
        ]);
    }
}
