<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);

        // 1. Rendezési paraméterek (Alapértelmezetten a legújabb elöl, ahogy eddig)
        $sortBy = $request->input('sort_by', 'created_at'); 
        $sortDir = $request->input('sort_dir', 'desc');

        // Biztonsági ellenőrzés: Csak ezekre az oszlopokra engedünk rendezni
        $allowedSorts = ['product_name', 'sku', 'price', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }
        // Az irány csak asc (növekvő) vagy desc (csökkenő) lehet
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        // 2. Lekérdezés
        $products = \App\Models\Product::with('category')
            ->when($search, function ($query, $search) {
                return $query->where('product_name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%");
            })
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);

        // 3. Változók átadása a nézetnek
        return view('products.index', compact('products', 'search', 'perPage', 'sortBy', 'sortDir'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Kelleni fognak a kategóriák a legördülő menühöz
        $categories = \App\Models\Category::all();
        return view('products.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // 1. Adatok ellenőrzése (Validáció)
        $validated = $request->validate([
            'product_name' => 'required|max:100',
            'sku' => 'required|unique:products,sku|max:50',
            'price' => 'required|numeric|min:0',
            'category_id' => 'required|exists:categories,category_id',
        ]);

        // 2. Mentés az adatbázisba
        \App\Models\Product::create($validated);

        // 3. Visszairányítás üzenettel
        return redirect()->route('products.index')->with('success', 'Termék sikeresen hozzáadva!');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        // 1. Megkeressük a terméket az azonosító (ID) alapján
        $product = \App\Models\Product::findOrFail($id);
        
        // 2. Lekérjük a kategóriákat és elküldjük a nézetnek
        $categories = \App\Models\Category::all();
        return view('products.edit', compact('product', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    
    public function update(Request $request, string $id)
    {
        // 1. Megkeressük a terméket
        $product = \App\Models\Product::findOrFail($id);

        // 2. Adatok ellenőrzése
        // Figyeld a cikkszámot (sku): engedjük, hogy ugyanaz maradjon, de másé nem lehet!
        $validated = $request->validate([
            'product_name' => 'required|max:100',
            'sku' => 'required|max:50|unique:products,sku,' . $product->product_id . ',product_id',
            'price' => 'required|numeric|min:0',
            'category_id' => 'required|exists:categories,category_id',
        ]);

        // 3. Frissítjük az adatbázist
        $product->update($validated);

        // 4. Visszairányítás
        return redirect()->route('products.index')->with('success', 'Termék sikeresen frissítve!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        // 1. Megkeressük a terméket az ID alapján
        $product = \App\Models\Product::findOrFail($id);
        
        // 2. Töröljük az adatbázisból
        $product->delete();
        
        // 3. Visszairányítás
        return redirect()->route('products.index')->with('success', 'Termék sikeresen törölve!');
    }
}
