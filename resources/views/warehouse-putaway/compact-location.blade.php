<li :data-putaway-location="location.code" class="min-w-0 rounded-md border px-3 py-2 [overflow-wrap:anywhere]"
    x-data="{ get cardSelected() { return selected?.location_id === location.location_id }, get cardCurrent() { return !this.cardSelected || selectedCurrent } }"
    :class="cardSelected ? 'border-indigo-400 bg-indigo-50/40' : 'border-gray-200'">
    <div class="grid min-w-0 items-center gap-x-3 gap-y-2 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_auto]">
        <div class="min-w-0">
            <p class="font-semibold text-gray-900" x-text="location.code"></p>
            <p x-show="!position(location) && location.name !== location.code" class="text-sm text-gray-700" x-text="location.name"></p>
            <p x-show="position(location)" class="text-sm text-gray-600" x-text="position(location)"></p>
            <div x-show="cardCurrent">
                @include('warehouse-putaway.stock', ['stockScopes' => ['bin', 'level']])
            </div>
        </div>
        <div class="min-w-0">
            <span x-show="!cardCurrent" role="status" class="inline-block rounded-md bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-900">Új ellenőrzés szükséges</span>
            <span x-show="cardCurrent" class="inline-block rounded-md px-2 py-1 text-xs font-semibold"
                  :class="{ 'bg-emerald-50 text-emerald-900': location.status === 'verified', 'bg-amber-50 text-amber-900': location.status === 'conditional', 'bg-red-50 text-red-900': location.status === 'rejected' }"
                  x-text="{{ $statusExpression ?? 'locationStatus(location.status)' }}"></span>
            <p x-show="cardCurrent && location.status !== 'rejected'" data-putaway-weight-capacity class="mt-1 text-sm text-gray-700">
                <span x-text="location.score?.remaining_weight_grams != null ? 'Szabad teherbírás utána:' : 'Jelenlegi szabad teherbírás:'"></span>
                <strong x-text="mass(location.score?.remaining_weight_grams ?? location.free_weight_grams)"></strong>
            </p>
        </div>
        <button x-show="location.status !== 'rejected' || cardSelected" type="button" @click="selectLocation(location)"
                :disabled="!canLookUp || loading || !cardCurrent || location.status === 'rejected'"
                :aria-pressed="cardSelected" class="{{ $secondary }} justify-self-start sm:justify-self-end"
                x-text="cardSelected ? 'Kiválasztva' : 'Kiválasztás'"></button>
    </div>
    <p x-show="cardCurrent && mode === 'suggestions' && location.ranking_explanation?.summary" data-putaway-rank-summary
       class="mt-1 text-sm text-indigo-800" x-text="location.ranking_explanation?.summary"></p>
    <details x-show="cardCurrent" name="putaway-location-details" class="mt-1">
        <summary class="w-fit cursor-pointer rounded py-1.5 text-sm font-medium text-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Részletek</summary>
        <div class="min-w-0 space-y-2 border-t border-gray-200 pt-2">
            @include('warehouse-putaway.location-details')
            <a x-show="location[@js($floorPlanField ?? 'putaway_floor_plan_url')]" :href="location[@js($floorPlanField ?? 'putaway_floor_plan_url')]" target="_blank" rel="noopener"
               class="{{ $link }}">Megtekintés az alaprajzon<span class="sr-only"> (új lapon)</span></a>
        </div>
    </details>
</li>
