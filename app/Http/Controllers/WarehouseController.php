<?php

namespace App\Http\Controllers;

use App\Http\Requests\WarehouseRequest;
use App\Models\Warehouse;
use App\Queries\WarehouseListing;
use App\Queries\WarehouseStockListing;
use App\Support\WarehouseListContext;
use App\Support\WarehouseStockContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        try {
            $listing = new WarehouseListing($request);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) throw $e;
            return redirect()->route('warehouses.index')->withErrors(['filters' => 'A keresési feltételek egy része már nem érvényes. A szűrőket visszaállítottuk.']);
        }
        $filters = $listing->filters;
        $suggestions = Warehouse::orderBy('warehouse_name')->orderBy('warehouse_id')->get(['warehouse_id', 'warehouse_name', 'location']);
        return view('warehouses.index', [
            'warehouses' => $listing->query()->paginate($filters['per_page'])->withQueryString(),
            'filters' => $filters,
            'suggestions' => $suggestions->map(fn($warehouse) => ['value' => (string)$warehouse->warehouse_id, 'name' => $warehouse->warehouse_name, 'detail' => $warehouse->location ?? ''])->values(),
            'selectedWarehouse' => $suggestions->firstWhere('warehouse_id', $filters['warehouse_id']),
            'context' => WarehouseListContext::encode($filters + ['page' => $request->integer('page', 1)]),
        ]);
    }

    public function create(Request $request) { return $this->form($request); }
    public function edit(Request $request, Warehouse $warehouse) { return $this->form($request, $warehouse); }

    public function show(Request $request, Warehouse $warehouse)
    {
        $context = WarehouseListContext::token($request->query('context', ''));
        try {
            $listing = new WarehouseStockListing($request, $warehouse);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) throw $e;
            return redirect($this->detailUrl($warehouse, $context))->withErrors(['filters' => 'A készletszűrés egy része már nem érvényes. A szűrőket visszaállítottuk.']);
        }
        $filters = $listing->filters;
        $stocks = $listing->query()->paginate($filters['per_page'])->withQueryString();
        if ($stocks->currentPage() > $stocks->lastPage()) {
            $params = ['warehouse' => $warehouse] + ($context !== '' ? ['context' => $context] : []) + $filters;
            if ($stocks->lastPage() > 1) $params['page'] = $stocks->lastPage();
            return redirect(route('warehouses.show', $params).'#local-stock');
        }
        $stockTotals = $warehouse->stocks()->selectRaw('COALESCE(SUM(quantity), 0) AS quantity, COUNT(CASE WHEN quantity > 0 THEN 1 END) AS products_count')->first();
        $incomingQuery = $warehouse->incomingTransfers()->where('status', 'pending');
        $outgoingQuery = $warehouse->outgoingTransfers()->where('status', 'pending');
        $transferTotals = fn($query) => (clone $query)->selectRaw('COUNT(*) AS transfer_count, COALESCE(SUM(quantity), 0) AS quantity')->first();
        $preview = fn($query) => (clone $query)->with(['product', 'fromWarehouse', 'toWarehouse', 'shipmentItem.stop.shipment'])->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get();
        $suggestions = $warehouse->stocks()->with('product')->get();
        return view('warehouses.show', [
            'warehouse' => $warehouse, 'context' => $context, 'returnUrl' => WarehouseListContext::url($context),
            'filters' => $filters, 'stocks' => $stocks,
            'detailContext' => WarehouseStockContext::encode($filters + ['page' => $request->integer('page', 1)]),
            'stockTotals' => $stockTotals, 'incomingTotals' => $transferTotals($incomingQuery), 'outgoingTotals' => $transferTotals($outgoingQuery),
            'incoming' => $preview($incomingQuery), 'outgoing' => $preview($outgoingQuery),
            'movements' => $warehouse->movements()->with(['product', 'user'])->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get(),
            'suggestions' => $suggestions->map(fn($stock) => ['value' => (string)$stock->product_id, 'name' => $stock->product->product_name, 'detail' => $stock->product->sku])->values(),
            'selectedProduct' => $suggestions->firstWhere('product_id', $filters['product_id'])?->product,
        ]);
    }

    private function form(Request $request, ?Warehouse $warehouse = null)
    {
        $context = WarehouseListContext::token(old('context', $request->query('context', '')));
        $fromDetails = $warehouse && old('from', $request->query('from', '')) === 'details';
        $detailContext = WarehouseStockContext::token(old('detail_context', $request->query('detail_context', '')));
        $returnUrl = $fromDetails ? WarehouseStockContext::url($warehouse, $detailContext, $context) : WarehouseListContext::url($context);
        return view('warehouses.form', compact('warehouse', 'context', 'returnUrl', 'fromDetails', 'detailContext'));
    }

    public function store(WarehouseRequest $request)
    {
        Warehouse::create($request->validated());
        return redirect(WarehouseListContext::url($request->input('context')))->with('success', 'Raktár sikeresen hozzáadva.');
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse)
    {
        $warehouse->update($request->validated());
        $context = WarehouseListContext::token($request->input('context'));
        $url = $request->input('from') === 'details' ? WarehouseStockContext::url($warehouse, $request->input('detail_context'), $context) : WarehouseListContext::url($context);
        return redirect($url)->with('success', 'Raktár adatai frissítve.');
    }

    public function destroy(Request $request, Warehouse $warehouse)
    {
        $message = 'A raktár nem törölhető, mert készletadat, készletmozgás, átmozgatás vagy szállítmány kapcsolódik hozzá. A nulla mennyiségű készletsorok is megőrzendő adatok.';
        try {
            DB::transaction(function () use ($warehouse, $message) {
                $locked = Warehouse::withUsage()->lockForUpdate()->findOrFail($warehouse->warehouse_id);
                if ($locked->is_in_use) throw ValidationException::withMessages(['warehouse' => $message]);
                $locked->delete();
            }, 3);
        } catch (QueryException $e) {
            // A concurrent insert can attach a record after the usage check.
            // The foreign keys remain the final guard and must not become a 500 error.
            if (!in_array($e->errorInfo[0] ?? null, ['23000', '23503'], true)) throw $e;
            throw ValidationException::withMessages(['warehouse' => $message]);
        }
        return redirect(WarehouseListContext::url($request->input('context'), ['warehouse_id', 'page']))->with('success', 'Raktár törölve.');
    }

    private function detailUrl(Warehouse $warehouse, string $context): string
    {
        return route('warehouses.show', ['warehouse' => $warehouse] + ($context !== '' ? ['context' => $context] : []));
    }
}
