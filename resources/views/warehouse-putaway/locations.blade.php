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
        <p class="text-xs text-gray-600">A keresés után válassz rekeszt az elhelyezéshez.</p>
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
        <p>Kiválasztott rekesz <strong x-text="selected?.code"></strong></p>
        <a x-show="selected?.putaway_floor_plan_url" :href="selected?.putaway_floor_plan_url" target="_blank" rel="noopener"
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
            <li :data-putaway-location="location.code" class="min-w-0 space-y-2 rounded-md border p-3"
                :class="selected?.location_id === location.location_id ? 'border-indigo-400' : 'border-gray-200'">
                <div class="flex min-w-0 flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-900" x-text="location.code"></p>
                        <p x-show="location.name !== location.code" class="text-sm text-gray-700" x-text="location.name"></p>
                        <p x-show="position(location)" class="text-xs text-gray-600" x-text="position(location)"></p>
                    </div>
                    <span class="rounded-md px-2 py-1 text-xs font-semibold"
                          :class="{ 'bg-emerald-50 text-emerald-900': location.status === 'verified', 'bg-amber-50 text-amber-900': location.status === 'conditional', 'bg-red-50 text-red-900': location.status === 'rejected' }"
                          x-text="locationStatus(location.status)"></span>
                </div>
                <p x-show="reason(location)" class="text-xs text-gray-700" x-text="reason(location)"></p>
                <dl x-show="location.status !== 'rejected'" class="grid min-w-0 gap-2 text-xs sm:grid-cols-2">
                    <div x-show="location.score?.remaining_weight_grams != null"><dt class="text-gray-600">Elhelyezés után szabad teherbírás</dt><dd class="font-semibold" x-text="mass(location.score?.remaining_weight_grams)"></dd></div>
                    <div x-show="location.score?.remaining_pallet_slots != null"><dt class="text-gray-600">Elhelyezés után szabad raklaphely</dt><dd class="font-semibold" x-text="numberValue(location.score?.remaining_pallet_slots)"></dd></div>
                    <div x-show="location.score?.remaining_weight_grams == null"><dt class="text-gray-600">Jelenlegi szabad teherbírás</dt><dd class="font-semibold" x-text="mass(location.free_weight_grams)"></dd></div>
                    <div x-show="location.score?.remaining_pallet_slots == null && location.free_pallet_slots != null"><dt class="text-gray-600">Jelenlegi szabad raklaphely</dt><dd class="font-semibold" x-text="numberValue(location.free_pallet_slots)"></dd></div>
                </dl>
                <ul x-show="location.status === 'rejected' || location.status === 'conditional'" class="list-disc space-y-0.5 ps-4 text-xs"
                    :class="location.status === 'rejected' ? 'text-red-900' : 'text-amber-900'">
                    <template x-for="(reason, index) in checks(location.status === 'rejected' ? location.checks?.failed : location.checks?.missing)" :key="index">
                        <li x-text="reason"></li>
                    </template>
                </ul>
                <div class="flex flex-wrap gap-x-3 gap-y-1">
                    <button x-show="location.status !== 'rejected'" type="button" @click="selectLocation(location)" :disabled="!canLookUp || loading"
                            :aria-pressed="selected?.location_id === location.location_id" class="{{ $secondary }}"
                            x-text="selected?.location_id === location.location_id ? 'Kiválasztva' : 'Kiválasztás'"></button>
                    <a x-show="location.putaway_floor_plan_url" :href="location.putaway_floor_plan_url" target="_blank" rel="noopener"
                       class="{{ $link }}">Megtekintés az alaprajzon<span class="sr-only"> (új lapon)</span></a>
                </div>
            </li>
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
