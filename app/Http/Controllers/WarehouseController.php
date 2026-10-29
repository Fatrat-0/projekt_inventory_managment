<?php

namespace App\Http\Controllers;

use App\Http\Requests\WarehouseRequest;
use App\Models\Warehouse;
use App\Queries\WarehouseListing;
use App\Support\WarehouseListContext;
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

    private function form(Request $request, ?Warehouse $warehouse = null)
    {
        $context = WarehouseListContext::token(old('context', $request->query('context', '')));
        $returnUrl = WarehouseListContext::url($context);
        return view('warehouses.form', compact('warehouse', 'context', 'returnUrl'));
    }

    public function store(WarehouseRequest $request)
    {
        Warehouse::create($request->validated());
        return redirect(WarehouseListContext::url($request->input('context')))->with('success', 'Raktár sikeresen hozzáadva.');
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse)
    {
        $warehouse->update($request->validated());
        return redirect(WarehouseListContext::url($request->input('context')))->with('success', 'Raktár adatai frissítve.');
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
}
