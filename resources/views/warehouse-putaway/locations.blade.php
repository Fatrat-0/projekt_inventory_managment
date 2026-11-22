<section class="{{ $panel }}" aria-labelledby="putaway-location-title" :aria-busy="loading">
    <h3 id="putaway-location-title" class="font-semibold text-gray-900">Célrekesz</h3>
    <div class="flex items-start gap-2">
        <div role="group" aria-label="Célrekesz választási módja" class="flex min-w-0 flex-wrap gap-2">
            <button type="button" :aria-pressed="mode === 'suggestions'" @click="setMode('suggestions')" :disabled="draftLocked"
                    class="{{ $secondary }} min-w-0 [overflow-wrap:normal]" :class="mode === 'suggestions' && 'border-indigo-500 bg-indigo-50 text-indigo-950'">Ajánlott rekeszek</button>
            <button type="button" :aria-pressed="mode === 'manual'" @click="setMode('manual')" :disabled="draftLocked"
                    class="{{ $secondary }} min-w-0 [overflow-wrap:normal]" :class="mode === 'manual' && 'border-indigo-500 bg-indigo-50 text-indigo-950'">Kézi rekeszkeresés</button>
        </div>
        <button x-show="mode === 'suggestions'" type="button" @click="loadLocations()" :disabled="!canLookUp || loading"
                class="{{ $secondary }} ml-auto w-11 shrink-0" aria-label="Ajánlások frissítése" title="Ajánlások frissítése">
            <x-icon name="refresh" />
        </button>
    </div>
    <p x-show="lookupHint" x-text="lookupHint" class="text-sm text-gray-600"></p>
    <form x-show="mode === 'manual'" @submit.prevent="loadLocations()" role="search" aria-label="Célrekeszek keresése">
        <label for="putaway-search" class="mb-1 block text-sm font-medium text-gray-900">Rekesz keresése</label>
        <input id="putaway-search" data-putaway-search x-ref="search" x-model="search" @input="changeSearch()" type="search" maxlength="100"
               :disabled="draftLocked || refreshing" placeholder="Rekesz, zóna, állvány vagy szint" class="{{ $field }}">
    </form>

    <div x-show="selected && !resultRows.some(row => row.location_id === selected.location_id)" data-putaway-selection class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-950 [overflow-wrap:anywhere]">
        <div>
            <p>Kiválasztott célrekesz: <strong x-text="selected?.code"></strong></p>
            <p x-show="selected && !selectedCurrent" class="text-sm font-medium text-amber-900" role="status">Új ellenőrzés szükséges</p>
            <p x-show="selectedCurrent" class="text-sm" x-text="selected ? {{ $selectedStatusExpression ?? 'locationStatus(selected.status)' }} : ''"></p>
            <p x-show="selectedError && selectedError !== lookupError" x-text="selectedError" class="text-sm text-red-800" role="alert"></p>
            <ul x-show="selectedCurrent && selected?.status === 'rejected'" class="list-disc space-y-0.5 ps-4 text-sm text-red-800">
                <template x-for="warning in checks(selected?.checks?.failed)" :key="warning"><li x-text="warning"></li></template>
            </ul>
            <div x-show="selectedCurrent && !loading && !refreshing" @class(['lg:hidden' => $showStockInSideSummary ?? false])>
                @include('warehouse-putaway.stock', ['stockRow' => 'selected', 'stockScopes' => ['bin']])
            </div>
        </div>
        <div class="flex min-w-0 flex-wrap items-center gap-x-3">
            @if($allowSelectedRecheck ?? false)
                <button x-show="!selectedCurrent && !needsRefresh" type="button" @click="loadLocations(true)"
                        :disabled="!canLookUp || loading" class="{{ $secondary }}">Célrekesz újraellenőrzése</button>
            @endif
            <a x-show="selected?.[@js($floorPlanField ?? 'putaway_floor_plan_url')]" :href="selected?.[@js($floorPlanField ?? 'putaway_floor_plan_url')]" target="_blank" rel="noopener"
               class="{{ $link }}">Megtekintés az alaprajzon<span class="sr-only"> (új lapon)</span></a>
        </div>
    </div>
    <p x-show="loading" role="status" class="text-sm text-gray-600">Rekeszek ellenőrzése…</p>
    <p x-show="lookupError" x-text="lookupError" role="alert" class="text-sm text-red-800"></p>
    <button x-show="mode === 'manual' && lookupError && canSearchLocations" type="button" @click="loadLocations()"
            :disabled="loading" class="{{ $link }}">Keresés újrapróbálása</button>
    @if($allowSourceRefresh ?? false)
        <button x-show="lookupError && requiresRefresh && !pendingCommand" type="button" @click="refresh()"
                :disabled="refreshing" class="{{ $secondary }}">Forrás és cél frissítése</button>
    @endif
    <template x-if="results && results.length === 0">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
            <p x-text="emptyMessage" role="status"></p>
            <button x-show="mode === 'suggestions'" type="button" @click="setMode('manual')" class="{{ $link }}">Kézi keresés indítása</button>
        </div>
    </template>

    <ul x-show="resultRows.length" aria-label="Rekesztalálatok" class="min-w-0 space-y-2">
        <template x-for="location in resultRows" :key="location.location_id">
            @include('warehouse-putaway.compact-location')
        </template>
    </ul>
    <button x-show="canSeeMore" type="button" @click="showMore = !showMore" :aria-expanded="showMore" class="{{ $secondary }}"
            x-text="showMore ? 'Kevesebb megjelenítése' : (mode === 'suggestions' ? 'További ajánlások megjelenítése' : 'További rekeszek megjelenítése')"></button>

    <details x-show="rejected.some(row => row.location_id !== selected?.location_id)" class="rounded-md border border-gray-200">
        <summary class="min-h-11 cursor-pointer rounded-md px-3 py-2.5 text-sm font-medium text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-indigo-600">Elutasított rekeszek és indokok</summary>
        <ul tabindex="0" aria-label="Elutasított rekeszek" class="max-h-72 divide-y divide-gray-100 overflow-y-auto border-t border-gray-100 px-3 text-sm">
            <template x-for="location in rejected.filter(row => row.location_id !== selected?.location_id)" :key="location.location_id">
                <li class="py-2"><strong x-text="location.code"></strong><p class="mt-0.5 text-xs text-red-800" x-text="checks(location.checks?.failed).join(' ')"></p></li>
            </template>
        </ul>
    </details>
</section>
