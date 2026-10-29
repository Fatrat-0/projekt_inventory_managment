<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-6 sm:px-6">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Kategóriák</a>
        <h2 class="text-xl font-bold text-gray-900">{{ $category ? 'Kategória szerkesztése' : 'Új kategória' }}</h2>
        @if($category)
            <div><a href="{{ route('products.index', ['category_id' => $category->category_id, 'sort_by' => 'product_name', 'sort_dir' => 'asc']) }}" class="inline-flex items-center gap-2 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-800 hover:bg-indigo-100">Termékek az ágban: <span class="tabular-nums">{{ number_format($branchProductCount, 0, ',', ' ') }}</span></a>
                <p class="mt-1 text-xs text-gray-500">A mentett kategória és összes alkategóriája termékei, készlettől függetlenül.</p></div>
        @endif
        <x-operation-feedback />
        <form method="POST" action="{{ $category ? route('categories.update', $category) : route('categories.store') }}" x-data="{ submitting: false }" @pageshow.window="if ($event.persisted) submitting = false" @submit="if (submitting) $event.preventDefault(); else submitting = true" class="space-y-5 rounded-lg border border-gray-200 bg-white p-5">
            @csrf @if($category) @method('PUT') @endif
            <input type="hidden" name="context" value="{{ $context }}">
            <div><label for="category-name" class="mb-1 block text-sm font-medium text-gray-700">Kategória neve</label>
                <input id="category-name" name="category_name" maxlength="100" required autofocus value="{{ is_scalar(old('category_name', $category?->category_name ?? '')) ? old('category_name', $category?->category_name ?? '') : '' }}" aria-describedby="category-name-error" @error('category_name') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                <p id="category-name-error" class="mt-1 text-sm text-red-700">@error('category_name'){{ $message }}@enderror</p>
            </div>
            <div><label for="category-parent" class="mb-1 block text-sm font-medium text-gray-700">Szülő kategória</label>
                <x-autocomplete-picker :items="$parentItems" id="category-parent" name="parent_id" :value="old('parent_id', $category?->parent_id ?? '')"
                    placeholder="Főkategória – vagy keress szülőt…" clear-label="Szülő kategória törlése a mezőből" results-label="Szülőkategória-találatok"
                    invalid-value-label="A korábbi szülő nem választható. Válassz másikat, vagy töröld a mezőt." described-by="category-parent-help" />
                <p id="category-parent-help" class="mt-1 text-xs text-gray-500">Keress névre vagy kategóriaútvonalra, majd válassz találatot. Üres mező: főkategória. Önmaga, a saját ága és a hibás, körkörös ágak nem választhatók.</p>
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
            <div class="flex justify-end gap-4 border-t border-gray-100 pt-4"><a href="{{ $returnUrl }}" class="py-2 text-sm text-gray-600">Mégse</a><button type="submit" :disabled="submitting" x-text="submitting ? 'Mentés…' : 'Kategória mentése'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Kategória mentése</button></div>
        </form>
    </div>
</x-app-layout>
