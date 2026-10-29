<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryRequest;
use App\Models\{Category, Product};
use App\Queries\CategoryListing;
use App\Support\{CategoryListContext, CategoryTree};
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        try { $listing = new CategoryListing($request); }
        catch (ValidationException $e) {
            if ($request->expectsJson()) throw $e;
            return redirect()->route('categories.index')->withErrors(['filters' => 'A keresési feltételek már nem érvényesek. A szűrőket visszaállítottuk.']);
        }
        $filters = $listing->filters;
        $categories = $listing->query()->paginate($filters['per_page'])->withQueryString();
        if ($request->integer('page', 1) > $categories->lastPage()) return redirect()->route('categories.index', $filters + ($categories->lastPage() > 1 ? ['page' => $categories->lastPage()] : []));
        $all = Category::orderBy('category_name')->orderBy('category_id')->get();
        $tree = new CategoryTree($all);
        $parents = $all->keyBy('category_id');
        $suggestions = $all->map(fn ($c) => ['value' => (string) $c->category_id, 'name' => $c->category_name, 'detail' => ($c->parent_id ? $parents->get($c->parent_id)?->category_name : 'Főkategória').' · #'.$c->category_id]);
        $selectedCategory = $all->firstWhere('category_id', $filters['category_id']);
        $context = CategoryListContext::encode($filters + ['page' => $request->integer('page', 1)]);
        return view('categories.index', compact('filters', 'categories', 'tree', 'suggestions', 'selectedCategory', 'context'));
    }

    public function create(Request $request) { return $this->form($request); }
    public function edit(Request $request, Category $category) { return $this->form($request, $category); }

    private function form(Request $request, ?Category $category = null)
    {
        $categories = Category::orderBy('category_name')->orderBy('category_id')->get();
        $tree = new CategoryTree($categories);
        $branchProductCount = $category ? Product::whereIn('category_id', $tree->branchIds($category->category_id))->count() : null;
        $categories = $categories->reject(fn ($candidate) => $tree->wouldCreateCycle($category?->category_id, $candidate->category_id));
        $parentItems = $categories->map(fn ($candidate) => ['value' => (string) $candidate->category_id, 'label' => $tree->path($candidate->category_id).' · #'.$candidate->category_id])->values();
        $context = CategoryListContext::token(old('context', $request->query('context', '')));
        $returnUrl = CategoryListContext::url($context);
        return view('categories.form', compact('category', 'categories', 'parentItems', 'branchProductCount', 'context', 'returnUrl'));
    }

    public function store(CategoryRequest $request) { return $this->save($request); }
    public function update(CategoryRequest $request, Category $category) { return $this->save($request, $category); }

    private function save(CategoryRequest $request, ?Category $category = null)
    {
        DB::transaction(function () use ($request, $category) {
            // Serialize hierarchy edits in a common order before checking ancestry.
            $all = Category::orderBy('category_id')->lockForUpdate()->get();
            $data = array_replace(['parent_id' => null, 'default_item_min_stock' => null, 'aggregate_min_stock' => null], $request->validated());
            if ($data['parent_id'] !== null && !$all->contains('category_id', $data['parent_id'])) throw ValidationException::withMessages(['parent_id' => 'A kiválasztott szülő kategória már nem létezik.']);
            if ((new CategoryTree($all))->wouldCreateCycle($category?->category_id, $data['parent_id'] === null ? null : (int) $data['parent_id'])) {
                throw ValidationException::withMessages(['parent_id' => 'A kiválasztott szülő körkörös hierarchiát eredményezne, vagy már egy hibás, körkörös ág része. Válassz másik szülőt.']);
            }
            if ($category) {
                Category::findOrFail($category->category_id)->update($data);
            } else Category::create($data);
        }, 3);
        return redirect(CategoryListContext::url($request->input('context')))->with('success', $category ? 'Kategória frissítve.' : 'Kategória létrehozva.');
    }

    public function destroy(Request $request, Category $category)
    {
        $message = 'A kategória nem törölhető, mert termék vagy alkategória tartozik hozzá. Előbb sorold át ezeket.';
        try {
            DB::transaction(function () use ($category, $message) {
                Category::orderBy('category_id')->lockForUpdate()->get();
                $locked = Category::findOrFail($category->category_id);
                if ($locked->products()->exists() || $locked->children()->exists()) throw ValidationException::withMessages(['category' => $message]);
                $locked->delete();
            }, 3);
        } catch (QueryException $e) {
            if (!in_array($e->errorInfo[0] ?? null, ['23000', '23503'], true)) throw $e;
            throw ValidationException::withMessages(['category' => $message]);
        }
        return redirect(CategoryListContext::url($request->input('context'), ['category_id', 'page']))->with('success', 'Kategória törölve.');
    }
}
