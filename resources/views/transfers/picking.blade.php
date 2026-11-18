<section aria-labelledby="transfer-picking-title" x-cloak x-show="product && warehouse && quantityValid"
         class="space-y-2 border-t border-gray-100 pt-3">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <h2 id="transfer-picking-title" tabindex="-1" class="text-sm font-semibold text-gray-900 focus-visible:outline-indigo-600">
            Forráshelyek – kitárolás
        </h2>
        <p x-show="stockText" x-text="stockText" role="status" class="order-3 basis-full text-xs tabular-nums text-gray-600 sm:order-none sm:basis-auto sm:grow"></p>
        <button type="button" @click="loadStock()" :disabled="loading"
                class="order-2 text-xs font-medium text-indigo-700 underline-offset-2 hover:underline disabled:opacity-50 sm:order-none">Forráskészlet frissítése</button>
    </div>

    <div x-show="!loading && !failed && !automaticSource" class="space-y-2">
        <p x-show="stock === 0" class="text-sm text-gray-700">A termékből nincs kitárolható készlet ebben a raktárban.</p>
        <template x-if="sourceRows.length > 0 && !automaticSource">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p x-text="sourceTotal + ' / ' + quantity + ' db kitárolásra'"
                       class="text-xs font-medium tabular-nums text-gray-700" aria-live="polite"></p>
                    <button type="button" @click="applySourceSuggestion()" :disabled="!quantityValid || quantityExceedsStock"
                            class="min-h-9 rounded-md border border-indigo-200 px-2.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50">
                        Kitárolási javaslat alkalmazása
                    </button>
                </div>
                <ul aria-label="Kitárolási forráshelyek" class="max-h-72 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-200 px-2.5 sm:px-3">
                    <template x-for="row in sourceRows" :key="row.key">
                        <li class="grid min-w-0 grid-cols-[minmax(0,1fr)_5.25rem] items-center gap-x-2 gap-y-0.5 py-2 sm:grid-cols-[minmax(0,1fr)_6rem] sm:gap-x-3">
                            <div class="min-w-0">
                                <label :for="'transfer-source-amount-' + row.key" x-text="sourceLabel(row)"
                                       class="block break-words text-sm font-medium text-gray-900"></label>
                                <p :id="'transfer-source-capacity-' + row.key" class="flex flex-wrap gap-x-2 text-xs tabular-nums text-gray-600">
                                    <span x-text="row.stored_quantity + ' db tárolt'"></span>
                                    <span x-text="row.reserved_quantity + ' db foglalt'"></span>
                                    <span x-text="row.available_quantity + ' db kiadható'" class="font-medium text-gray-800"></span>
                                    <span x-show="row.active === false" class="font-medium text-amber-800">Inaktív tárhely</span>
                                </p>
                            </div>
                            <input type="text" inputmode="numeric" placeholder="0 db" :id="'transfer-source-amount-' + row.key"
                                   :aria-label="'Kitárolandó mennyiség (db) – ' + sourceLabel(row)"
                                   :aria-invalid="Boolean(sourceInputError(row))"
                                   :aria-describedby="'transfer-source-capacity-' + row.key + (sourceInputError(row) ? ' transfer-source-error-' + row.key : '')"
                                   :value="sourceAmount(row)" @input="editSource(row, $event.target.value)"
                                   :disabled="row.available_quantity === 0 && !sourceAmount(row)"
                                   class="min-h-10 w-full min-w-0 rounded-md border-gray-300 text-right text-sm tabular-nums aria-invalid:border-red-600 disabled:bg-gray-50">
                            <p x-show="sourceInputError(row)" :id="'transfer-source-error-' + row.key" x-text="sourceInputError(row)"
                               class="col-span-2 text-xs text-red-700"></p>
                        </li>
                    </template>
                </ul>
            </div>
        </template>
    </div>

    <template x-for="(row, index) in sourceAllocations" :key="row.location_id ?? 'unassigned'">
        <span hidden>
            <input type="hidden" :name="`source_allocations[${index}][location_id]`" :value="row.location_id ?? ''">
            <input type="hidden" :name="`source_allocations[${index}][quantity]`" :value="row.quantity">
        </span>
    </template>

    @if($sourceErrors->isNotEmpty())
        <ul role="alert" class="list-inside list-disc text-xs text-red-700">
            @foreach($sourceErrors->flatten()->unique() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif
</section>
