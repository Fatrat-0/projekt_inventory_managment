<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $listing = new \App\Queries\ProductListing($request);
        $filters = $listing->filters;
        $products = $listing->query()->paginate($filters['per_page'])->withQueryString();

        return view('products.index', [
            'products' => $products,
            'search' => $filters['search'],
            'categoryId' => $filters['category_id'],
            'warehouseId' => $filters['warehouse_id'],
            'stockStatus' => $filters['stock_status'],
            'perPage' => $filters['per_page'],
            'sortBy' => $filters['sort_by'],
            'sortDir' => $filters['sort_dir'],
            'categoryOptions' => $listing->categoryOptions(),
            'selectedCategoryObj' => $listing->categories->firstWhere('category_id', $filters['category_id']),
            'sortOptions' => \App\Queries\ProductListing::SORTS,
            'warehouses' => Warehouse::orderBy('warehouse_name')->get(),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $categories = Category::orderBy('category_name')->get();
        return view('products.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_name'       => 'required|max:100',
            'sku'                => 'required|unique:products,sku|max:50',
            'price'              => 'required|numeric|min:0',
            'category_id'        => 'required|exists:categories,category_id',
            'min_stock_override' => 'nullable|integer|min:0',
        ]);

        Product::create($validated);

        return redirect()->route('products.index')->with('success', 'Termék sikeresen hozzáadva!');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $product = Product::findOrFail($id);
        $categories = Category::orderBy('category_name')->get();
        return view('products.edit', compact('product', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'product_name'       => 'required|max:100',
            'sku'                => 'required|max:50|unique:products,sku,' . $product->product_id . ',product_id',
            'price'              => 'required|numeric|min:0',
            'category_id'        => 'required|exists:categories,category_id',
            'min_stock_override' => 'nullable|integer|min:0',
        ]);

        $product->update($validated);

        return redirect()->route('products.index')->with('success', 'Termék sikeresen frissítve!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Termék sikeresen törölve!');
    }
}