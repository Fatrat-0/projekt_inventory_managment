<section class="{{ $panel }}" aria-labelledby="putaway-cargo-title">
    <h3 id="putaway-cargo-title" class="font-semibold text-gray-900">Fizikai rakományadatok</h3>
    <div class="grid min-w-0 items-start gap-3 sm:grid-cols-2">
        <div class="min-w-0 space-y-1">
            <label for="putaway-weight" class="block text-sm font-medium text-gray-900">Bruttó tömeg (kg)</label>
            <input id="putaway-weight" data-putaway-weight x-model="weight" @input="changeCargo()" type="text" inputmode="decimal" maxlength="14"
                   :disabled="usePackaging || draftLocked || refreshing" :aria-invalid="String(Boolean(weightError))" aria-describedby="putaway-weight-help" class="{{ $field }}">
            <p id="putaway-weight-help" class="text-xs" :class="weightError ? 'text-red-800' : 'text-gray-600'"
               x-text="weightError || (usePackaging ? 'A megerősített csomagolásból számítva.' : 'A most elhelyezett részlet teljes tömege.')"></p>
            <label x-show="packagingWeight" class="flex min-h-11 items-start gap-2 rounded-md border border-gray-200 bg-gray-50 p-2 text-xs text-gray-800">
                <input type="checkbox" x-model="usePackaging" @change="changeCargo()" :disabled="draftLocked || refreshing"
                       class="mt-0.5 rounded border-gray-400 text-indigo-700 focus:ring-indigo-600">
                <span>A részlet egész, bontatlan csomagokból áll. <span class="block font-semibold" x-text="formula"></span></span>
            </label>
        </div>
        <div class="min-w-0 space-y-1">
            <label for="putaway-pallets" class="block text-sm font-medium text-gray-900">Raklaphelyigény</label>
            <input id="putaway-pallets" data-putaway-pallets x-model="pallets" @input="changeCargo()" type="text" inputmode="numeric" maxlength="7"
                   :disabled="draftLocked || refreshing" :aria-invalid="String(Boolean(palletError))" aria-describedby="putaway-pallets-help" class="{{ $field }}">
            <p id="putaway-pallets-help" class="text-xs" :class="palletError ? 'text-red-800' : 'text-gray-600'"
               x-text="palletError || 'Raklap nélküli áru esetén 0. Üresen a raklaphelyigény ismeretlen.'"></p>
        </div>
    </div>
    <details class="rounded-md border border-gray-200">
        <summary class="min-h-11 cursor-pointer rounded-md px-3 py-2.5 text-sm font-medium text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-indigo-600">Külső méretek (cm)</summary>
        <div class="space-y-2 border-t border-gray-100 p-3">
            <p class="text-xs text-gray-700">A teljes részlet mérete a rekeszbe kerülő tájolásban. A hiányzó méretekhez helyszíni ellenőrzés szükséges.</p>
            <div class="grid min-w-0 gap-2 sm:grid-cols-3">
                @foreach (['width' => 'Szélesség', 'depth' => 'Mélység', 'height' => 'Magasság'] as $axis => $name)
                    <div class="min-w-0">
                        <label for="putaway-{{ $axis }}" class="mb-1 block text-sm font-medium text-gray-900">{{ $name }} (cm)</label>
                        <input id="putaway-{{ $axis }}" x-model="dimensions.{{ $axis }}" @input="changeCargo()" type="text" inputmode="numeric" maxlength="5"
                               :disabled="draftLocked || refreshing" aria-describedby="putaway-dimension-help" class="{{ $field }}">
                    </div>
                @endforeach
            </div>
            <p id="putaway-dimension-help" x-show="dimensionError" x-text="dimensionError" class="text-xs text-red-800"></p>
        </div>
    </details>
</section>
