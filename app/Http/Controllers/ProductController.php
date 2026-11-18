<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\MasterDataEditor;
use App\Services\WarehouseStockAvailability;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            'product_name'       => 'required|string|max:100',
            'sku'                => 'required|string|unique:products,sku|max:50',
            'price'              => 'required|numeric|min:0',
            'category_id'        => 'required|exists:categories,category_id',
            'min_stock_override' => 'nullable|integer|min:0',
        ] + $this->packagingRules(), $this->packagingMessages());
        $packaging = Arr::pull($validated, 'packaging');

        DB::transaction(function () use ($validated, $packaging) {
            $this->savePackaging(Product::create($validated), $packaging);
        });

        return redirect()->route('products.index')->with('success', 'Termék sikeresen hozzáadva!');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $product = Product::with(['category', 'defaultPackaging'])->withSum('stocks', 'quantity')->withSum('stocks', 'quarantined_quantity')
            ->withSum('stocks as total_available', DB::raw(WarehouseStockAvailability::usableStockSql()))
            ->withCount('stocks')->findOrFail($id);
        return response()->view('products.show', compact('product'))->header('Cache-Control', 'no-store, private');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $product = Product::with(['category', 'defaultPackaging'])->findOrFail($id);
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
            'product_name'       => 'required|string|max:100',
            'sku'                => 'required|string|max:50|unique:products,sku,' . $product->product_id . ',product_id',
            'price'              => 'required|numeric|min:0',
            'category_id'        => 'required|exists:categories,category_id',
            'min_stock_override' => 'nullable|integer|min:0',
        ] + $this->packagingRules() + MasterDataEditor::rules(),
            $this->packagingMessages() + MasterDataEditor::messages(), MasterDataEditor::attributes());
        $packaging = Arr::pull($validated, 'packaging');
        $productData = Arr::except($validated, ['lock_version', 'review_version', 'confirm_edit_conflict']);

        DB::transaction(function () use ($product, $request, $productData, $packaging) {
            $current = MasterDataEditor::update($product, $request, $productData, route('products.edit', $product));
            $this->savePackaging($current, $packaging);
        }, 3);

        return redirect()->route('products.index')->with('success', 'Termék sikeresen frissítve!');
    }

    private function packagingRules(): array
    {
        $physicalFields = 'packaging.gross_weight_kg,packaging.width_cm,packaging.depth_cm,packaging.height_cm,packaging.barcode';
        $dimension = 'nullable|numeric|decimal:0,2|min:0.01|max:10000';

        return [
            'packaging' => 'sometimes|array:name,pieces_per_package,gross_weight_kg,width_cm,depth_cm,height_cm,barcode',
            'packaging.name' => 'required_with:packaging.pieces_per_package,'.$physicalFields.'|nullable|string|max:100',
            'packaging.pieces_per_package' => 'required_with:packaging.name,'.$physicalFields.'|nullable|integer|min:1|max:2147483647',
            'packaging.gross_weight_kg' => 'nullable|numeric|decimal:0,3|min:0.001|max:1000000',
            'packaging.width_cm' => $dimension,
            'packaging.depth_cm' => $dimension,
            'packaging.height_cm' => $dimension,
            'packaging.barcode' => 'nullable|string|max:100',
        ];
    }

    private function packagingMessages(): array
    {
        $dimension = 'Adj meg 0,01 és 10 000 cm közötti méretet, legfeljebb két tizedesjeggyel.';

        return [
            'packaging.array' => 'Érvénytelen csomagolási adatok.',
            'packaging.name.required_with' => 'Add meg a csomagolás nevét.',
            'packaging.name.*' => 'A csomagolás neve legfeljebb 100 karakterből állhat.',
            'packaging.pieces_per_package.required_with' => 'Add meg a csomagolásonkénti darabszámot.',
            'packaging.pieces_per_package.*' => 'A csomagolásonkénti darabszám 1 és 2 147 483 647 közötti egész szám lehet.',
            'packaging.gross_weight_kg.*' => 'Adj meg 0,001 és 1 000 000 kg közötti bruttó tömeget, legfeljebb három tizedesjeggyel.',
            'packaging.width_cm.*' => $dimension,
            'packaging.depth_cm.*' => $dimension,
            'packaging.height_cm.*' => $dimension,
            'packaging.barcode.*' => 'A vonalkód legfeljebb 100 karakterből állhat.',
        ];
    }

    private function savePackaging(Product $product, ?array $packaging): void
    {
        if ($packaging === null) return;

        $relation = $product->defaultPackaging();
        $current = $relation->lockForUpdate()->first();
        if (!array_filter($packaging, static fn ($value) => $value !== null)) {
            $current?->delete();
        } elseif ($current) {
            $current->update($packaging);
        } else {
            $relation->create($packaging);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        try {
            $product->delete();
        } catch (QueryException $e) {
            if (!in_array($e->errorInfo[0] ?? null, ['23000', '23503'], true)) throw $e;
            throw ValidationException::withMessages(['product' => 'A termék nem törölhető, mert készlet vagy készletkezelési bizonylat kapcsolódik hozzá.']);
        }

        return redirect()->route('products.index')->with('success', 'Termék sikeresen törölve!');
    }
}
