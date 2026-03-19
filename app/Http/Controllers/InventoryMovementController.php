<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class InventoryMovementController extends Controller
{
    public function index()
    {
        // A legutóbbi mozgások listája
        $movements = InventoryMovement::with(['product', 'warehouse', 'user'])->latest()->get();
        return view('movements.index', compact('movements'));
    }

    public function create()
    {
        $products = Product::all();
        $warehouses = Warehouse::all();
        return view('movements.create', compact('products', 'warehouses'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,product_id',
            'warehouse_id' => 'required|exists:warehouses,warehouse_id',
            'type' => 'required|in:in,out',
            'quantity' => 'required|integer|min:1',
            'reference' => 'nullable|string|max:255',
        ]);

        // Hozzáadjuk a bejelentkezett felhasználó ID-ját, aki a mozgást rögzítette
        $validated['user_id'] = auth()->id();

        // Mentjük a mozgást (EKKOR LÉP AKCIÓBA AZ OBSERVER A HÁTTÉRBEN!)
        InventoryMovement::create($validated);

        return redirect()->route('movements.index')->with('success', 'Mozgás sikeresen rögzítve!');
    }
}