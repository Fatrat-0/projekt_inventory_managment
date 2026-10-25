<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class InventoryTransferController extends Controller
{
    public function index()
    {
        $transfers = InventoryTransfer::with(['product', 'fromWarehouse', 'toWarehouse'])->latest()->get();
        return view('transfers.index', compact('transfers'));
    }

    public function create()
    {
        $products = Product::all();
        $warehouses = Warehouse::all();
        return view('transfers.create', compact('products', 'warehouses'));
    }

    public function store(Request $request, \App\Services\InventoryService $inventory)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,product_id',
            'from_warehouse_id' => 'required|exists:warehouses,warehouse_id',
            'to_warehouse_id' => 'required|exists:warehouses,warehouse_id|different:from_warehouse_id',
            'quantity' => 'required|integer|min:1|max:2147483647',
        ]);
        $validated['user_id'] = $request->user()->id;
        $inventory->startTransfer($validated);
        return redirect()->route('transfers.index')->with('success', 'Átmozgatás elindítva!');
    }

    public function complete(InventoryTransfer $transfer, \App\Services\InventoryService $inventory)
    {
        $inventory->finalizeTransfer($transfer, true);
        return redirect()->route('transfers.index')->with('success', 'A szállítmány sikeresen megérkezett és a készlet frissült!');
    }

    public function cancel(InventoryTransfer $transfer, \App\Services\InventoryService $inventory)
    {
        $inventory->finalizeTransfer($transfer, false);
        return redirect()->route('transfers.index')->with('success', 'Átmozgatás visszavonva! A termékek visszakerültek a forrásraktárba.');
    }
}
