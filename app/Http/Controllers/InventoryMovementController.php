<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Partner;
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
            'partners' => Partner::orderBy('name')->orderBy('id')->get(),
            'totalIn' => (int) ($summary['in'] ?? 0), 'totalOut' => (int) ($summary['out'] ?? 0),
        ]);
    }

    public function create(Request $request)
    {
        $prefill = $request->validate(['product_id' => 'nullable|integer|exists:products,product_id', 'warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id']);
        $products = Product::orderBy('product_name')->get();
        $warehouses = Warehouse::orderBy('warehouse_name')->get();
        $partners = Partner::whereNull('archived_at')->orderBy('name')->orderBy('id')->get();
        $context = $request->old('context', $request->query('context', ''));
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        $returnUrl = \App\Support\MovementListContext::url($context);
        $requestKey = $request->old('request_key');
        if (!is_string($requestKey) || !\Illuminate\Support\Str::isUuid($requestKey)) $requestKey = (string) \Illuminate\Support\Str::uuid();
        return view('movements.create', compact('products', 'warehouses', 'partners', 'context', 'returnUrl', 'requestKey', 'prefill'));
    }

    public function show(Request $request, InventoryMovement $movement)
    {
        $movement->load(['product', 'warehouse', 'user', 'partner', 'returnOf', 'qualityAction.user'])->loadSum('returns', 'quantity');
        $returned = (int)$movement->returns_sum_quantity;
        $remaining = max(0, $movement->quantity - $returned);
        $returnHistory = $movement->returns()->with('user')->orderByDesc('id')->paginate(10, ['*'], 'returns_page')->withQueryString();
        $context = $request->query('context');
        $context = is_string($context) && strlen($context) <= 4096 ? $context : '';
        if ($returnHistory->currentPage() > $returnHistory->lastPage()) {
            $params = ['movement' => $movement];
            if ($context !== '') $params['context'] = $context;
            if ($returnHistory->lastPage() > 1) $params['returns_page'] = $returnHistory->lastPage();
            return redirect()->route('movements.show', $params);
        }
        $destination = \App\Support\MovementListContext::destination($request->query('context'));
        $returnUrl = $destination['url'];
        $returnLabel = $destination['label'];
        $qualityHistory = $movement->qualityActions()->with(['user', 'movement'])->orderByDesc('id')->paginate(10, ['*'], 'quality_page')->withQueryString();
        if ($qualityHistory->currentPage() > $qualityHistory->lastPage()) {
            $params = ['movement' => $movement];
            if ($context !== '') $params['context'] = $context;
            if ($qualityHistory->lastPage() > 1) $params['quality_page'] = $qualityHistory->lastPage();
            return redirect()->route('movements.show', $params);
        }
        $qualityTotals = $movement->qualityActions()->toBase()->selectRaw("COALESCE(SUM(CASE WHEN action = 'release' THEN quantity ELSE 0 END), 0) AS released, COALESCE(SUM(CASE WHEN action = 'scrap' THEN quantity ELSE 0 END), 0) AS scrapped, COALESCE(SUM(quantity), 0) AS processed")->first();
        $qualityReleased = (int)$qualityTotals->released;
        $qualityScrapped = (int)$qualityTotals->scrapped;
        $qualityRemaining = max(0, $movement->quarantined_quantity - (int)$qualityTotals->processed);
        $qualityKey = $request->old('request_key');
        if (!is_string($qualityKey) || !\Illuminate\Support\Str::isUuid($qualityKey)) $qualityKey = (string)\Illuminate\Support\Str::uuid();
        return view('movements.show', compact('movement', 'returnUrl', 'returnLabel', 'returned', 'remaining', 'returnHistory', 'context', 'qualityHistory', 'qualityRemaining', 'qualityReleased', 'qualityScrapped', 'qualityKey'));
    }

    public function stock(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,product_id',
            'warehouse_id' => 'required|integer|exists:warehouses,warehouse_id',
        ]);
        $stock = \App\Models\Stock::where('product_id', $data['product_id'])->where('warehouse_id', $data['warehouse_id'])
            ->withSum('placements as assigned_quantity', 'quantity')->first();
        $balance = ['quantity' => $stock?->available_quantity ?? 0, 'physical_quantity' => $stock?->quantity ?? 0,
            'quarantined_quantity' => $stock?->quarantined_quantity ?? 0];
        if ($stock?->assigned_quantity) $balance['assigned_quantity'] = (int) $stock->assigned_quantity;

        return response()->json($balance)->header('Cache-Control', 'no-store');
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
            'partner_id' => 'nullable|integer|exists:partners,id',
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
