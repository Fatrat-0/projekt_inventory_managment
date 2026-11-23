<section x-show="canEdit" class="{{ $panel }}" aria-labelledby="plan-eligible-title" data-plan-eligible>
    <h2 id="plan-eligible-title" x-ref="eligibleHeading" tabindex="-1" class="text-base font-semibold text-gray-900">Jóváhagyott igények hozzáadása</h2>
    <div class="mt-3">
        <label for="plan-eligible-search" class="text-sm font-medium text-gray-900">Igények keresése</label>
        <input id="plan-eligible-search" type="search" maxlength="200" x-model="search" @input.debounce.300ms="changeSearch()"
               :disabled="saving || unconfirmed" placeholder="Igényazonosító, terméknév vagy SKU…" class="{{ $field }} mt-1 min-h-11">
    </div>
    <div :aria-busy="eligibleLoading" class="mt-2">
        <p x-show="eligibleLoading" role="status" class="py-2 text-sm text-gray-700">Igények betöltése…</p>
        <div x-show="eligibleError && !eligibleLoading" class="my-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-amber-50 p-2 text-sm text-amber-950">
            <p role="alert" x-text="eligibleError"></p>
            <button type="button" @click="loadEligible(eligiblePage?.current_page ?? 1)" :disabled="saving || unconfirmed" class="{{ $secondary }}">Újrapróbálás</button>
        </div>
        <p x-show="eligiblePage && !eligibleLoading && !eligibleRows.length && !eligibleError" role="status" class="rounded-md bg-gray-50 p-3 text-sm text-gray-700"
           x-text="search.trim() ? 'Nincs a keresésnek megfelelő hozzárendelhető igény.' : 'Az induló raktárhoz nincs hozzárendelhető jóváhagyott igény.'"></p>
        <ul x-show="eligibleRows.length" class="divide-y divide-gray-200">
            <template x-for="row in eligibleRows" :key="row.id">
                <li data-plan-eligible-row class="rounded-md" :class="isSelected(row.id) && 'bg-indigo-50/60'">
                    <label class="flex cursor-pointer items-start gap-3 px-2 py-2.5 text-sm [overflow-wrap:anywhere]">
                        <input type="checkbox" :checked="isSelected(row.id)" @change="toggleIntent(row)"
                               :aria-label="`Kijelölés · ${row.reference}`" :disabled="busy || needsRefresh || unconfirmed || eligibleLoading || !!eligibleError"
                               class="mt-1 h-4 w-4 shrink-0 rounded border-gray-400 text-indigo-700 focus:ring-indigo-600 disabled:opacity-50">
                        <span class="min-w-0 flex-1">
                            <span class="flex min-w-0 flex-wrap items-start justify-between gap-x-2 gap-y-1">
                                <span class="min-w-0 flex-1 basis-36">
                                    <span class="block font-mono text-xs font-medium text-indigo-800" x-text="row.reference"></span>
                                    <span class="mt-0.5 block font-semibold text-gray-900" x-text="row.product_name"></span>
                                    <span class="block text-xs text-gray-600" x-text="row.sku"></span>
                                </span>
                                <strong class="text-gray-900" x-text="formatQuantity(row.quantity)"></strong>
                            </span>
                            <span class="mt-1 block text-xs text-gray-700"><span>{{ $shipment->origin->warehouse_name }}</span> <span aria-hidden="true">→</span><span class="sr-only">Célraktár</span> <strong x-text="row.to_warehouse_name"></strong></span>
                            <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-600">
                                <span><span x-text="@js($priorities)[row.priority] || 'Normál'"></span> prioritás</span>
                                <span x-show="row.requested_arrival_at">Kért érkezés <span class="font-medium text-gray-900" x-text="formatRequestedArrival(row)"></span></span>
                                <span x-show="row.shipping_preference === 'dedicated_requested'" class="rounded-md bg-amber-50 px-1.5 py-0.5 font-medium text-amber-950">Külön fuvar kérése</span>
                                <span x-show="row.shipping_preference === 'groupable'">Összevonható</span>
                            </span>
                            <span x-show="row.reservation_status || row.picking_status" class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-700">
                                <span x-show="row.reservation_status === 'active'">Készlet biztosítva</span>
                                <span x-show="['unreserved', 'released'].includes(row.reservation_status)">Készletbiztosításra vár</span>
                                <span x-show="row.picking_status === 'in_progress'">Kitárolás folyamatban · <span x-text="formatQuantity(row.staged_quantity)"></span></span>
                                <span x-show="row.picking_status === 'picked'" class="font-medium">Kitárolás befejezve</span>
                            </span>
                        </span>
                    </label>
                </li>
            </template>
        </ul>
        <div x-show="eligiblePage?.last_page > 1" role="group" aria-label="Igények lapozása" class="flex min-w-0 flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-2 text-sm text-gray-700">
            <p x-text="`${eligiblePage?.from ?? 0}–${eligiblePage?.to ?? 0} / ${eligiblePage?.total ?? 0} igény`"></p>
            <div class="flex flex-wrap gap-1.5">
                <button type="button" @click="loadEligible(eligiblePage.current_page - 1)" :disabled="eligibleLoading || busy || unconfirmed || eligiblePage?.current_page <= 1" class="{{ $secondary }}">Előző oldal</button>
                <button type="button" @click="loadEligible(eligiblePage.current_page + 1)" :disabled="eligibleLoading || busy || unconfirmed || eligiblePage?.current_page >= eligiblePage?.last_page" class="{{ $secondary }}">Következő oldal</button>
            </div>
        </div>
    </div>

    <div class="mt-3 border-t border-gray-200 pt-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-gray-900" aria-live="polite" x-text="`${selected.length} igény kijelölve`"></p>
            <button type="button" @click="attachSelected()" :disabled="!mayChange || eligibleLoading || !selected.length || (needsGrouping && !canOverrideDedicated)"
                    class="{{ $primary }}">Kijelölt igények hozzáadása</button>
        </div>
        <p x-show="needsGrouping && !canOverrideDedicated" class="mt-2 text-sm text-amber-950">A külön fuvart kérő igény csoportosításához jogosultság szükséges.</p>
        <ul x-show="selected.length" aria-label="Hozzáadásra kijelölt igények" class="mt-2 flex max-h-36 flex-wrap gap-1.5 overflow-y-auto">
            <template x-for="row in selected" :key="row.id">
                <li class="min-w-0 max-w-full">
                    <button type="button" @click="removeSelection(row.id)" :disabled="busy || unconfirmed" :aria-label="`Kijelölés törlése · ${row.reference}`"
                            class="inline-flex min-h-11 max-w-full items-center gap-1 rounded-md bg-gray-100 px-2 text-left font-mono text-xs text-gray-800 hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:opacity-50">
                        <span class="min-w-0 [overflow-wrap:anywhere]" x-text="row.reference"></span><span aria-hidden="true">×</span>
                    </button>
                </li>
            </template>
        </ul>
    </div>
</section>
