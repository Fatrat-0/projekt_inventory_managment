<x-app-layout>
    @php
        $warehouseItems = $warehouses->map(fn ($warehouse) => [
            'value' => (string) $warehouse->warehouse_id,
            'label' => $warehouse->warehouse_name,
        ])->values();
        $form = [
            'endpoint' => route('transfer-intents.store', absolute: false),
            'detailUrl' => route('transfer-intent-pages.show', ['transferIntent' => '__intent__'], false),
            'productNames' => $products->pluck('product_name', 'product_id'),
            'packagings' => $products->filter(fn ($product) => $product->defaultPackaging?->pieces_per_package > 0)
                ->mapWithKeys(fn ($product) => [$product->product_id => [
                    'name' => $product->defaultPackaging->name,
                    'pieces' => (int) $product->defaultPackaging->pieces_per_package,
                ]]),
            'warehouses' => $warehouseItems,
            'requestWarehouseIds' => $requestWarehouseIds,
        ];
        $routeHelp = count($requestWarehouseIds) < $warehouses->count() ? ' intent-route-help' : '';
        $inputClass = 'mt-1 block min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500';
    @endphp
    <div class="app-page mx-auto max-w-[87.5rem] space-y-4 py-4">
        <x-page-toolbar title="Új átmozgatási igény">
            <a href="{{ route('transfer-intent-pages.index') }}" class="text-sm font-medium text-indigo-700 hover:underline">← Vissza az igényekhez</a>
        </x-page-toolbar>

        @if($products->isEmpty() || $warehouses->count() < 2)
            <p class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Új igényhez legalább egy termék és két raktár szükséges.</p>
        @else
            <form method="POST" action="{{ $form['endpoint'] }}" x-data="transferIntentCreate(@js($form))" @submit.prevent="submitIntent()" novalidate
                  class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,13fr)_minmax(0,7fr)] lg:gap-5">
                @csrf
                <div class="min-w-0 space-y-4">
                    <div x-cloak x-show="submissionError" x-ref="errorNotice" tabindex="-1" role="alert"
                         class="space-y-2 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        <p x-text="submissionError"></p>
                        <template x-if="fieldErrors.operation_key">
                            <p x-text="fieldErrors.operation_key[0]"></p>
                        </template>
                        <button x-show="conflict" type="button" @click="resetIntentSubmission()"
                                class="rounded font-semibold underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">
                            Új műveleti azonosító kérése
                        </button>
                    </div>
                    <fieldset :disabled="submitting || submitted" aria-labelledby="intent-basics-title"
                              class="min-w-0 space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-5 [&_[role=combobox]]:min-h-11">
                        <h2 id="intent-basics-title" class="font-semibold text-gray-900">Átmozgatás adatai</h2>
                        <div class="min-w-0" @product-selected="chooseProduct($event.detail.value)"
                             x-effect="$el.querySelector('[role=combobox]').setAttribute('aria-invalid', String(Boolean(fieldErrors.product_id)))">
                            <label for="intent-product" class="mb-1 block text-sm font-medium">Termék</label>
                            <x-product-picker :$products id="intent-product" :required="true" described-by="intent-product-error" />
                            <p id="intent-product-error" x-cloak x-show="fieldErrors.product_id" x-text="fieldErrors.product_id?.[0]" class="mt-1 text-sm text-red-700"></p>
                        </div>

                        <div class="grid min-w-0 gap-3 sm:grid-cols-2 sm:gap-4">
                            <div class="min-w-0" @intent-source-selected="chooseWarehouse('source', $event.detail.value)"
                                 x-effect="$el.querySelector('[role=combobox]').setAttribute('aria-invalid', String(Boolean(fieldErrors.from_warehouse_id)))">
                                <label for="intent-from-warehouse" class="mb-1 block text-sm font-medium">Forrásraktár</label>
                                <x-autocomplete-picker :items="$warehouseItems" id="intent-from-warehouse" name="from_warehouse_id"
                                    :required="true" event-name="intent-source-selected" placeholder="Raktár keresése…"
                                    available-values="sourceOptions" :described-by="'intent-source-error'.$routeHelp" />
                                <p id="intent-source-error" x-cloak x-show="fieldErrors.from_warehouse_id" x-text="fieldErrors.from_warehouse_id?.[0]" class="mt-1 text-sm text-red-700"></p>
                            </div>
                            <div class="min-w-0" @intent-destination-selected="chooseWarehouse('destination', $event.detail.value)"
                                 x-effect="$el.querySelector('[role=combobox]').setAttribute('aria-invalid', String(Boolean(fieldErrors.to_warehouse_id)))">
                                <label for="intent-to-warehouse" class="mb-1 block text-sm font-medium">Célraktár</label>
                                <x-autocomplete-picker :items="$warehouseItems" id="intent-to-warehouse" name="to_warehouse_id"
                                    :required="true" event-name="intent-destination-selected" placeholder="Raktár keresése…"
                                    available-values="destinationOptions" :described-by="'intent-destination-error'.$routeHelp" />
                                <p id="intent-destination-error" x-cloak x-show="fieldErrors.to_warehouse_id" x-text="fieldErrors.to_warehouse_id?.[0]" class="mt-1 text-sm text-red-700"></p>
                            </div>
                        </div>
                        @if($routeHelp)
                            <p id="intent-route-help" class="text-xs text-gray-600">Az útvonal egyik raktárához igénylési jogosultság szükséges.</p>
                        @endif

                        <div class="space-y-1.5">
                            <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
                                <div class="min-w-0 sm:w-full sm:max-w-56">
                                    <div x-show="!boxMode">
                                        <label for="intent-quantity" class="block text-sm font-medium">Kért mennyiség (db)</label>
                                        <input id="intent-quantity" name="quantity" inputmode="numeric" required :disabled="boxMode"
                                               :value="quantityDraft" @input="editQuantity($event.target.value); clearFieldError('quantity')"
                                               :aria-invalid="String(Boolean(quantityInputError || fieldErrors.quantity))"
                                               aria-describedby="intent-quantity-help intent-quantity-error"
                                               class="{{ $inputClass }} tabular-nums">
                                    </div>
                                    <div x-cloak x-show="boxMode">
                                        <label for="intent-box-count" class="block text-sm font-medium">Kért mennyiség (doboz)</label>
                                        <input id="intent-box-count" inputmode="numeric" required :disabled="!boxMode"
                                               :value="quantityDraft" @input="editQuantity($event.target.value); clearFieldError('quantity')"
                                               :aria-invalid="String(Boolean(quantityInputError || fieldErrors.quantity))"
                                               aria-describedby="intent-quantity-help intent-quantity-equivalent intent-quantity-error"
                                               class="{{ $inputClass }} tabular-nums">
                                    </div>
                                </div>
                                <div x-cloak x-show="packaging" role="group" aria-label="Mennyiség egysége"
                                     class="inline-flex min-h-11 max-w-full self-start rounded-md border border-gray-300 p-0.5 text-sm sm:self-auto">
                                    <button type="button" @click="usePieces(); clearFieldError('quantity')" :aria-pressed="!boxMode"
                                            class="rounded px-3 hover:bg-gray-50 aria-pressed:bg-indigo-50 aria-pressed:font-semibold aria-pressed:text-indigo-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Darab</button>
                                    <button type="button" @click="useBoxes(); clearFieldError('quantity')" :aria-pressed="boxMode"
                                            class="rounded px-3 hover:bg-gray-50 aria-pressed:bg-indigo-50 aria-pressed:font-semibold aria-pressed:text-indigo-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Doboz</button>
                                </div>
                            </div>
                            <div x-cloak x-show="packaging" class="flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                <p id="intent-quantity-help" class="min-w-0 break-words text-gray-600"
                                   x-text="packaging ? packaging.name + ' · 1 doboz = ' + packaging.pieces + ' db' : ''"></p>
                                <p id="intent-quantity-equivalent" x-show="boxMode && quantityValid" aria-live="polite"
                                   class="font-semibold tabular-nums text-gray-900" x-text="quantity + ' db'"></p>
                            </div>
                            <p id="intent-quantity-error" x-cloak x-show="quantityInputError || fieldErrors.quantity"
                               x-text="quantityInputError || fieldErrors.quantity?.[0]" class="text-sm text-red-700"></p>
                            <div x-cloak x-show="confirmBoxChange" class="text-xs text-gray-700">
                                <p role="status">A megadott darabszám nem váltható át egész dobozokra.</p>
                                <button type="button" @click="useBoxes(true); clearFieldError('quantity')" class="min-h-9 rounded font-medium text-indigo-700 hover:underline focus-visible:outline focus-visible:outline-2">
                                    Új dobozmennyiség megadása
                                </button>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset :disabled="submitting || submitted" aria-labelledby="intent-details-title"
                              class="min-w-0 space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                        <h2 id="intent-details-title" class="font-semibold text-gray-900">Igénylés részletei</h2>
                        <div class="grid min-w-0 gap-3 sm:grid-cols-2 sm:gap-4">
                            <div class="min-w-0">
                                <label for="intent-priority" class="block text-sm font-medium">Prioritás</label>
                                <select id="intent-priority" x-model="priority" @change="clearFieldError('priority')" class="{{ $inputClass }}"
                                        :aria-invalid="String(Boolean(fieldErrors.priority))" aria-describedby="intent-priority-error">
                                    <option value="normal">Normál</option>
                                    <option value="urgent">Sürgős</option>
                                    <option value="critical">Kritikus</option>
                                </select>
                                <p id="intent-priority-error" x-cloak x-show="fieldErrors.priority" x-text="fieldErrors.priority?.[0]" class="mt-1 text-sm text-red-700"></p>
                            </div>
                            <div class="min-w-0">
                                <label for="intent-arrival" class="block text-sm font-medium">Kért érkezési határidő <span class="font-normal text-gray-600">(opcionális)</span></label>
                                <input id="intent-arrival" x-ref="arrival" type="datetime-local" x-model="arrival" @input="clearFieldError('requested_arrival_at')"
                                       min="1000-01-01T00:00" max="9999-12-31T23:59" class="{{ $inputClass }}"
                                       :aria-invalid="String(Boolean(fieldErrors.requested_arrival_at))" aria-describedby="intent-arrival-zone intent-arrival-error">
                                <p id="intent-arrival-zone" class="mt-1 text-xs text-gray-600">Magyarországi idő</p>
                                <p id="intent-arrival-error" x-cloak x-show="fieldErrors.requested_arrival_at" x-text="fieldErrors.requested_arrival_at?.[0]" class="mt-1 text-sm text-red-700"></p>
                            </div>
                            <div class="min-w-0">
                                <label for="intent-shipping" class="block text-sm font-medium">Fuvarpreferencia</label>
                                <select id="intent-shipping" x-model="shippingPreference" @change="clearFieldError('shipping_preference')" class="{{ $inputClass }}"
                                        :aria-invalid="String(Boolean(fieldErrors.shipping_preference))" aria-describedby="intent-shipping-error">
                                    <option value="groupable">Összevonható</option>
                                    <option value="dedicated_requested">Külön fuvar kérése</option>
                                </select>
                                <p id="intent-shipping-error" x-cloak x-show="fieldErrors.shipping_preference" x-text="fieldErrors.shipping_preference?.[0]" class="mt-1 text-sm text-red-700"></p>
                            </div>
                        </div>

                        <div class="grid min-w-0 gap-3 sm:grid-cols-2 sm:gap-4">
                            <div class="min-w-0">
                                <label for="intent-reason" class="block text-sm font-medium">Igénylés indoka <span class="font-normal text-gray-600">(opcionális)</span></label>
                                <textarea id="intent-reason" rows="3" maxlength="500" x-model="reason" @input="clearFieldError('reason')" class="{{ $inputClass }}"
                                          :aria-invalid="String(Boolean(fieldErrors.reason))" aria-describedby="intent-reason-error"></textarea>
                                <p id="intent-reason-error" x-cloak x-show="fieldErrors.reason" x-text="fieldErrors.reason?.[0]" class="mt-1 text-sm text-red-700"></p>
                            </div>
                            <div class="min-w-0">
                                <label for="intent-note" class="block text-sm font-medium">Megjegyzés <span class="font-normal text-gray-600">(opcionális)</span></label>
                                <textarea id="intent-note" rows="3" maxlength="2000" x-model="note" @input="clearFieldError('note')" class="{{ $inputClass }}"
                                          :aria-invalid="String(Boolean(fieldErrors.note))" aria-describedby="intent-note-error"></textarea>
                                <p id="intent-note-error" x-cloak x-show="fieldErrors.note" x-text="fieldErrors.note?.[0]" class="mt-1 text-sm text-red-700"></p>
                            </div>
                        </div>
                    </fieldset>
                </div>

                <aside x-cloak class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 sm:p-5 lg:sticky lg:top-[calc(var(--app-topbar-height,4rem)+1rem)]"
                       aria-labelledby="intent-summary-title">
                    <h2 id="intent-summary-title" class="font-semibold text-gray-900">Összegzés</h2>
                    <dl class="mt-3 grid min-w-0 grid-cols-2 gap-x-3 gap-y-3 text-sm">
                        <div class="col-span-2 min-w-0">
                            <dt class="text-xs text-gray-600">Termék</dt>
                            <dd class="break-words font-medium text-gray-900" x-text="productName"></dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-gray-600">Forrásraktár</dt>
                            <dd class="break-words font-medium text-gray-900" x-text="warehouseName(source)"></dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-gray-600">Célraktár</dt>
                            <dd class="break-words font-medium text-gray-900" x-text="warehouseName(destination)"></dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-gray-600">Mennyiség</dt>
                            <dd class="font-medium tabular-nums text-gray-900" x-text="quantityValid ? quantity + ' db' : '–'"></dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-gray-600">Prioritás</dt>
                            <dd class="font-medium text-gray-900" x-text="priorityLabel"></dd>
                        </div>
                        <div class="col-span-2 min-w-0">
                            <dt class="text-xs text-gray-600">Kért érkezési határidő</dt>
                            <dd class="break-words font-medium text-gray-900" x-text="arrival ? arrival.replace('T', ' ') : '–'"></dd>
                        </div>
                    </dl>
                    <div class="mt-4 space-y-3 border-t border-gray-100 pt-4">
                        <p class="text-xs text-gray-600">A benyújtott igény a forrásraktár jóváhagyására vár.</p>
                        <button type="submit" :disabled="submitting || submitted || conflict"
                                class="min-h-11 w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                                x-text="submitting || submitted ? 'Benyújtás…' : 'Igény benyújtása'">Igény benyújtása</button>
                    </div>
                </aside>
            </form>
            <noscript><p class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Az igény benyújtásához engedélyezd a JavaScriptet.</p></noscript>
        @endif
    </div>
</x-app-layout>
