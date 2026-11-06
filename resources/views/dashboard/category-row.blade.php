<li class="space-y-2 border-b border-gray-100 p-4" data-dashboard-row data-category-alert="{{ $category->category_id }}">
    <div class="flex flex-wrap items-start justify-between gap-2"><h3 class="dashboard-text min-w-0 flex-1 font-semibold text-gray-900">{{ $category->category_name }}</h3><span class="rounded-md bg-amber-50 px-2 py-1 text-sm font-semibold tabular-nums text-amber-800">Hiány: {{ number_format($category->shortage, 0, ',', ' ') }} db</span></div>
    <p class="text-sm font-semibold text-amber-800">Kiadható: {{ number_format($category->total_available, 0, ',', ' ') }} db <span class="font-normal text-gray-500">· Minimum: {{ number_format($category->aggregate_min_stock, 0, ',', ' ') }} db</span></p>
    <p class="text-xs text-gray-500">Fizikai: {{ number_format($category->total_stock, 0, ',', ' ') }} db · Elkülönítve: {{ number_format($category->total_quarantined, 0, ',', ' ') }} db</p>
    <div class="flex flex-wrap gap-x-4 gap-y-2">
        <a href="{{ route('products.index', ['category_id' => $category->category_id]) }}" class="text-xs font-medium text-indigo-700 hover:underline">Termékek és alkategóriák →</a>
        @if($canManage)<a href="{{ route('categories.edit', $category) }}" class="text-xs font-medium text-indigo-700 hover:underline">Minimum beállítása →</a>@endif
    </div>
</li>
