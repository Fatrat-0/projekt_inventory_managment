@php
    $value = static function (string $name, $default) {
        $oldValue = old($name, $default);
        return is_scalar($oldValue) ? $oldValue : $default;
    };
    $chosen = $errors->any() ? collect((array) old('categories'))->filter(fn ($id) => is_scalar($id))->map(fn ($id) => (int) $id)->all()
        : array_map('intval', $bin->allowedCategories->modelKeys());
@endphp
<form method="POST" action="{{ route('warehouses.storage-systems.bins.update', $systemParams + ['bin' => $bin->id]) }}" class="mt-3 space-y-3 text-sm">
    @csrf @method('PATCH')
    <input type="hidden" name="lock_version" value="{{ $value('lock_version', $bin->lock_version) }}">
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
        @foreach(['width_cm' => 'Szélesség', 'depth_cm' => 'Mélység', 'height_cm' => 'Magasság'] as $field => $label)
            <label>{{ $label }} (cm)<input type="number" name="{{ $field }}" min="1" max="{{ $field === 'height_cm' ? 3000 : 5000 }}" required
                value="{{ $value($field, $bin->$field ?? $defaults[$field]) }}" class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
        @endforeach
        <label>Teherbírás (kg)<input type="number" name="max_weight_kg" min="0.001" max="1000000" step="0.001" required
            value="{{ $value('max_weight_kg', $bin->max_weight_grams / 1000) }}" class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
        <label>Állapot<select name="active" class="mt-1 w-full rounded-md border-gray-300 text-sm">
            <option value="1" @selected((int) $value('active', $bin->active) === 1)>Aktív</option>
            <option value="0" @selected((int) $value('active', $bin->active) === 0)>Kikapcsolt</option>
        </select></label>
    </div>
    <fieldset class="min-w-0 space-y-1">
        <legend class="font-medium">Megengedett termékkategóriák</legend>
        <p class="text-xs text-gray-600">Üres lista esetén minden kategória megengedett. Alkategóriát külön jelölj be.</p>
        <div class="max-h-40 overflow-y-auto">
            @forelse($categories as $category)
                <label class="flex min-h-9 items-center gap-2">
                    <input type="checkbox" name="categories[]" value="{{ $category->category_id }}" @checked(in_array((int) $category->category_id, $chosen, true)) class="rounded border-gray-300 text-indigo-600">
                    <span class="break-words">{{ $category->category_name }}</span>
                </label>
            @empty
                <p class="text-xs text-gray-600">Még nincs létrehozott kategória.</p>
            @endforelse
        </div>
    </fieldset>
    <button class="min-h-11 rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Rekesz mentése</button>
</form>
