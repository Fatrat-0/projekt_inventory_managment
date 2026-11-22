<x-warehouse-layout :$warehouse :context="$moduleContext['context'] ?? ''" :detail-context="$moduleContext['detail_context'] ?? ''">
    @php
        $config = [
            'warehouses' => $warehouses,
            'warehouseId' => $warehouse ? (string) $warehouse->getKey() : '',
            'fromId' => $from ? (string) $from->id : '',
            'initialProduct' => $initialProduct,
            'sources' => route('warehouse-relocations.sources'),
            'candidates' => route('warehouse-relocations.candidates'),
            'preview' => route('warehouse-relocations.preview'),
            'store' => route('warehouse-relocations.store'),
        ];
        $history = $warehouse ? route('warehouses.relocations.index', ['warehouse' => $warehouse] + $moduleContext) : route('warehouse-relocation-pages.index');
        $new = $warehouse ? route('warehouses.relocations.create', ['warehouse' => $warehouse] + $moduleContext) : route('warehouse-relocation-pages.create');
        $detail = route('warehouses.relocations.show', ['warehouse' => '__warehouse__', 'relocation' => '__event__'] + $moduleContext);
        $panel = 'min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5';
        $field = 'min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100';
        $secondary = 'inline-flex min-h-11 max-w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600';
        $primary = 'inline-flex min-h-11 max-w-full items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';
        $link = 'inline-flex min-h-11 max-w-full items-center rounded text-sm font-medium text-indigo-700 underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600';
    @endphp
    <div class="mx-auto w-full max-w-[85rem] space-y-4 px-4 py-4 [overflow-wrap:anywhere] sm:px-6" x-data="warehouseRelocation(@js($config))">
        <x-page-toolbar title="Új belső áthelyezés" :description="$warehouse?->warehouse_name">
            <a href="{{ $history }}" class="{{ $secondary }}">Vissza az előzményekhez</a>
        </x-page-toolbar>
        <noscript><p class="{{ $panel }}">Az áthelyezés rögzítéséhez engedélyezd a JavaScriptet.</p></noscript>

        <div x-cloak x-show="!success" class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <fieldset :disabled="draftLocked" class="min-w-0 space-y-4">
                <legend class="sr-only">Áthelyezés szerkesztése</legend>
                <section class="{{ $panel }}" aria-labelledby="relocation-source-title">
                    <h2 id="relocation-source-title" class="font-semibold text-gray-900">Forrás</h2>
                    <div class="grid min-w-0 gap-3 sm:grid-cols-2">
                        <div class="min-w-0" @relocation-warehouse="warehouseChanged($event.detail.value)">
                            @if($warehouse)<p class="mb-1 text-sm font-medium text-gray-800">Raktár</p>@else<label for="relocation-warehouse" class="mb-1 block text-sm font-medium text-gray-800">Raktár</label>@endif
                            @if($warehouse)
                                <p id="relocation-warehouse" class="flex min-h-11 items-center rounded-md bg-gray-50 px-3 py-2 text-sm font-medium">{{ $warehouse->warehouse_name }}</p>
                            @else
                                <x-autocomplete-picker :items="[]" id="relocation-warehouse" name="warehouse_id"
                                    items-source="warehouseOptions" model-value="warehouseId" event-name="relocation-warehouse" :max-results="16"
                                    empty-message="requests.warehouses ? 'A jogosult raktárak keresése…' : 'Nincs megfelelő raktár.'" placeholder="Raktár neve…" />
                            @endif
                        </div>
                        <div class="min-w-0" @relocation-product="productChanged($event.detail.value)" @relocation-product-search="queueProducts($event.detail.query)">
                            <label for="relocation-product" class="mb-1 block text-sm font-medium text-gray-800">Termék</label>
                            <x-autocomplete-picker :items="[]" id="relocation-product" name="product_id"
                                items-source="productOptions" model-value="productId" event-name="relocation-product" search-event="relocation-product-search" :max-results="16"
                                empty-message="requests.products ? 'Termékek keresése…' : 'Nincs áthelyezhető rekeszkészlettel rendelkező találat.'" placeholder="Terméknév vagy SKU…" />
                            <p x-show="productError" role="alert" class="mt-1 text-xs text-red-800" x-text="productError"></p>
                            <button x-show="productError" @click="loadProducts()" type="button" class="{{ $link }}">Termékkeresés újrapróbálása</button>
                        </div>
                        <div class="min-w-0" @relocation-source="sourceChanged($event.detail.value)">
                            @if($from)<p class="mb-1 text-sm font-medium text-gray-800">Forrásrekesz</p>@else<label for="relocation-source" class="mb-1 block text-sm font-medium text-gray-800">Forrásrekesz</label>@endif
                            @if($from)
                                <div id="relocation-source" class="flex min-h-11 flex-wrap items-center justify-between gap-x-2 rounded-md bg-gray-50 px-3 py-2 text-sm">
                                    <p class="font-medium">{{ $from->code }}{{ $from->name !== $from->code ? ' · '.$from->name : '' }}</p>
                                    <a href="{{ $new }}" class="text-sm font-medium text-indigo-700 underline">Másik forrásrekesz</a>
                                </div>
                            @else
                                <x-autocomplete-picker :items="[]" id="relocation-source" name="from_location_id"
                                    items-source="sourceOptions" model-value="sourceId" event-name="relocation-source" details-source="sourceDetails"
                                    clear-label="Forrásrekesz törlése" results-label="Forrásrekesz-találatok" :max-results="20"
                                    empty-message="refreshing ? 'Forrásrekeszek betöltése…' : (warehouseId && productId ? 'Nincs használható forrásrekesz.' : 'Előbb válassz raktárat és terméket.')" placeholder="Rekeszkód, zóna vagy állvány…" />
                            @endif
                            <p x-show="source" class="mt-1 text-xs text-gray-700" x-text="source ? sourceDetails[source.location_id] : ''"></p>
                        </div>
                        <div class="min-w-0">
                            <label for="relocation-quantity" class="mb-1 block text-sm font-medium text-gray-800" x-text="boxMode ? 'Áthelyezendő mennyiség (doboz)' : 'Áthelyezendő mennyiség (db)'"></label>
                            <div class="flex flex-wrap items-center gap-2">
                                <input id="relocation-quantity" type="text" inputmode="numeric" maxlength="10" :value="quantityDraft"
                                    @input="editQuantity($event.target.value)" :disabled="!source" aria-describedby="relocation-max" class="{{ $field }} flex-1 basis-24">
                                <div x-show="packaging" role="group" aria-label="Mennyiség egysége" class="flex gap-1">
                                    <button type="button" :aria-pressed="!boxMode" @click="usePieces()" :class="!boxMode && 'border-indigo-500 bg-indigo-50'" class="{{ $secondary }}">Darab</button>
                                    <button type="button" :aria-pressed="boxMode" @click="useBoxes()" :class="boxMode && 'border-indigo-500 bg-indigo-50'" class="{{ $secondary }}">Doboz</button>
                                </div>
                            </div>
                            <p id="relocation-max" x-show="source" class="mt-1 text-xs text-gray-600" x-text="'Áthelyezhető legfeljebb ' + pieces(maxMovable) + '.'"></p>
                            <p x-show="packaging" class="mt-1 text-xs text-gray-700" x-text="packaging ? '1 doboz = ' + pieces(packaging.pieces) + (boxMode && quantity ? ' · Kijelölve: ' + pieces(quantity) : '') : ''"></p>
                            <p x-show="quantityInputError || (quantity && amount === null)" class="mt-1 text-xs text-red-800" x-text="quantityInputError || 'A megadott mennyiség meghaladja a forrásból elérhető készletet.'"></p>
                            <p x-show="confirmBoxChange" class="mt-1 text-xs text-amber-900">A mennyiség nem fejezhető ki egész dobozokban. A darabszám megmaradt.</p>
                            <button type="button" x-show="source" :disabled="!maxMovable" @click="useMaximum()" class="{{ $link }}">Teljes mennyiség</button>
                        </div>
                    </div>
                    <p x-show="notice" role="status" class="text-sm text-amber-900" x-text="notice"></p>
                    <p x-show="sourceError" role="alert" class="text-sm text-red-800" x-text="sourceError"></p>
                    <button x-show="sourceError && productId" type="button" @click="filterWarehouses(); if (warehouseId) loadSource()" class="{{ $link }}">Forrásadatok újratöltése</button>
                </section>

                <section class="{{ $panel }}" aria-labelledby="relocation-cargo-title">
                    <h2 id="relocation-cargo-title" class="font-semibold text-gray-900">Rakományadatok</h2>
                    <p x-show="!cargo && !cargoError" class="text-sm text-gray-600" role="status"
                        x-text="cargoBasis ? 'A rakományadatok ellenőrzése…' : 'A forrásrekesz és a darabszám alapján jelennek meg a rakományadatok.'"></p>
                    <p x-show="cargoError" role="alert" class="text-sm text-red-800" x-text="cargoError"></p>
                    <button x-show="cargoError && !requiresRefresh" type="button" @click="loadCargo()" class="{{ $link }}">Rakományadatok újrapróbálása</button>
                    <div x-show="cargo" class="space-y-3">
                        <dl x-show="cargo && !measured" class="grid grid-cols-2 gap-3 rounded-md bg-indigo-50 p-3 text-sm">
                            <div><dt class="text-indigo-950">Nyilvántartott bruttó tömeg</dt><dd class="font-semibold" x-text="mass(cargo?.cargo.cargo_weight_grams)"></dd></div>
                            <div><dt class="text-indigo-950">Nyilvántartott raklaphelyigény</dt><dd class="font-semibold" x-text="slots(cargo?.cargo.pallet_slots)"></dd></div>
                        </dl>
                        <div x-show="measured" class="grid gap-3 sm:grid-cols-2">
                            <div><label for="relocation-weight" class="mb-1 block text-sm font-medium">Mért bruttó tömeg (kg)</label>
                                <input id="relocation-weight" x-model="weightValue" @input="invalidateLocations()" type="text" inputmode="decimal" maxlength="12" class="{{ $field }}"></div>
                            <div><label for="relocation-pallets" class="mb-1 block text-sm font-medium">Raklaphelyigény</label>
                                <input id="relocation-pallets" x-model="pallets" @input="invalidateLocations()" type="text" inputmode="numeric" maxlength="7" aria-describedby="relocation-pallets-help" class="{{ $field }}">
                                <p id="relocation-pallets-help" class="mt-1 text-xs text-gray-600">A 0 nem raklapos rakományt jelöl. Az üres érték ismeretlen.</p></div>
                        </div>

                        <label x-show="cargo?.cargo.source_verification_required" class="flex min-h-11 items-start gap-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                            <input type="checkbox" x-model="sourceVerified" class="mt-0.5 rounded border-gray-300 text-indigo-600"> Helyszínen ellenőriztem a mozgatott rakomány forrásterhelését.
                        </label>
                        <details class="rounded-md border border-gray-200">
                            <summary class="min-h-11 cursor-pointer px-3 py-2.5 text-sm font-medium">Külső méretek (cm)</summary>
                            <div class="grid gap-2 px-3 pb-3 sm:grid-cols-3">
                                @foreach(['width' => 'Szélesség', 'depth' => 'Mélység', 'height' => 'Magasság'] as $axis => $label)
                                    <div><label for="relocation-{{ $axis }}" class="mb-1 block text-sm">{{ $label }} (cm)</label>
                                        <input id="relocation-{{ $axis }}" x-model="dimensions.{{ $axis }}" @input="invalidateLocations()" type="text" inputmode="numeric" maxlength="5" class="{{ $field }}"></div>
                                @endforeach
                            </div>
                        </details>
                    </div>
                    <p x-show="physicalErrors" role="alert" class="text-sm text-red-800" x-text="physicalErrors"></p>
                </section>

                @include('warehouse-putaway.locations', [
                    'floorPlanField' => 'floor_plan_url',
                    'statusExpression' => 'targetStatus(location)',
                    'selectedStatusExpression' => 'targetStatus(selected)',
                    'showStockInSideSummary' => true,
                    'allowSourceRefresh' => true,
                ])

                <section class="{{ $panel }}" aria-labelledby="relocation-notes-title">
                    <h2 id="relocation-notes-title" class="font-semibold text-gray-900">Megjegyzés</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div><label for="relocation-reason" class="mb-1 block text-sm font-medium">Indok (opcionális)</label>
                            <input id="relocation-reason" type="text" maxlength="255" x-model="reasonText" class="{{ $field }}"></div>
                        <div><label for="relocation-note" class="mb-1 block text-sm font-medium">Megjegyzés (opcionális)</label>
                            <textarea id="relocation-note" rows="2" maxlength="500" x-model="note" class="{{ $field }}"></textarea></div>
                    </div>
                </section>
            </fieldset>

            <aside class="{{ $panel }} lg:sticky lg:top-[calc(var(--app-topbar-height,4rem)_+_1rem)]" aria-labelledby="relocation-summary-title">
                <h2 id="relocation-summary-title" class="font-semibold text-gray-900">Áthelyezés összegzése</h2>
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-xs text-gray-600">Raktár</dt><dd x-text="warehouseName"></dd></div>
                    <div><dt class="text-xs text-gray-600">Termék</dt><dd x-text="chosenProduct?.label ?? '—'"></dd></div>
                    <div class="grid grid-cols-2 gap-2 border-y border-gray-100 py-2">
                        <div><dt class="text-xs text-gray-600">Forrásrekesz</dt><dd class="font-semibold" x-text="source?.code ?? '—'"></dd></div>
                        <div><dt class="text-xs text-gray-600">Célrekesz</dt><dd class="font-semibold [overflow-wrap:anywhere]" x-text="selected?.code ?? '—'"></dd></div>
                        <div><dt class="text-xs text-gray-600">Forrásban maradna</dt><dd x-text="pieces(amount === null ? null : source.stored_quantity - amount)"></dd></div>
                        <div x-show="showTargetStock" data-relocation-target-stock aria-live="polite">
                            <dt class="text-xs text-gray-600">Ebből a termékből a célrekeszben</dt>
                            <dd class="font-semibold" x-text="targetStockCaption"></dd>
                        </div>
                    </div>
                    <div class="flex justify-between gap-2"><dt>Áthelyezendő</dt><dd class="font-semibold" x-text="pieces(amount)"></dd></div>
                    <div class="flex justify-between gap-2 rounded-md bg-emerald-50 p-2 text-emerald-950"><dt>Raktári készletváltozás</dt><dd class="font-semibold">0 db</dd></div>
                </dl>
                @include('warehouse-relocations.checks', ['phase' => 'draft'])
                <div aria-live="polite" class="space-y-2 text-sm">
                    <p x-show="nextStep && !pendingCommand" class="text-gray-700" x-text="nextStep"></p>
                    <p x-show="reviewError || submissionError" role="alert" class="text-red-800" x-text="reviewError || submissionError"></p>
                    <ul x-show="blockers.length || Object.keys(fieldErrors).length" class="list-disc space-y-1 ps-5 text-amber-900">
                        <template x-for="message in [...new Set([...blockers, ...checks(fieldErrors).flat()])]" :key="message"><li x-text="message"></li></template>
                    </ul>
                </div>
                <label x-show="siteRequired" class="flex items-start gap-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                    <input type="checkbox" x-model="siteVerified" :disabled="draftLocked" class="mt-0.5 rounded border-gray-300 text-indigo-600">
                    A helyszínen ellenőriztem, hogy a rakomány elfér a célrekeszben.
                </label>
                <button type="button" x-show="requiresRefresh && !lookupError && !pendingCommand" @click="refresh()" :disabled="refreshing" class="{{ $secondary }} w-full">Forrás és cél frissítése</button>
                <button type="button" x-show="!pendingCommand" x-ref="reviewButton" @click="review()" :disabled="!canReview" aria-haspopup="dialog" class="{{ $primary }} w-full"
                        x-text="requests.preview ? 'Ellenőrzés folyamatban…' : 'Áthelyezés ellenőrzése'"></button>
                <button type="button" x-show="pendingCommand" x-ref="retryButton" @click="openReviewedDialog()" class="{{ $primary }} w-full">Mentés újrapróbálása</button>
            </aside>
        </div>

        <section x-cloak x-show="success" x-ref="success" tabindex="-1" role="status" class="min-w-0 space-y-3 rounded-lg border border-emerald-200 bg-white p-4 sm:p-5">
            <h2 class="text-lg font-semibold text-emerald-950" x-text="success && pieces(success.quantity) + ' sikeresen áthelyezve: ' + success.from_location_code + ' → ' + success.to_location_code + '.'"></h2>
            <p x-text="success?.reference" class="text-sm font-medium text-emerald-900"></p>
            <div class="flex flex-wrap gap-2">
                <a :href="success ? @js($detail).replace('__warehouse__', success.warehouse_id).replace('__event__', success.id) : null" class="{{ $primary }}">Áthelyezés adatlapja</a>
                <a x-show="selected?.view_url" :href="selected?.view_url" target="_blank" rel="noopener" class="{{ $secondary }}">Célrekesz megtekintése<span class="sr-only"> (új lapon)</span></a>
                <a href="{{ $new }}" class="{{ $secondary }}">Új áthelyezés</a>
            </div>
        </section>

        @include('warehouse-relocations.confirmation')
    </div>
</x-warehouse-layout>
