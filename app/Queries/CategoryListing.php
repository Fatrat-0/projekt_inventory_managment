<?php

namespace App\Queries;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryListing
{
    public const SORTS = ['category_name' => 'Kategória neve', 'default_item_min_stock' => 'Termékenkénti minimum', 'aggregate_min_stock' => 'Kategória minimum'];
    public array $filters;

    public function __construct(Request $request)
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:200', 'category_id' => 'nullable|integer|exists:categories,category_id',
            'type' => 'nullable|in:root,child', 'sort_by' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'sort_dir' => 'nullable|in:asc,desc', 'per_page' => ['nullable', 'integer', Rule::in([15,25,50,100])], 'page' => 'nullable|integer|min:1',
        ]);
        $this->filters = ['search' => trim($data['search'] ?? ''), 'category_id' => $data['category_id'] ?? '', 'type' => $data['type'] ?? '',
            'sort_by' => $data['sort_by'] ?? 'category_name', 'sort_dir' => $data['sort_dir'] ?? 'asc', 'per_page' => (int) ($data['per_page'] ?? 15)];
    }

    public function query()
    {
        $f = $this->filters;
        $query = Category::with('parent')->withCount(['products', 'children']);
        if ($f['category_id'] !== '') $query->where('category_id', $f['category_id']);
        if ($f['type'] === 'root') $query->whereNull('parent_id');
        if ($f['type'] === 'child') $query->whereNotNull('parent_id');
        foreach (preg_split('/\s+/u', $f['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
            $query->where(fn ($q) => $q->whereRaw("category_name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('parent', fn ($p) => $p->whereRaw("category_name LIKE ? ESCAPE '!'", [$pattern])));
        }
        if ($f['sort_by'] !== 'category_name') $query->orderByRaw($f['sort_by'].' IS NULL');
        return $query->orderBy($f['sort_by'], $f['sort_dir'])->orderBy('category_id');
    }
}
