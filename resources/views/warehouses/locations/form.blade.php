@php
    $field = static function ($key, $default = '') {
        $value = old($key, $default);
        return is_scalar($value) ? (string) $value : (string) $default;
    };
    $generated = $location?->system_id !== null;
    $kind = $generated ? $location->kind : $field('kind', $location?->kind ?? 'shelf');
@endphp
<form method="POST" action="{{ $action }}" class="space-y-3">
    @csrf
    @if($location)
        @method('PATCH')
        <input type="hidden" name="lock_version" value="{{ $field('lock_version', $location->lock_version) }}">
    @endif
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label class="block text-sm font-medium">Tárhelykód
            <input name="code" required maxlength="32" placeholder="A-01-02" @readonly($generated) value="{{ $generated ? $location->code : $field('code', $location?->code) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
        </label>
        <label class="block text-sm font-medium">Megnevezés
            <input name="name" required maxlength="100" value="{{ $field('name', $location?->name) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
        </label>
        <label class="block text-sm font-medium">Típus
            @if($generated)<input type="hidden" name="kind" value="{{ $kind }}">@endif
            <select name="kind" @disabled($generated) required class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                @foreach(\App\Models\WarehouseLocation::KINDS as $value => $label)
                    <option value="{{ $value }}" @selected($kind === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm font-medium">Maximális terhelés (kg)
            <input name="max_weight_kg" type="number" step="0.001" min="0.001" max="1000000" required @readonly($generated) value="{{ $generated ? $location->max_weight_grams / 1000 : $field('max_weight_kg', $location ? $location->max_weight_grams / 1000 : '') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
        </label>
        <label class="block text-sm font-medium">Raklapférőhely (csak raklaphelyhez)
            <input name="max_pallet_slots" type="number" step="1" min="1" max="1000000" @readonly($generated) value="{{ $generated ? $location->max_pallet_slots : $field('max_pallet_slots', $location?->max_pallet_slots) }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
        </label>
        @if($location)
            <label class="block text-sm font-medium">Állapot
                <select name="active" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    <option value="1" @selected($field('active', (int) $location->active) === '1')>Aktív</option>
                    <option value="0" @selected($field('active', (int) $location->active) === '0')>Kikapcsolt</option>
                </select>
            </label>
        @endif
    </div>
    @if($generated)<p class="text-xs text-gray-600">A terhelési határt a tervrajzon, a tárolórendszer tulajdonságainál szerkesztheted.</p>@endif
    <button class="min-h-10 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ $location ? 'Tárhely mentése' : 'Tárhely létrehozása' }}</button>
</form>
