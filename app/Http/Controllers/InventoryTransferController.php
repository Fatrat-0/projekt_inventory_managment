<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Stock;
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

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,product_id',
            'from_warehouse_id' => 'required|exists:warehouses,warehouse_id',
            'to_warehouse_id' => 'required|exists:warehouses,warehouse_id|different:from_warehouse_id',
            'quantity' => 'required|integer|min:1',
        ]);

        // 1. Ellenőrizzük, van-e elég készlet a forrásraktárban
        $sourceStock = Stock::where('product_id', $request->product_id)
                            ->where('warehouse_id', $request->from_warehouse_id)
                            ->first();

        if (!$sourceStock || $sourceStock->quantity < $request->quantity) {
            return back()->withErrors(['quantity' => 'Nincs elég készlet a forrásraktárban!']);
        }

        // 2. Levonjuk a forrásraktárból (mert elindult az áru)
        $sourceStock->decrement('quantity', $request->quantity);

        // 3. Rögzítjük az átmozgatást 'pending' státusszal
        InventoryTransfer::create([
            'product_id' => $request->product_id,
            'from_warehouse_id' => $request->from_warehouse_id,
            'to_warehouse_id' => $request->to_warehouse_id,
            'quantity' => $request->quantity,
            'user_id' => auth()->id(),
            'status' => 'pending',
        ]);

        return redirect()->route('transfers.index')->with('success', 'Átmozgatás elindítva!');
    }

    public function complete(InventoryTransfer $transfer)
    {
        // 1. Biztonsági őr: csak 'pending' (úton lévő) szállítmányt lehet elfogadni
        if ($transfer->status !== 'pending') {
            return back()->withErrors(['Hibás művelet: Ez a szállítmány már megérkezett vagy törölve lett.']);
        }

        // 2. Megkeressük (vagy létrehozzuk) a készletet a célraktárban
        $destinationStock = Stock::firstOrNew([
            'product_id' => $transfer->product_id,
            'warehouse_id' => $transfer->to_warehouse_id,
        ]);

        // 3. Hozzáadjuk a beérkezett mennyiséget a polchoz
        $destinationStock->quantity += $transfer->quantity;
        $destinationStock->save();

        // 4. Átállítjuk a szállítmány státuszát készre (completed)
        $transfer->update(['status' => 'completed']);

        // 5. Vissza a listához egy siker-üzenettel
        return redirect()->route('transfers.index')->with('success', 'A szállítmány sikeresen megérkezett és a készlet frissült!');
    }

    public function cancel(InventoryTransfer $transfer)
    {
        // 1. Biztonsági őr: csak 'pending' (úton lévő) szállítmányt lehet visszavonni
        if ($transfer->status !== 'pending') {
            return back()->withErrors(['Hibás művelet: Csak úton lévő átmozgatást lehet visszavonni.']);
        }

        // 2. Megkeressük a készletet a FORRÁSraktárban (ahonnan elindult)
        $sourceStock = Stock::where('product_id', $transfer->product_id)
                            ->where('warehouse_id', $transfer->from_warehouse_id)
                            ->first();

        // 3. Visszaadjuk a levont mennyiséget a polcra
        if ($sourceStock) {
            $sourceStock->increment('quantity', $transfer->quantity);
        } else {
            // Ha valamiért időközben törlődött volna a sor, újra létrehozzuk
            Stock::create([
                'product_id' => $transfer->product_id,
                'warehouse_id' => $transfer->from_warehouse_id,
                'quantity' => $transfer->quantity
            ]);
        }

        // 4. Átállítjuk a szállítmány státuszát törölt/visszavontra
        $transfer->update(['status' => 'cancelled']);

        // 5. Vissza a listához
        return redirect()->route('transfers.index')->with('success', 'Átmozgatás visszavonva! A termékek visszakerültek a forrásraktárba.');
    }
    

}