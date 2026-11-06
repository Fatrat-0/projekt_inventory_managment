<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use App\Models\{Product, Warehouse};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['warehouse_id' => 'nullable|integer|exists:warehouses,warehouse_id']);
        $warehouse = isset($data['warehouse_id']) ? Warehouse::findOrFail($data['warehouse_id']) : null;
        $stocks = Stock::with(['product', 'warehouse'])->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->warehouse_id))->orderBy('id')->get();
        return view('stock.index', compact('stocks', 'warehouse'));
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
        return redirect()->route('stock.index', ['warehouse_id' => $stock->warehouse_id])->with('success', 'A helyi minimum frissítve. A készlet mennyisége nem változott.');
    }
}
