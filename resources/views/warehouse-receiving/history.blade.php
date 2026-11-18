<section aria-labelledby="receiving-history-heading" data-testid="receiving-history" class="{{ $panel }} [overflow-wrap:anywhere]">
    <h2 id="receiving-history-heading" class="font-semibold text-gray-900">Átvételi előzmények</h2>
    <p x-show="!history.data.length && !historyError" class="mt-2 text-sm text-gray-600">Ehhez a megállóhoz még nincs átvételi bizonylat.</p>
    <div x-show="historyError" class="mt-2 rounded-md bg-amber-50 p-2 text-sm text-amber-900" role="alert">
        <p x-text="historyError"></p>
        <button type="button" @click="loadHistory(history.current_page)" class="{{ $link }}">Előzmények újratöltése</button>
    </div>
    <div class="mt-2 divide-y divide-gray-200">
        <template x-for="(receipt, index) in history.data" :key="receipt.id">
            <details :open="index === 0" class="min-w-0 py-2">
                <summary class="min-h-11 cursor-pointer rounded py-2 text-sm text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                    <span class="font-semibold text-gray-900" x-text="'Átvételi bizonylat #' + receipt.id"></span>
                    <span aria-hidden="true"> · </span><span x-text="formatDate(receipt.received_at)"></span>
                    <span aria-hidden="true"> · </span><span x-text="receipt.actor_name"></span>
                </summary>
                <p x-show="receipt.note" class="mb-2 text-sm text-gray-700" x-text="receipt.note"></p>
                <ul class="space-y-2">
                    <template x-for="item in receipt.items" :key="item.id">
                        <li class="grid min-w-0 gap-x-3 gap-y-2 rounded-md bg-gray-50 p-2 text-sm sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                            <div class="min-w-0">
                                <p class="font-medium" x-text="item.product_name"></p>
                                <p class="text-xs text-gray-700"><span x-text="item.sku"></span> <span aria-hidden="true">·</span> Igény <span class="font-mono" x-text="item.intent_reference"></span></p>
                                <p x-show="item.note" class="mt-1 text-gray-700" x-text="item.note"></p>
                            </div>
                            <dl class="min-w-0 text-xs leading-5 tabular-nums text-gray-700">
                                <div x-show="itemFor(item.transit_item_id)"><dt class="inline">Feladva</dt> <dd class="inline font-medium" x-text="quantity(itemFor(item.transit_item_id)?.dispatched_quantity ?? 0)"></dd></div>
                                <div><dt class="inline">Ekkor átvéve</dt> <dd class="inline font-semibold" x-text="quantity(item.received_quantity)"></dd></div>
                                <div x-show="item.remaining_quantity_after_receipt !== null"><dt class="inline">Ekkor úton maradt</dt> <dd class="inline font-medium" x-text="quantity(item.remaining_quantity_after_receipt)"></dd></div>
                                <div><dt class="inline">Most betárolásra vár</dt> <dd class="inline font-medium" x-text="quantity(item.pending_putaway_quantity)"></dd></div>
                            </dl>
                            <a :href="putawayUrl(item.id)" class="{{ $link }}" x-text="item.can_putaway ? 'Betárolás megnyitása' : 'Betárolási előzmények'"></a>
                        </li>
                    </template>
                </ul>
            </details>
        </template>
    </div>
    <nav x-show="history.last_page > 1" aria-label="Átvételi előzmények lapozása" class="mt-3 flex flex-wrap items-center gap-2 text-sm">
        <button type="button" @click="loadHistory(history.current_page - 1)" :disabled="!!historyRequest || history.current_page <= 1" class="{{ $button }}">Előző</button>
        <span x-text="history.current_page + ' / ' + history.last_page + ' oldal'"></span>
        <button type="button" @click="loadHistory(history.current_page + 1)" :disabled="!!historyRequest || history.current_page >= history.last_page" class="{{ $button }}">Következő</button>
    </nav>
</section>

