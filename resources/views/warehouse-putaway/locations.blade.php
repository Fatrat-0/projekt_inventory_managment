<section class="{{ $panel }}" aria-labelledby="putaway-location-title" :aria-busy="loading">
    <h3 id="putaway-location-title" class="font-semibold text-gray-900">Célrekesz</h3>
    <div role="group" aria-label="Célrekesz választási módja" class="flex flex-wrap gap-2">
        <button type="button" :aria-pressed="mode === 'suggestions'" @click="setMode('suggestions')" :disabled="draftLocked"
                class="{{ $secondary }}" :class="mode === 'suggestions' && 'border-indigo-500 bg-indigo-50 text-indigo-950'">Ajánlott rekeszek</button>
        <button type="button" :aria-pressed="mode === 'manual'" @click="setMode('manual')" :disabled="draftLocked"
                class="{{ $secondary }}" :class="mode === 'manual' && 'border-indigo-500 bg-indigo-50 text-indigo-950'">Kézi rekeszkeresés</button>
    </div>

    <div x-show="mode === 'suggestions'" class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <button type="button" @click="loadLocations()" :disabled="!canLookUp || loading" class="{{ $secondary }}">Ajánlások keresése</button>
        <p class="text-xs text-gray-600">{{ $locationPrompt ?? 'A keresés után válassz rekeszt az elhelyezéshez.' }}</p>
    </div>
    <form x-show="mode === 'manual'" @submit.prevent="loadLocations()" class="grid min-w-0 items-end gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
        <div class="min-w-0">
            <label for="putaway-search" class="mb-1 block text-sm font-medium text-gray-900">Rekesz keresése</label>
            <input id="putaway-search" data-putaway-search x-ref="search" x-model="search" @input="changeSearch()" type="search" maxlength="100"
                   :disabled="draftLocked || refreshing" placeholder="Rekesz, zóna, állvány vagy szint" class="{{ $field }}">
        </div>
        <button :disabled="!canLookUp || loading" class="{{ $secondary }}">Keresés</button>
    </form>

    <div x-show="selected" x-ref="selection" tabindex="-1" class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-950">
        <div>
            <p>Kiválasztott rekesz <strong x-text="selected?.code"></strong></p>
            <p x-show="selected && selectedSignature !== signature" class="text-xs">A módosított részlethez ellenőrizd újra a rekeszt.</p>
            @unless($showStockInSideSummary ?? false)
                <div x-show="selectedSignature === signature && !loading && !refreshing">
                    @include('warehouse-putaway.stock', ['stockRow' => 'selected', 'stockScopes' => ['bin']])
                </div>
            @endunless
        </div>
        <a x-show="selected?.[@js($floorPlanField ?? 'putaway_floor_plan_url')]" :href="selected?.[@js($floorPlanField ?? 'putaway_floor_plan_url')]" target="_blank" rel="noopener"
           class="{{ $link }}">Megtekintés az alaprajzon<span class="sr-only"> (új lapon)</span></a>
    </div>
    <p x-show="loading" role="status" class="text-sm text-gray-600">Rekeszek ellenőrzése…</p>
    <p x-show="lookupError" x-text="lookupError" role="alert" class="text-sm text-red-800"></p>
    <template x-if="results && results.length === 0">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            <p x-text="emptyMessage"></p>
            <button x-show="mode === 'suggestions'" type="button" @click="setMode('manual')" class="{{ $link }}">Kézi keresés indítása</button>
        </div>
    </template>

    <ul x-show="resultRows.length" aria-label="Rekesztalálatok" class="min-w-0 space-y-2">
        <template x-for="location in resultRows" :key="location.location_id">
            @include('warehouse-putaway.compact-location')
        </template>
    </ul>
    <button x-show="canSeeMore" type="button" @click="showMore = !showMore" :aria-expanded="showMore" class="{{ $secondary }}"
            x-text="showMore ? 'Kevesebb megjelenítése' : 'További rekeszek megjelenítése'"></button>

    <details x-show="rejected.length" class="rounded-md border border-gray-200">
        <summary class="min-h-11 cursor-pointer rounded-md px-3 py-2.5 text-sm font-medium text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-indigo-600">Elutasított rekeszek és indokok</summary>
        <ul tabindex="0" aria-label="Elutasított rekeszek" class="max-h-72 divide-y divide-gray-100 overflow-y-auto border-t border-gray-100 px-3 text-sm">
            <template x-for="location in rejected" :key="location.location_id">
                <li class="py-2"><strong x-text="location.code"></strong><p class="mt-0.5 text-xs text-red-800" x-text="checks(location.checks?.failed).join(' ')"></p></li>
            </template>
        </ul>
    </details>
</section>
