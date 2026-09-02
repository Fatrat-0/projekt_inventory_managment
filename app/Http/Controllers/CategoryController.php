<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // A 'with('parent')' betölti a szülőket is
        $categories = \App\Models\Category::with('parent')->get();
        return view('categories.index', compact('categories'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Lekérjük a kategóriákat a legördülő menühöz (hogy választhassunk szülőt)
        $categories = \App\Models\Category::all();
        return view('categories.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,category_id',
            'default_item_min_stock' => 'nullable|integer|min:0',
            'aggregate_min_stock' => 'nullable|integer|min:0',
        ]);

        Category::create($validated);
        return redirect()->route('categories.index')->with('success', 'Kategória sikeresen hozzáadva!');
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
    public function edit(Category $category)
    {
        // Itt is lekérjük őket, de kivesszük belőle önmagát, hogy ne lehessen saját maga szülője (végtelen ciklus)
        $categories = \App\Models\Category::where('category_id', '!=', $category->category_id)->get();
        return view('categories.edit', compact('category', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'category_name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,category_id',
            'default_item_min_stock' => 'nullable|integer|min:0',
            'aggregate_min_stock' => 'nullable|integer|min:0',
        ]);

        $category->update($validated);

        return redirect()->route('categories.index')->with('success', 'Kategória sikeresen frissítve!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        $category->delete();
        return redirect()->route('categories.index')->with('success', 'Kategória törölve!');
    }
}
