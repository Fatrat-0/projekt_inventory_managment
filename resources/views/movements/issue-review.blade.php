<aside x-cloak x-show="type === 'out'" aria-labelledby="issue-summary-title"
    class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
    <h2 id="issue-summary-title" class="font-semibold text-gray-900">Kiadás összegzése</h2>
    <dl class="space-y-0.5 text-xs text-gray-600 [overflow-wrap:anywhere]">
        <div x-show="product"><dt class="sr-only">Termék</dt><dd class="font-medium text-gray-900" x-text="productNames[product]"></dd></div>
        <div x-show="warehouse"><dt class="sr-only">Raktár</dt><dd x-text="warehouseNames[warehouse]"></dd></div>
        <div x-show="partnerName"><dt class="sr-only">Partner</dt><dd x-text="partnerName"></dd></div>
    </dl>
    <dl class="space-y-2 border-y border-gray-100 py-3 text-sm">
        <div class="flex flex-wrap justify-between gap-x-2"><dt>Kiadandó</dt><dd class="font-semibold tabular-nums" x-text="quantityValid ? formatStock(quantity) : '—'"></dd></div>
        <div class="flex flex-wrap justify-between gap-x-2"><dt>Forrásokból kijelölve</dt><dd class="font-semibold tabular-nums" x-text="formatStock(selectedTotal)"></dd></div>
        <div class="flex flex-wrap justify-between gap-x-2"><dt>Kijelölt forráshelyek</dt><dd class="tabular-nums" x-text="rows.length"></dd></div>
        <div>
            <div class="flex flex-wrap justify-between gap-x-2"><dt>Várható fizikai készlet</dt><dd class="tabular-nums" x-text="expectedStock === null ? '—' : formatStock(expectedStock)"></dd></div>
            <dd x-show="expectedStock !== null" class="mt-0.5 text-xs text-gray-600">Véglegesítéskor a szerver újra ellenőrzi.</dd>
        </div>
    </dl>
    <div class="space-y-1 text-sm [overflow-wrap:anywhere]" aria-live="polite">
        <p :class="canCheck ? 'text-emerald-800' : 'text-gray-700'" x-text="reviewStatus"></p>
        <p x-show="issueError" x-text="issueError" class="font-medium text-red-700"></p>
    </div>
    <button x-ref="checkTrigger" x-show="!pending && !receiptUrl" type="submit" :disabled="type !== 'out' || !canCheck" class="min-h-11 w-full rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
        x-text="checkLoading ? 'Ellenőrzés…' : 'Kiadás ellenőrzése'">Kiadás ellenőrzése</button>
    <button x-ref="pendingTrigger" x-show="pending" type="button" @click="showReview()" class="min-h-11 w-full rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white">Függő mentés megnyitása</button>
    <a x-show="receiptUrl" :href="receiptUrl" class="block font-medium text-indigo-700 underline">Rögzített kiadás megnyitása</a>
    <a href="{{ $returnUrl }}" class="block text-center text-sm text-gray-600 hover:underline">Mégse</a>
</aside>

<dialog x-ref="review" @cancel.prevent="if (!savingIssue) closeReview()" aria-labelledby="issue-review-title"
    class="m-auto w-[calc(100%-1rem)] max-w-lg overflow-hidden rounded-lg border border-gray-200 bg-white p-0 text-gray-900 shadow-xl backdrop:bg-gray-900/50">
    <div class="flex max-h-[calc(100dvh-1.25rem)] flex-col">
    <h2 id="issue-review-title" tabindex="-1" x-ref="reviewTitle" class="shrink-0 px-4 pt-4 text-lg font-semibold sm:px-6">Kiadás megerősítése</h2>
    <div class="min-h-0 overflow-y-auto px-4 pb-3 [overflow-wrap:anywhere] sm:px-6">
    <p x-show="!pending" class="mt-1 text-sm text-gray-600">A szerver ellenőrizte a forrásokat. A megerősítéssel a készlet csökken.</p>
    <template x-if="review">
        <div class="mt-3 space-y-3 text-sm">
            <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <div class="min-w-0"><dt class="text-xs text-gray-500">Termék</dt><dd class="break-words font-medium" x-text="review.product"></dd></div>
                <div class="min-w-0"><dt class="text-xs text-gray-500">Raktár</dt><dd class="break-words font-medium" x-text="review.warehouse"></dd></div>
                <div x-show="review.partner" class="min-w-0"><dt class="text-xs text-gray-500">Partner</dt><dd class="break-words" x-text="review.partner"></dd></div>
                <div x-show="review.payload.reference" class="min-w-0"><dt class="text-xs text-gray-500">Bizonylatszám vagy megjegyzés</dt><dd class="break-words" x-text="review.payload.reference"></dd></div>
            </dl>
            <div class="border-t border-gray-100 pt-2">
                <p class="font-semibold" x-text="'Kiadandó · ' + formatStock(review.payload.quantity)"></p>
                <ul class="mt-1 divide-y divide-gray-100">
                    <template x-for="row in review.rows" :key="row.key">
                        <li class="flex justify-between gap-2 py-1"><span class="min-w-0 break-words" x-text="row.name"></span><strong class="shrink-0" x-text="formatStock(row.quantity)"></strong></li>
                    </template>
                </ul>
            </div>
        </div>
    </template>
    <p x-show="issueError" role="alert" x-text="issueError" class="mt-3 break-words rounded-md bg-amber-50 p-2 text-sm text-amber-900"></p>
    </div>
    <div class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-gray-100 px-4 py-3 sm:px-6">
        <button type="button" @click="closeReview()" :disabled="savingIssue" class="min-h-11 rounded-md border border-gray-300 px-3 text-sm font-medium">Vissza a kiadáshoz</button>
        <button type="button" @click="confirmIssue()" :disabled="savingIssue" class="min-h-11 rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white disabled:opacity-50"
            x-text="savingIssue ? 'Mentés…' : (pending ? 'Mentés újrapróbálása' : 'Kiadás véglegesítése')">Kiadás véglegesítése</button>
    </div>
    </div>
</dialog>
