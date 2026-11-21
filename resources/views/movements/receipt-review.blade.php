<aside x-cloak x-show="type === 'in'" aria-labelledby="receipt-summary-title"
    class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5 lg:sticky lg:top-4 [overflow-wrap:anywhere]">
    <h2 id="receipt-summary-title" class="font-semibold text-gray-900">Bevételezés összegzése</h2>
    <div x-show="product || warehouse" class="space-y-0.5 text-sm text-gray-700">
        <p x-show="product" class="font-medium text-gray-900" x-text="productNames[product]"></p>
        <p x-show="product" class="text-xs text-gray-600" x-text="receiptSku"></p>
        <p x-show="warehouse" x-text="warehouseNames[warehouse]"></p>
    </div>
    <div role="status" aria-label="Készletelérhetőség">
    <dl class="space-y-2 border-y border-gray-100 py-3 text-sm">
        <div class="flex flex-wrap justify-between gap-x-2"><dt>Jelenlegi fizikai készlet</dt><dd x-text="receiptPhysicalStock === null ? '—' : formatStock(receiptPhysicalStock)"></dd></div>
        <div class="flex flex-wrap justify-between gap-x-2"><dt>Most bevételezendő</dt><dd class="font-semibold" x-text="quantityValid ? '+' + formatStock(quantity) : '—'"></dd></div>
        <div class="flex flex-wrap justify-between gap-x-2"><dt>Várható fizikai készlet</dt><dd class="font-semibold" x-text="receiptExpectedStock === null ? '—' : formatStock(receiptExpectedStock)"></dd></div>
        <div class="flex flex-wrap justify-between gap-x-2"><dt>Újonnan betárolásra vár</dt><dd class="font-semibold" x-text="quantityValid ? formatStock(quantity) : '—'"></dd></div>
    </dl>
    <p x-show="boxMode && quantityValid" class="mt-1 text-xs text-gray-600" x-text="boxMode && quantityValid ? quantityDraft + ' doboz × ' + formatStock(packaging.pieces) : ''"></p>
    <p x-show="loading || failed" class="mt-1 text-xs text-gray-700" x-text="loading || failed ? stockText : ''"></p>
    </div>
    <button type="button" x-show="failed" @click="loadStock()" class="text-sm text-indigo-700 underline">Frissítés</button>
    <p class="rounded-md bg-indigo-50 p-2 text-sm text-indigo-950">A bevételezett áru a betárolásig nem lesz kiadható.</p>
    <div class="space-y-1 text-sm" aria-live="polite">
        <p x-show="receiptNextStep" x-text="receiptNextStep" class="text-gray-700"></p>
        <p x-show="!receiptNextStep && receiptPhysicalStock === null" class="text-xs text-gray-600">A készletegyenleget rögzítéskor a szerver ellenőrzi.</p>
        <p x-show="submissionError" x-text="submissionError" class="font-medium text-red-700"></p>
        <template x-for="(messages, key) in fieldErrors" :key="key">
            <p x-show="key !== 'partner_id'" x-text="messages[0]" class="text-red-700"></p>
        </template>
    </div>
    <button x-ref="receiptReviewButton" type="submit" x-show="!receiptPending" :disabled="type !== 'in' || !canReviewReceipt"
        class="min-h-11 w-full rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Bevételezés ellenőrzése</button>
    <button x-ref="receiptRetry" type="button" x-show="receiptPending" @click="$refs.receiptDialog.showModal()"
        class="min-h-11 w-full rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white">Függő bevételezés megnyitása</button>
    <a href="{{ $returnUrl }}" class="block text-center text-sm text-gray-600 hover:underline">Mégse</a>
</aside>

<dialog x-ref="receiptDialog" @cancel.prevent="closeReceiptReview()" aria-labelledby="receipt-review-title"
    class="m-auto w-[calc(100%-1rem)] max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white p-0 text-gray-900 shadow-xl backdrop:bg-gray-900/50">
    <div class="flex max-h-[calc(100dvh-1.25rem)] flex-col [overflow-wrap:anywhere]">
        <h2 id="receipt-review-title" x-ref="receiptTitle" tabindex="-1" class="shrink-0 px-4 pt-4 text-lg font-semibold">Bevételezés megerősítése</h2>
        <div class="space-y-3 overflow-y-auto p-4 text-sm">
            <p>Véglegesítéssel megerősíted az áru tényleges átvételét.</p>
            <template x-if="receiptReview">
                <dl class="grid grid-cols-1 gap-2 rounded-md bg-gray-50 p-3 sm:grid-cols-2">
                    <div><dt class="text-xs text-gray-600">Termék</dt><dd class="font-semibold" x-text="receiptReview.product + ' · ' + receiptReview.sku"></dd></div>
                    <div><dt class="text-xs text-gray-600">Célraktár</dt><dd class="font-semibold" x-text="receiptReview.warehouse"></dd></div>
                    <div x-show="receiptReview.partner"><dt class="text-xs text-gray-600">Partner</dt><dd x-text="receiptReview.partner"></dd></div>
                    <div><dt class="text-xs text-gray-600">Bevételezendő</dt><dd class="font-semibold" x-text="formatStock(receiptReview.payload.quantity)"></dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-gray-600">Várható készletváltozás</dt><dd class="font-semibold" x-text="receiptReview.before === null ? '+' + formatStock(receiptReview.payload.quantity) : formatStock(receiptReview.before) + ' → ' + formatStock(receiptReview.after)"></dd></div>
                </dl>
            </template>
            <p x-show="submissionError" role="alert" class="rounded-md bg-amber-50 p-2 text-amber-950" x-text="submissionError"></p>
        </div>
        <div class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-gray-100 p-3">
            <button type="button" @click="closeReceiptReview()" :disabled="submitting" class="min-h-11 rounded-md border border-gray-300 px-3 text-sm font-medium"
                x-text="receiptPending ? 'Bezárás' : 'Vissza a bevételezéshez'"></button>
            <button type="button" @click="saveReceipt()" :disabled="submitting" class="min-h-11 rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white disabled:opacity-50"
                x-text="submitting ? 'Rögzítés…' : (receiptPending ? 'Mentés újrapróbálása' : 'Bevételezés véglegesítése')"></button>
        </div>
    </div>
</dialog>
