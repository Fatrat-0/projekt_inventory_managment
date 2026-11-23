@php
    $primary = 'inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:opacity-50';
    $secondary = 'inline-flex min-h-11 items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:opacity-50';
    $field = 'mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm aria-[invalid=true]:border-rose-600 disabled:bg-gray-100';
@endphp
<x-warehouse-layout :$warehouse :context="$params['context'] ?? ''" :detail-context="$params['detail_context'] ?? ''">
    <div class="app-page min-w-0 space-y-4 py-4 [overflow-wrap:anywhere]" x-data="warehouseLoadCheck(@js($worksheet), @js($api))"
         @pageshow.window="if ($event.persisted && notice?.kind === 'logged') notice = null" data-load-worksheet>
        <a href="{{ route('warehouses.load-checks.index', $params) }}" class="inline-flex min-h-10 items-center text-sm font-medium text-indigo-700 hover:underline">← Terhelésellenőrzések</a>
        <x-page-toolbar title="Terhelés ellenőrzése" :description="$warehouse->warehouse_name">
            <a href="{{ $locationUrl }}" class="{{ $secondary }}">Rekesz adatlapja</a>
            @if($planUrl)<a href="{{ $planUrl }}" class="{{ $secondary }}">Megjelenítés az alaprajzon</a>@endif
            <a x-show="!openDiscrepancy" href="{{ $historyUrl }}" class="{{ $secondary }}">Mérési előzmények</a>
        </x-page-toolbar>

        <div x-cloak x-show="notice" x-ref="notice" tabindex="-1" role="status" data-load-notice
             :class="{
                 'rounded-md border-gray-200 bg-gray-50 px-3 py-2 text-gray-800': notice?.kind === 'logged',
                 'rounded-lg border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900': notice?.kind === 'success',
                 'rounded-lg border-amber-300 bg-amber-50 px-4 py-3 text-amber-950': notice?.kind === 'warning',
                 'rounded-lg border-rose-200 bg-rose-50 px-4 py-3 text-rose-900': notice?.kind === 'error',
             }"
             class="border text-sm font-medium" x-text="notice?.text"></div>
        <p x-cloak x-show="refreshing" role="status" class="text-sm text-gray-700">Az aktuális állapot betöltése…</p>
        <div x-cloak x-show="requiresRefresh && !refreshing" class="flex flex-wrap items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
            <span>Frissítsd a tétel adatait a folytatáshoz.</span>
            <button type="button" class="{{ $secondary }}" @click="refresh()">Állapot frissítése</button>
        </div>

        <section x-cloak x-show="openDiscrepancy" data-open-load-warning tabindex="-1" x-ref="discrepancySummary"
                 aria-labelledby="load-discrepancy-title" class="space-y-3 rounded-lg border p-4 sm:p-5"
                 :class="critical ? 'border-rose-300 bg-rose-50 text-rose-950' : 'border-amber-300 bg-amber-50 text-amber-950'">
            <div class="flex items-start gap-2">
                <x-icon name="warning" class="mt-0.5 h-5 w-5" />
                <div class="min-w-0">
                    <h2 id="load-discrepancy-title" class="font-semibold"
                        x-text="critical ? 'Kritikus terhelési eltérés – beavatkozás szükséges' : 'Nyitott terhelési eltérés – új ellenőrzés szükséges'"></h2>
                    <p class="mt-1 text-sm" x-text="critical
                        ? 'A dokumentált mérés kapacitáskorlát megsértését igazolta. A tárolóhely további terhelése nem engedélyezett.'
                        : 'A mérés óta változott a készlet. A korábbi mérés nem igazolja a jelenlegi terhelést. A korlátozás az új ellenőrzésig érvényben marad.'"></p>
                </div>
            </div>
            <p x-show="critical && !documentedLimits.length" class="text-sm font-medium" x-text="ruleList(row.discrepancy?.violations)"></p>
            <template x-for="scope in documentedLimits" :key="scope.key">
                <div class="rounded-md border border-rose-200 bg-white/70 p-3 text-sm">
                    <p class="mb-2 font-semibold" x-text="scope.label"></p>
                    <dl class="grid min-w-0 gap-2 sm:grid-cols-3">
                        <div><dt>Megengedett határ</dt><dd class="font-semibold" x-text="capacityValue(scope.limit, scope.unit)"></dd></div>
                        <div><dt>A mért tétellel számolt terhelés</dt><dd class="font-semibold" x-text="capacityValue(scope.projected, scope.unit)"></dd></div>
                        <div><dt>Túllépés</dt><dd class="font-bold" x-text="'+' + capacityValue(scope.excess, scope.unit)"></dd></div>
                    </dl>
                </div>
            </template>
        </section>

        <div class="grid min-w-0 items-start gap-4" :class="row.status !== 'verified' && !unavailable ? 'lg:grid-cols-[minmax(0,1fr)_minmax(0,0.95fr)]' : ''" data-load-layout>
            <section aria-labelledby="load-row-title" class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 id="load-row-title" class="font-semibold text-gray-900">Rekesztétel adatai</h2>
                    <span class="inline-flex items-center gap-1 rounded px-2 py-1 text-xs font-semibold"
                          :class="stateLabel === 'Ellenőrzött' ? 'bg-emerald-50 text-emerald-900' : (critical ? 'bg-rose-100 text-rose-950' : 'bg-amber-50 text-amber-950')">
                        <x-icon name="check-circle" x-show="stateLabel === 'Ellenőrzött'" />
                        <x-icon name="warning" x-show="stateLabel !== 'Ellenőrzött'" />
                        <span data-load-status x-text="stateLabel"></span>
                    </span>
                </div>
                <dl class="grid min-w-0 gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-gray-600">Termék</dt><dd class="font-medium" x-text="row.product.name"></dd><dd class="text-xs text-gray-600" x-text="row.product.sku"></dd></div>
                    <div><dt class="text-xs text-gray-600">Tárolóhely</dt><dd class="font-medium" x-text="row.location.code"></dd><dd class="text-xs text-gray-600" x-text="row.warehouse.name + ' · ' + locationLabel"></dd></div>
                    <div><dt class="text-xs text-gray-600">Aktuális mennyiség</dt><dd class="font-semibold" x-text="row.quantity + ' db'" data-current-quantity></dd></div>
                    <div><dt class="text-xs text-gray-600" x-text="openDiscrepancy ? 'Korábban nyilvántartott terhelés' : 'Nyilvántartott terhelés'"></dt>
                        <dd x-text="mass(openDiscrepancy ? row.discrepancy?.measurement.previous_weight_grams : row.recorded_weight_grams)"></dd>
                        <dd x-text="pallet(openDiscrepancy ? row.discrepancy?.measurement.previous_pallet_slots : row.recorded_pallet_slots)"></dd></div>
                    <div x-show="openDiscrepancy">
                        <dt class="text-xs text-gray-600" x-text="critical ? 'Legutóbb dokumentált mérés' : 'Legutóbb dokumentált mérés (korábbi állapot)'"></dt>
                        <dd class="font-semibold" x-text="mass(row.discrepancy?.measurement.measured_weight_grams) + ' · ' + pallet(row.discrepancy?.measurement.measured_pallet_slots)"></dd>
                        <dd class="text-xs text-gray-600" x-text="measuredAt(row.discrepancy?.measurement.measured_at)"></dd>
                    </div>
                </dl>
                <p x-show="row.verification_reason && !unavailable" class="border-t border-gray-100 pt-3 text-sm text-gray-700">
                    Az előző művelet után a korábbi terhelés maradt a nyilvántartásban. A tényleges terhelést új méréssel lehet megállapítani.
                </p>
                <details x-show="capacity && !unavailable" class="rounded-md border border-gray-200 p-3" :open="capacity && Object.keys(capacity.blocks).length > 0">
                    <summary class="cursor-pointer text-sm font-semibold text-gray-900">Aktuális kapacitás</summary>
                    <p x-show="capacity && Object.keys(capacity.blocks).length" class="mt-2 text-sm text-gray-700">
                        Az új elhelyezésre használható értékek erre a rekeszre vonatkoznak.
                    </p>
                    <ul class="mt-2 space-y-1 text-sm font-medium text-amber-950">
                        <template x-for="(message, key) in capacity?.blocks ?? {}" :key="key"><li x-text="message"></li></template>
                    </ul>
                    <dl class="mt-2 divide-y divide-gray-100 text-sm">
                        <template x-for="scope in currentCapacity" :key="scope.key">
                            <div class="min-w-0 py-2">
                                <dt class="font-medium" x-text="scope.label"></dt>
                                <dd class="text-gray-700" x-text="'Határ: ' + (scope.slots ? pallet(scope.limit) : mass(scope.limit)) + ' · Figyelembe vett terhelés: ' + (scope.slots ? pallet(scope.committed) : mass(scope.committed))"></dd>
                                <dd class="text-gray-700" x-text="'Számított kapacitásmaradék: ' + (scope.slots ? pallet(scope.remaining) : mass(scope.remaining))"></dd>
                                <dd class="font-medium" x-text="'Új elhelyezésre használható: ' + (scope.slots ? pallet(scope.free) : mass(scope.free))"></dd>
                            </div>
                        </template>
                    </dl>
                </details>
            </section>

            <div class="min-w-0 space-y-4" x-show="openDiscrepancy || showForm || preview">
                <section x-cloak x-show="openDiscrepancy" aria-labelledby="load-next-title"
                         class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5" data-load-next>
                    <h2 id="load-next-title" class="font-semibold text-gray-900">Mi a következő teendő?</h2>
                    <ol class="list-decimal space-y-1 ps-5 text-sm text-gray-800">
                        <li>Vizsgáld meg a tárolóhelyet. A fizikai műveletet csak biztonságos körülmények között kezdd el.</li>
                        <li x-show="row.placement_present">Szükség esetén csökkentsd a terhelést kitárolással vagy áthelyezéssel.</li>
                        <li x-text="row.placement_present ? 'A megmaradt teljes tételen végezz új helyszíni mérést.' : 'A helyszínen ellenőrizd, hogy a tételből semmi sem maradt a rekeszben.'"></li>
                        <li>Sikeres ellenőrzés után a felügyelő vagy az adminisztrátor lezárhatja az eltérést.</li>
                    </ol>
                    <div class="flex flex-wrap gap-2">
                        <a x-show="canRelocate && openDiscrepancy && !refreshing && !requiresRefresh" href="{{ $relocateUrl }}" class="{{ $primary }}">Áthelyezés indítása</a>
                        <button type="button" x-show="!measurementStarted && !refreshing && !requiresRefresh" class="{{ $secondary }}" @click="continueMeasurement()"
                                x-text="canConfirm ? 'Új mérés indítása' : 'Mérési előnézet indítása'"></button>
                        <a href="{{ $historyUrl }}" class="{{ $secondary }}">Mérési előzmények</a>
                    </div>
                </section>
                <section x-cloak x-show="showForm" aria-labelledby="load-measure-title" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                    <h2 id="load-measure-title" x-ref="measureTitle" tabindex="-1" class="font-semibold text-gray-900" x-text="emptyCheck ? 'Üres állapot ellenőrzése' : 'Mérési adatok'"></h2>
                    <p x-show="!emptyCheck" class="text-sm text-gray-700">A mérést a rekeszben ebből a tételből jelenleg megmaradt teljes mennyiségre kell elvégezni.</p>
                    <p x-show="emptyCheck" class="text-sm text-gray-700">A helyszínen ellenőrizd, hogy ebből a tételből semmi sem maradt a rekeszben. A mérés 0 kg és 0 raklaphely. A rekeszben lehetnek még más tételek.</p>
                    <p x-show="!canConfirm" class="rounded-md bg-slate-100 p-2 text-sm text-gray-700">Előnézetet kérhetsz. A mérést a raktár felügyelője vagy egy adminisztrátor rögzítheti.</p>
                    <form @submit.prevent="check()" novalidate class="space-y-3">
                        <div x-show="!emptyCheck" class="grid min-w-0 gap-3 sm:grid-cols-2">
                            <div class="min-w-0">
                                <label for="load-weight" class="text-sm font-medium">Mért teljes bruttó tömeg (kg)</label>
                                <input id="load-weight" x-ref="weight" name="cargo_weight_kg" type="text" inputmode="decimal" autocomplete="off"
                                       x-model="weight" @input="edit()" :disabled="!editable" :aria-invalid="!!errors.cargo_weight_kg" aria-describedby="load-weight-help load-weight-error" class="{{ $field }}">
                                <p id="load-weight-help" class="mt-1 text-xs text-gray-600">A teljes megmaradt rakomány tömege csomagolással együtt.</p>
                                <p id="load-weight-error" class="mt-1 text-sm text-rose-800" x-text="errors.cargo_weight_kg?.[0]"></p>
                            </div>
                            <div class="min-w-0">
                                <label for="load-slots" class="text-sm font-medium">Tényleges raklaphelyigény</label>
                                <input id="load-slots" name="pallet_slots" type="text" inputmode="numeric" autocomplete="off"
                                       x-model="slots" @input="edit()" :disabled="!editable" :aria-invalid="!!errors.pallet_slots" aria-describedby="load-slots-help load-slots-error" class="{{ $field }}">
                                <p id="load-slots-help" class="mt-1 text-xs text-gray-600">Csak akkor írj 0-t, ha az áru biztosan nem foglal raklaphelyet.</p>
                                <p id="load-slots-error" class="mt-1 text-sm text-rose-800" x-text="errors.pallet_slots?.[0]"></p>
                            </div>
                        </div>
                        <div>
                            <label for="load-note" class="text-sm font-medium">Megjegyzés <span class="font-normal text-gray-600">(opcionális)</span></label>
                            <textarea id="load-note" name="note" rows="2" maxlength="500" x-model="note" @input="edit()" :disabled="!editable"
                                      :aria-invalid="!!errors.note" aria-describedby="load-note-error" class="{{ $field }}"></textarea>
                            <p id="load-note-error" class="mt-1 text-sm text-rose-800" x-text="errors.note?.[0]"></p>
                        </div>
                        <ul x-show="generalErrors.length" class="list-disc space-y-1 pl-5 text-sm text-rose-800">
                            <template x-for="message in generalErrors" :key="message"><li x-text="message"></li></template>
                        </ul>
                        <div class="flex flex-wrap gap-2">
                            <button type="submit" :disabled="!editable || checking || requiresRefresh" class="{{ $secondary }}"
                                    x-text="checking ? 'Ellenőrzés folyamatban…' : (emptyCheck ? 'Üres állapot ellenőrzése' : 'Mérés ellenőrzése')"></button>
                            @if($worksheet['can_confirm'])
                                <button type="button" x-cloak x-show="retry" x-ref="retryButton" @click="openReview()" class="{{ $primary }}">Mentés újrapróbálása</button>
                            @endif
                        </div>
                    </form>
                </section>
                @include('warehouses.load-checks.preview')
            </div>
        </div>
        @if($worksheet['can_confirm'])@include('warehouses.load-checks.confirmation')@endif
    </div>
</x-warehouse-layout>
