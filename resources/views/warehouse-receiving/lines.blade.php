<section class="{{ $panel }}" aria-labelledby="receiving-lines-heading">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <h2 id="receiving-lines-heading" class="font-semibold text-gray-900" tabindex="-1" x-ref="lineHeading">Tranzittételek</h2>
        <button x-show="snapshot.stop.can_receive && editableLines.length > 1" type="button" @click="setAll()" :disabled="draftLocked"
                class="{{ $button }}">Minden tétel teljes mennyisége</button>
    </div>
    <p x-show="snapshot.stop.can_receive" class="mt-1 text-sm text-gray-600">Add meg a most ténylegesen átvett darabszámot. A többi áru tranzitban marad.</p>
    <p x-show="!snapshot.stop.can_receive && snapshot.stop.remaining_quantity === 0" class="mt-1 text-sm text-gray-600">A megálló összes tételét átvették.</p>
    <p x-show="!snapshot.stop.can_receive && snapshot.stop.remaining_quantity > 0" class="mt-1 text-sm text-gray-600">Ehhez a megállóhoz jelenleg nem rögzíthető új átvétel.</p>
    <ul class="mt-3 space-y-3">
        <template x-for="item in snapshot.items" :key="item.id">
            <li data-testid="receiving-line" class="min-w-0 space-y-3 rounded-lg border border-gray-200 p-3 text-sm [overflow-wrap:anywhere] sm:p-4"
                :class="{ 'bg-gray-50': lineStatus(item) === 'received' }" :aria-labelledby="'receive-product-' + item.id">
                <div class="flex min-w-0 flex-wrap items-start justify-between gap-x-3 gap-y-2">
                    <div class="min-w-0 flex-1 basis-44">
                        <h3 :id="'receive-product-' + item.id" class="font-semibold leading-5 text-gray-900" x-text="item.product_name"></h3>
                        <p class="mt-1 text-xs text-gray-600">Cikkszám <span class="font-mono font-medium text-gray-800" x-text="item.sku"></span></p>
                        <p class="text-xs text-gray-600">Igény <span class="font-mono" x-text="item.intent_reference"></span></p>
                    </div>
                    <span :data-receiving-line-status="lineStatus(item)"
                          class="max-w-full rounded-md px-2 py-1 text-xs font-medium"
                          :class="{ 'bg-amber-50 text-amber-900': lineStatus(item) === 'partial', 'bg-gray-100 text-gray-700': lineStatus(item) !== 'partial' }"
                          x-text="statuses[lineStatus(item)]"></span>
                </div>
                <dl class="grid min-w-0 grid-cols-2 gap-2 tabular-nums min-[400px]:grid-cols-3">
                    <div class="flex min-w-0 flex-col justify-between rounded-md bg-gray-50 px-2.5 py-2">
                        <dt class="text-xs text-gray-600">Feladva</dt>
                        <dd data-receiving-line-quantity="dispatched" class="mt-1 font-medium text-gray-900" x-text="quantity(item.dispatched_quantity)"></dd>
                    </div>
                    <div class="flex min-w-0 flex-col justify-between rounded-md bg-gray-50 px-2.5 py-2">
                        <dt class="text-xs text-gray-600">Korábban átvéve</dt>
                        <dd data-receiving-line-quantity="received" class="mt-1 font-medium text-gray-900" x-text="quantity(item.received_quantity)"></dd>
                    </div>
                    <div class="col-span-2 flex min-w-0 flex-wrap items-center justify-between gap-x-2 rounded-md px-2.5 py-2 min-[400px]:col-span-1 min-[400px]:flex-col min-[400px]:items-start"
                         :class="{ 'bg-indigo-50 text-indigo-900': item.remaining_quantity > 0, 'bg-gray-100 text-gray-700': item.remaining_quantity === 0 }">
                        <dt class="text-xs font-medium">Még átvehető</dt>
                        <dd data-receiving-line-quantity="remaining" class="text-base font-semibold min-[400px]:mt-0.5" x-text="quantity(item.remaining_quantity)"></dd>
                    </div>
                </dl>
                <template x-if="(snapshot.stop.can_receive && item.remaining_quantity > 0) || draft[item.id].quantity !== '' || draft[item.id].note">
                    <div>
                        <div class="grid min-w-0 items-end gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                            <div class="min-w-0">
                                <label :for="'receive-quantity-' + item.id" class="block text-xs font-medium text-gray-700">Most átvett mennyiség</label>
                                <div class="flex min-w-0 flex-wrap items-center gap-x-2">
                                    <input :id="'receive-quantity-' + item.id" type="text" inputmode="numeric" maxlength="10" x-model="draft[item.id].quantity"
                                           @input="edited()" :disabled="draftLocked || !snapshot.stop.can_receive || item.remaining_quantity === 0"
                                           :aria-invalid="!!rowError(item)" :aria-describedby="rowError(item) ? 'receive-error-' + item.id : null"
                                           placeholder="0" class="{{ $field }} max-w-36">
                                    <button type="button" @click="setFull(item)" :disabled="draftLocked || !snapshot.stop.can_receive || item.remaining_quantity === 0"
                                            class="{{ $link }} disabled:opacity-50">Teljes mennyiség</button>
                                </div>
                            </div>
                            <label class="block min-w-0">
                                <span class="block text-xs font-medium text-gray-700">Tétel megjegyzése <span class="font-normal text-gray-500">– opcionális</span></span>
                                <input type="text" maxlength="500" x-model="draft[item.id].note" @input="edited()"
                                       :disabled="draftLocked || !snapshot.stop.can_receive || item.remaining_quantity === 0"
                                       aria-label="Tétel megjegyzése" placeholder="Pl. 5 db még hiányzik" class="{{ $field }}">
                            </label>
                        </div>
                        <p x-show="rowError(item)" :id="'receive-error-' + item.id" class="mt-1 text-sm text-red-700" x-text="rowError(item)"></p>
                    </div>
                </template>
            </li>
        </template>
    </ul>
    <label x-show="snapshot.stop.can_receive || hasDraft" class="mt-2 block border-t border-gray-200 pt-3">
        <span class="block text-xs font-medium text-gray-700">Átvételi megjegyzés <span class="font-normal text-gray-500">– opcionális</span></span>
        <textarea x-model="note" @input="edited()" maxlength="500" rows="2" :disabled="draftLocked || !snapshot.stop.can_receive"
                  aria-label="Átvételi megjegyzés" class="{{ $field }}"></textarea>
    </label>
</section>

