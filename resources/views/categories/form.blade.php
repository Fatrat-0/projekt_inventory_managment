<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-6 sm:px-6">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Kategóriák</a>
        <h2 class="text-xl font-bold text-gray-900">{{ $category ? 'Kategória szerkesztése' : 'Új kategória' }}</h2>
        <x-operation-feedback />
        <form method="POST" action="{{ $category ? route('categories.update', $category) : route('categories.store') }}" x-data="{ submitting: false }" @pageshow.window="if ($event.persisted) submitting = false" @submit="if (submitting) $event.preventDefault(); else submitting = true" class="space-y-5 rounded-lg border border-gray-200 bg-white p-5">
            @csrf @if($category) @method('PUT') @endif
            <input type="hidden" name="context" value="{{ $context }}">
            <div><label for="category-name" class="mb-1 block text-sm font-medium text-gray-700">Kategória neve</label>
                <input id="category-name" name="category_name" maxlength="100" required autofocus value="{{ is_scalar(old('category_name', $category?->category_name ?? '')) ? old('category_name', $category?->category_name ?? '') : '' }}" aria-describedby="category-name-error" @error('category_name') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                <p id="category-name-error" class="mt-1 text-sm text-red-700">@error('category_name'){{ $message }}@enderror</p>
            </div>
            <div><label for="category-parent" class="mb-1 block text-sm font-medium text-gray-700">Szülő kategória</label>
                <select id="category-parent" name="parent_id" aria-describedby="parent-help parent-error" @error('parent_id') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                    <option value="">Főkategória – nincs szülő</option>
                    @foreach($categories as $option)<option value="{{ $option->category_id }}" @selected(old('parent_id', $category?->parent_id) == $option->category_id)>{{ $tree->path($option->category_id) }} · #{{ $option->category_id }}</option>@endforeach
                </select>
                <p id="parent-help" class="mt-1 text-xs text-gray-500">Szerkesztéskor önmaga és a teljes saját ága nem választható szülőnek.</p>
                <p id="parent-error" class="mt-1 text-sm text-red-700">@error('parent_id'){{ $message }}@enderror</p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                @foreach(['default_item_min_stock' => 'Termékenkénti minimum (db)', 'aggregate_min_stock' => 'Kategória minimum (db)'] as $key => $label)
                <div><label for="{{ $key }}" class="mb-1 block text-sm font-medium text-gray-700">{{ $label }}</label><input id="{{ $key }}" name="{{ $key }}" type="number" min="0" max="2147483647" step="1" value="{{ is_scalar(old($key, $category?->$key ?? '')) ? old($key, $category?->$key ?? '') : '' }}" aria-describedby="{{ $key }}-help {{ $key }}-error" @error($key) aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                    <p id="{{ $key }}-help" class="mt-1 text-xs text-gray-500">{{ $key === 'default_item_min_stock' ? 'A közvetlenül ide sorolt termékek alapértéke, ha nincs saját minimumuk. Nem öröklődik tovább az alkategóriákba.' : 'A közvetlenül ide sorolt termékek összkészlete, minden raktárból. Az alkategóriák nélkül.' }}</p>
                    <p id="{{ $key }}-error" class="mt-1 text-sm text-red-700">@error($key){{ $message }}@enderror</p>
                </div>
                @endforeach
            </div>
            <p class="text-xs text-gray-500">Üres mező: nincs beállított minimum. A 0 kifejezetten beállított érték; nem azonos az üres mezővel.</p>
            <div class="flex justify-end gap-4 border-t border-gray-100 pt-4"><a href="{{ $returnUrl }}" class="py-2 text-sm text-gray-600">Mégse</a><button :disabled="submitting" x-text="submitting ? 'Mentés…' : 'Kategória mentése'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Kategória mentése</button></div>
        </form>
    </div>
</x-app-layout>
