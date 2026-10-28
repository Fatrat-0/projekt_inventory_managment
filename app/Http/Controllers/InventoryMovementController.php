<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Services\InventoryService;

class InventoryMovementController extends Controller
{
    public function index(Request $request)
    {
        $listing = new \App\Queries\MovementListing($request);
        $filters = $listing->filters;
        $summary = $listing->query()->reorder()->selectRaw("type, SUM(quantity) as total")->groupBy('type')->pluck('total', 'type');
        $context = \App\Support\MovementListContext::encode($filters + ['page' => $request->integer('page', 1)]);
        return view('movements.index', [
            'movements' => $listing->query()->paginate($filters['per_page'])->withQueryString(),
            'filters' => $filters, 'filterLabels' => $listing->labels(),
            'datePresets' => $listing->datePresets(),
            'listContext' => $context,
            'createUrl' => route('movements.create', ['context' => $context]),
            'sortOptions' => \App\Queries\MovementListing::SORTS,
            'products' => Product::orderBy('product_name')->get(),
            'warehouses' => Warehouse::orderBy('warehouse_name')->get(),
            'users' => \App\Models\User::orderBy('name')->get(),
            'totalIn' => (int) ($summary['in'] ?? 0), 'totalOut' => (int) ($summary['out'] ?? 0),
        ]);
    }

    public function create(Request $request)
    {
        $products = Product::orderBy('product_name')->get();
        $warehouses = Warehouse::orderBy('warehouse_name')->get();
        $context = $request->old('context', $request->query('context', ''));
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        $returnUrl = \App\Support\MovementListContext::url($context);
        $requestKey = $request->old('request_key');
        if (!is_string($requestKey) || !\Illuminate\Support\Str::isUuid($requestKey)) $requestKey = (string) \Illuminate\Support\Str::uuid();
        return view('movements.create', compact('products', 'warehouses', 'context', 'returnUrl', 'requestKey'));
    }

    public function show(Request $request, InventoryMovement $movement)
    {
        $movement->load(['product', 'warehouse', 'user']);
        $returnUrl = \App\Support\MovementListContext::url($request->query('context'));
        return view('movements.show', compact('movement', 'returnUrl'));
    }

    public function stock(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,product_id',
            'warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
        ]);
        $quantity = (int) \App\Models\Stock::where('product_id', $data['product_id'])
            ->where('warehouse_id', $data['warehouse_id'])->value('quantity');
        return response()->json(['quantity' => $quantity])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, InventoryService $inventory)
    {
        $validated = $request->validate([
            'request_key' => 'required|uuid',
            'product_id' => 'required|exists:products,product_id',
            'warehouse_id' => 'required|exists:warehouses,warehouse_id',
            'type' => 'required|in:in,out',
            'quantity' => 'required|integer|min:1|max:2147483647',
            'reference' => 'nullable|string|max:255',
        ]);

        // Hozzáadjuk a bejelentkezett felhasználó ID-ját, aki a mozgást rögzítette
        $validated['user_id'] = auth()->id();

        // A készletváltozás és a naplóbejegyzés egyetlen tranzakció.
        $requestKey = $validated['request_key'];
        unset($validated['request_key']);
        $movement = $inventory->recordMovement($validated, $requestKey);

        return redirect()->to(\App\Support\MovementListContext::url($request->input('context')))->with('success', 'Mozgás rögzítve: '.$movement->movement_number.'.');
    }
}
