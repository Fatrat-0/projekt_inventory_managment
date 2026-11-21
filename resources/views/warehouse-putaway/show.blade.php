<x-warehouse-task-layout>
    @php
        $item = $detail['item'];
        $manual = $manual ?? false;
        $actions = ['detail' => 'show', 'history' => 'history', 'recommendations' => 'recommendations', 'locations' => 'locations',
            'store' => $manual ? 'putaway' : 'store'] + ($manual ? ['preview' => 'preview'] : []);
        $config = [
            'detail' => $detail,
            'history' => $history->toArray(),
            'urls' => collect($actions)->map(fn ($action) => route(($manual ? 'movements.in.receipts.' : 'shipment-receipt-items.putaway.').$action, $item['id']))->all(),
        ];
        $panel = 'min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-3 sm:p-4';
        $secondary = 'inline-flex min-h-11 max-w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $primary = 'inline-flex min-h-11 max-w-full items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-left text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $link = 'inline-flex min-h-11 max-w-full items-center rounded text-sm font-medium text-indigo-700 underline underline-offset-2 hover:text-indigo-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600';
        $field = 'min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100';
    @endphp
    <div class="app-page space-y-4 py-4 [overflow-wrap:anywhere]" data-putaway-workspace x-data="warehousePutaway(@js($config))"
         @pageshow.window="if ($event.persisted && !draftLocked) refresh()">
        <x-page-toolbar title="Betárolás">
            <a href="{{ route('warehouse-putaway-pages.index') }}" class="{{ $secondary }}">Vissza a betárolási feladatokhoz</a>
        </x-page-toolbar>

        <p x-cloak x-show="accessError" x-text="accessError" role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900"></p>

        <div x-show="!accessError" class="space-y-4">
            <header class="{{ $panel }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="mb-1 text-xs text-gray-600">{{ $item['sku'] }} · {{ $manual ? 'Kézi bevételezés' : $item['intent_reference'] }}</p>
                        <h2 class="text-lg font-semibold text-gray-900 sm:text-xl">{{ $item['product_name'] }}</h2>
                        <p class="mt-1 text-sm text-gray-700">{{ $item['warehouse_name'] }}@unless($manual) · Átvétel #{{ $item['receipt_id'] }} · Tétel #{{ $item['id'] }}@endunless</p>
                    </div>
                    <p x-text="itemStatus(item.status)" class="rounded-md bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-900"></p>
                </div>

                <dl class="grid min-w-0 gap-2 sm:grid-cols-3" aria-label="Átvett és betárolt mennyiségek">
                    <div class="rounded-md bg-gray-50 px-3 py-2"><dt class="text-xs text-gray-600">Átvett</dt><dd x-text="pieces(item.received_quantity)" class="font-semibold tabular-nums text-gray-900"></dd></div>
                    <div class="rounded-md bg-gray-50 px-3 py-2"><dt class="text-xs text-gray-600">Már betárolt</dt><dd x-text="pieces(item.placed_quantity)" class="font-semibold tabular-nums text-gray-900"></dd></div>
                    <div class="rounded-md bg-indigo-50 px-3 py-2"><dt class="text-xs text-indigo-900">Betárolásra vár</dt><dd data-putaway-remaining x-text="pieces(item.pending_putaway_quantity)" class="font-semibold tabular-nums text-indigo-950"></dd></div>
                </dl>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    @if($manual)
                    <span class="text-xs text-gray-600">Átvette {{ $item['received_by_name'] }} · {{ \Illuminate\Support\Carbon::parse($item['received_at'])->timezone('Europe/Budapest')->format('Y. m. d. H:i') }}</span>
                    <a href="{{ $item['movement_url'] }}" class="{{ $link }}">{{ $item['movement_number'] }} bevételezés</a>
                    @else
                    <span class="text-xs text-gray-600">Feladva {{ number_format($item['dispatched_quantity'], 0, ',', ' ') }} db</span>
                    <span class="text-xs text-gray-600">Átvette {{ $detail['receipt']['actor_name'] }} · {{ \Illuminate\Support\Carbon::parse($detail['receipt']['received_at'])->timezone('Europe/Budapest')->format('Y. m. d. H:i') }}</span>
                    <a href="{{ $receivingUrl }}" class="{{ $link }}">Átvételi munkalap</a>
                    <a href="{{ $shipmentUrl }}" class="{{ $link }}">{{ $detail['shipment']['shipment_number'] }} fuvar</a>
                    @endif
                </div>
                @if ($note = $manual ? $item['reference'] : ($item['note'] ?? null))
                    <p class="whitespace-pre-wrap rounded-md bg-gray-50 p-3 text-sm text-gray-800">{{ $note }}</p>
                @endif
            </header>

            <div data-putaway-success x-cloak x-show="success" x-ref="success" tabindex="-1" role="status"
                 class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950">
                <p><strong x-text="pieces(success?.quantity)"></strong> {{ $manual ? 'sikeresen betárolva' : 'betárolva' }} a(z) <strong x-text="success?.location_code"></strong> rekeszbe.
                    @if($manual)<span x-text="eligible ? 'Hátralévő: ' + pieces(item.pending_putaway_quantity) + '.' : 'A bevételezés teljes mennyisége betárolva.'"></span>@endif
                </p>
                <template x-if="success?.location_view_url">
                    <a data-putaway-success-location :href="success.location_view_url" target="_blank" rel="noopener" aria-description="Új lapon nyílik"
                       class="{{ $link }}">Rekesz megtekintése</a>
                </template>
                @if($manual)
                    <button x-show="eligible" type="button" @click="$refs.portion?.focus()" class="{{ $secondary }}">További betárolás</button>
                @endif
                <a href="{{ route('warehouse-putaway-pages.index') }}" class="{{ $link }}">Másik betárolási feladat választása</a>
            </div>
            <div x-cloak x-show="submissionError || needsRefresh" x-ref="feedback" tabindex="-1"
                 class="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                <p role="alert" x-text="submissionError || 'A tétel időközben megváltozott. Töltsd be a friss állapotot.'"></p>
                <ul x-show="Object.keys(fieldErrors).length" class="list-disc space-y-1 ps-5">
                    <template x-for="(messages, key) in fieldErrors" :key="key"><li x-text="messages[0]"></li></template>
                </ul>
                <button type="button" x-show="needsRefresh && !pendingCommand" @click="refresh()" :disabled="refreshing" class="{{ $secondary }}">Tétel frissítése</button>
                <button type="button" x-show="pendingCommand" @click="retry()" :disabled="submitting" class="{{ $primary }}">Mentés újrapróbálása</button>
            </div>

            <div style="display: none" x-show="eligible" class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
                <div class="min-w-0 space-y-4">
                    <section class="{{ $panel }}" aria-labelledby="putaway-quantity-title">
                        <h3 id="putaway-quantity-title" class="font-semibold text-gray-900">Betárolandó részlet</h3>
                        <div class="grid min-w-0 gap-3 sm:grid-cols-2">
                            <div>
                                <label for="putaway-quantity" class="mb-1 block text-sm font-medium text-gray-900">Most betárolandó mennyiség (db)</label>
                                <input id="putaway-quantity" x-ref="portion" data-putaway-quantity x-model="quantity" @input="changeCargo()" type="text" inputmode="numeric"
                                       maxlength="10" aria-describedby="putaway-quantity-help" :aria-invalid="String(Boolean(quantityError))" :disabled="draftLocked || refreshing" class="{{ $field }}">
                                <p id="putaway-quantity-help" class="mt-1 text-xs" :class="quantityError ? 'text-red-800' : 'text-gray-600'"
                                   x-text="quantityError || ('Legfeljebb ' + pieces(item.pending_putaway_quantity) + ' helyezhető el.')"></p>
                            </div>
                            <div>
                                <label for="putaway-note" class="mb-1 block text-sm font-medium text-gray-900">Megjegyzés <span class="font-normal text-gray-600">(opcionális)</span></label>
                                <textarea id="putaway-note" x-model="note" :disabled="draftLocked || refreshing" maxlength="500" rows="2" class="{{ $field }}"></textarea>
                            </div>
                        </div>
                    </section>
                    @include('warehouse-putaway.cargo')
                    @include('warehouse-putaway.locations')
                </div>

                <aside class="{{ $panel }} lg:sticky lg:top-[calc(var(--app-topbar-height,4rem)_+_1rem)]" aria-labelledby="putaway-summary-title">
                    <h3 id="putaway-summary-title" class="font-semibold text-gray-900">Betárolás összegzése</h3>
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-xs text-gray-600">Termék és mennyiség</dt><dd class="font-semibold text-gray-900">{{ $item['product_name'] }} · <span x-text="pieces(quantityValue)"></span></dd></div>
                        <div><dt class="text-xs text-gray-600">Célraktár</dt><dd class="font-medium text-gray-900">{{ $item['warehouse_name'] }}</dd></div>
                        <div><dt class="text-xs text-gray-600">Célrekesz</dt><dd class="font-medium text-gray-900" x-text="selected?.code ?? 'Még nincs kiválasztva'"></dd></div>
                        <div class="grid min-w-0 grid-cols-2 gap-2 border-t border-gray-100 pt-2">
                            <div><dt class="text-xs text-gray-600">Bruttó tömeg</dt><dd x-text="mass(weightValue)"></dd></div>
                            <div><dt class="text-xs text-gray-600">Raklaphelyigény</dt><dd x-text="slots(palletValue)"></dd></div>
                        </div>
                        <div><dt class="text-xs text-gray-600">Betárolás után fennmaradna</dt><dd x-text="pieces(quantityValue === null ? null : item.pending_putaway_quantity - quantityValue)"></dd></div>
                    </dl>
                    <p x-show="nextStep" x-text="nextStep" class="rounded-md bg-gray-50 p-2 text-sm text-gray-700"></p>
                    <button type="button" x-ref="reviewButton" data-putaway-review :disabled="!canReview || previewing" @click="openConfirmation()" aria-haspopup="dialog" class="{{ $primary }} w-full"
                        x-text="previewing ? 'Ellenőrzés folyamatban…' : 'Betárolás ellenőrzése'">Betárolás ellenőrzése</button>
                </aside>
            </div>

            @if($manual)<p x-show="!item.can_putaway && item.status === 'completed' && !success" class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-950">A bevételezés teljes mennyisége betárolva.</p>@endif
            @include('warehouse-putaway.history')
        </div>

        @include('warehouse-putaway.confirmation')
    </div>
</x-warehouse-task-layout>
