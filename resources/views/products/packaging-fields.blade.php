@php
    $packaging = $product?->defaultPackaging;
    $fields = [
        'name' => ['Csomagolás megnevezése', 'text', ['maxlength' => 100]],
        'pieces_per_package' => ['Darab csomagolásonként', 'number', ['min' => 1, 'max' => 2147483647, 'step' => 1]],
        'gross_weight_kg' => ['Bruttó tömeg (kg)', 'number', ['min' => 0.001, 'max' => 1000000, 'step' => 0.001]],
        'width_cm' => ['Külső szélesség (cm)', 'number', ['min' => 0.01, 'max' => 10000, 'step' => 0.01]],
        'depth_cm' => ['Külső mélység (cm)', 'number', ['min' => 0.01, 'max' => 10000, 'step' => 0.01]],
        'height_cm' => ['Külső magasság (cm)', 'number', ['min' => 0.01, 'max' => 10000, 'step' => 0.01]],
        'barcode' => ['Csomagolási vonalkód', 'text', ['maxlength' => 100]],
    ];
@endphp
<fieldset class="min-w-0 space-y-3 rounded-md border border-gray-200 p-4" aria-describedby="product-packaging-help">
    <legend class="px-1 text-sm font-semibold text-gray-900">Alapértelmezett csomagolás (opcionális)</legend>
    <p id="product-packaging-help" class="text-xs text-gray-600">
        A csomagoláshoz add meg a nevét és a benne lévő darabszámot. A tömeg és a méretek egy teljes csomagolásra vonatkoznak.
        @if($packaging) A csomagolási adatok törléséhez ürítsd ki az összes mezőt. @endif
    </p>
    <x-input-error :messages="$errors->get('packaging')" />
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($fields as $key => [$label, $type, $limits])
            @php($value = old('packaging.'.$key, $packaging?->{$key}))
            <div class="min-w-0">
                <label for="product-packaging-{{ $key }}" class="block text-sm font-medium text-gray-700">{{ $label }}</label>
                <input id="product-packaging-{{ $key }}" name="packaging[{{ $key }}]" type="{{ $type }}"
                       value="{{ is_scalar($value) ? $value : '' }}" @foreach($limits as $attribute => $limit) {{ $attribute }}="{{ $limit }}" @endforeach
                       @error('packaging.'.$key) aria-invalid="true" aria-describedby="product-packaging-{{ $key }}-error" @enderror
                       class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                @error('packaging.'.$key)<p id="product-packaging-{{ $key }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>
</fieldset>
