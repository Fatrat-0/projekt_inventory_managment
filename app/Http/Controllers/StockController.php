<?php

namespace App\Http\Controllers;

use App\Models\Stock;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index()
    {
        // Lekérjük az összes készletet, és hozzácsatoljuk a termék és raktár adatait is
        $stocks = Stock::with(['product', 'warehouse'])->get();
        return view('stock.index', compact('stocks'));
    }
}