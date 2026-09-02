<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Stock;
use App\Models\InventoryMovement;
use App\Models\Category;

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

        // 4/A. Egyedi termékhiányok
        $lowStockProducts = Product::with('category')
            ->withSum('stocks', 'quantity') // Összeadja az összes raktárban lévő készletet
            ->get()
            ->filter(function ($product) {
                // Limit: Saját felülbírálat VAGY Kategória alapértelmezés
                $threshold = $product->min_stock_override ?? optional($product->category)->default_item_min_stock;
                
                // Ha nincs limit, nem riasztunk
                if ($threshold === null) {
                    return false; 
                }
                
                // Riasztunk, ha a jelenlegi készlet kisebb, mint a limit
                $currentStock = $product->stocks_sum_quantity ?? 0;
                return $currentStock < $threshold;
            });


        // 4/B. Kategória szintű hiányok
        $lowStockCategories = Category::whereNotNull('aggregate_min_stock')
            ->with('products.stocks') // Betöltjük a termékeiket és azok készleteit
            ->get()
            ->filter(function ($category) {
                // Összeadjuk a kategóriába tartozó ÖSSZES termék ÖSSZES készletét
                $totalCategoryStock = $category->products->sum(function ($product) {
                    return $product->stocks->sum('quantity');
                });

                // Riasztunk, ha az összesített készlet kisebb, mint a limit
                return $totalCategoryStock < $category->aggregate_min_stock;
            });

        // 5. Átadjuk az adatokat a nézetnek
        return view('dashboard', compact(
            'totalProducts' 
            ,'totalWarehouses' 
            ,'recentMovements' 
            ,'lowStockProducts' 
            ,'lowStockCategories'
        ));
    }
}
