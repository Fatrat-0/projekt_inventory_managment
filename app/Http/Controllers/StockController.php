<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Models\{Product, Warehouse};
use App\Models\Category;
use App\Queries\StockListing;
use App\Support\{CategoryTree, StockListContext};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function index(Request $request)
    {
        try { $listing = new StockListing($request); }
        catch (ValidationException $exception) {
            if ($request->expectsJson()) throw $exception;
            return redirect()->route('stock.index')->withErrors(['filters' => 'A készletszűrők már nem érvényesek. A feltételeket visszaállítottuk.']);
        }
        $filters = $listing->filters;
        $stocks = $listing->query()->paginate($filters['per_page'])->appends($filters)->onEachSide(1);
        if ($stocks->currentPage() > $stocks->lastPage()) {
            $request->session()->keep(['errors', 'success']);
            return redirect()->route('stock.index', $filters + ($stocks->lastPage() > 1 ? ['page' => $stocks->lastPage()] : []));
        }
        $categories = Category::orderBy('category_name')->get(['category_id', 'category_name', 'parent_id']);
        $tree = new CategoryTree($categories);
        return response()->view('stock.index', [
            'stocks' => $stocks, 'filters' => $filters, 'summary' => $listing->summary(), 'filterLabels' => $listing->labels(),
            'suggestions' => $listing->suggestions(), 'warehouses' => Warehouse::orderBy('warehouse_name')->orderBy('warehouse_id')->get(),
            'categoryOptions' => $categories->map(fn ($category) => ['id' => $category->category_id, 'label' => $tree->path($category->category_id)]),
            'listContext' => StockListContext::encode($filters + ['page' => $stocks->currentPage()]),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function minimum(Request $request, Stock $stock)
    {
        $data = $request->validate(['min_quantity' => 'required|integer|min:0|max:2147483647', 'expected_minimum' => 'required|integer|min:0|max:2147483647']);
        DB::transaction(function () use ($stock, $data) {
            Product::whereKey($stock->product_id)->lockForUpdate()->firstOrFail();
            $current = Stock::whereKey($stock->id)->lockForUpdate()->firstOrFail();
            if ($current->min_quantity !== (int)$data['expected_minimum']) throw ValidationException::withMessages(['min_quantity' => 'A helyi minimum közben megváltozott. Frissítsd az oldalt a módosítás előtt.']);
            $current->update(['min_quantity' => (int)$data['min_quantity']]);
        }, 3);
        return redirect()->to(StockListContext::url($request->input('context'), (int)$stock->warehouse_id))->with('success', 'A helyi minimum frissítve. A készlet mennyisége nem változott.');
    }
}
