<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Stock;
use App\Models\InventoryMovement;

class DashboardController extends Controller
{
    public function index()
    {
        // 1. Alap statisztikák
        $totalProducts = Product::count();
        $totalWarehouses = Warehouse::count();

        // 2. Alacsony készlet (ahol a mennyiség 5 vagy kevesebb)
        $lowStocks = Stock::with(['product', 'warehouse'])
                          ->where('quantity', '<=', 5)
                          ->get();

        // 3. Utolsó 5 mozgás a raktárakban
        $recentMovements = InventoryMovement::with(['product', 'warehouse'])
                                            ->latest()
                                            ->take(5)
                                            ->get();

        // Átadjuk az adatokat a nézetnek
        return view('dashboard', compact('totalProducts', 'totalWarehouses', 'lowStocks', 'recentMovements'));
    }
}
