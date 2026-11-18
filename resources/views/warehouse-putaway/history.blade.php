<section data-putaway-history class="{{ $panel }}" aria-labelledby="putaway-history-title" :aria-busy="historyLoading">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 id="putaway-history-title" class="font-semibold text-gray-900">Betárolási előzmények</h3>
        <button type="button" x-show="historyError" @click="loadHistory(history.current_page)" :disabled="historyLoading" class="{{ $secondary }}">Előzmények frissítése</button>
    </div>
    <p x-show="historyError" x-text="historyError" role="alert" class="text-sm text-red-800"></p>
    <p x-show="historyLoading" role="status" class="text-sm text-gray-600">Előzmények betöltése…</p>
    <p x-show="!history.data.length && !historyLoading && !historyError" class="text-sm text-gray-700">Ehhez az átvételi tételhez még nem tartozik betárolás.</p>
    <ol x-show="history.data.length" class="divide-y divide-gray-100">
        <template x-for="event in history.data" :key="event.id">
            <li class="grid min-w-0 gap-2 py-3 text-sm sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900">
                        <template x-if="event.location_view_url">
                            <a data-putaway-history-location :href="event.location_view_url" target="_blank" rel="noopener" aria-description="Új lapon nyílik"
                               class="rounded text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600" x-text="event.location_code"></a>
                        </template>
                        <template x-if="!event.location_view_url"><span x-text="event.location_code"></span></template>
                        · <span x-text="pieces(event.quantity)"></span>
                    </p>
                    <p x-show="event.location_name !== event.location_code" class="text-gray-700" x-text="event.location_name"></p>
                    <p class="text-xs text-gray-600"><span x-text="event.actor_name"></span> · <time :datetime="event.placed_at" x-text="timestamp(event.placed_at)"></time></p>
                </div>
                <div class="min-w-0">
                    <p class="text-gray-800"><span x-text="mass(event.cargo_weight_grams)"></span> · <span x-text="slots(event.pallet_slots)"></span></p>
                    <p class="text-xs text-gray-600" x-text="event.geometry_status === 'site_verified' ? 'Helyszínen ellenőrzött elférés' : 'Ellenőrzött méretek'"></p>
                    <p x-show="event.note" class="mt-1 whitespace-pre-wrap text-gray-700" x-text="event.note"></p>
                </div>
            </li>
        </template>
    </ol>
    <nav x-show="history.last_page > 1" aria-label="Betárolási előzmények lapozása" class="flex flex-wrap items-center gap-2">
        <button type="button" @click="loadHistory(history.current_page - 1)" :disabled="historyLoading || history.current_page <= 1" class="{{ $secondary }}">Előző oldal</button>
        <span class="text-sm text-gray-700" x-text="history.current_page + ' / ' + history.last_page"></span>
        <button type="button" @click="loadHistory(history.current_page + 1)" :disabled="historyLoading || history.current_page >= history.last_page" class="{{ $secondary }}">Következő oldal</button>
    </nav>
</section>
