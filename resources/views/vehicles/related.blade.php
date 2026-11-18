<dialog x-ref="relatedDialog" aria-labelledby="vehicle-related-title" class="{{ $dialog }} max-w-xl" @cancel.prevent="closeRelated()">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <h2 id="vehicle-related-title" class="text-lg font-semibold">Kapcsolódó fuvarok</h2>
            <p class="mt-0.5 text-sm text-gray-600" x-text="relatedVehicle?.name"></p>
        </div>
        <button type="button" @click="closeRelated()" class="{{ $secondary }}">Bezárás</button>
    </div>
    <div class="mt-3">
        <label for="vehicle-related-status" class="mb-1 block text-sm font-medium text-gray-800">Fuvar állapota</label>
        <select id="vehicle-related-status" x-ref="relatedFilter" x-model="relatedStatus" @change="loadRelated()" class="{{ $field }} sm:max-w-56">
            <option value="all">Összes</option>
            <option value="draft">Tervezett</option>
            <option value="in_transit">Úton</option>
            <option value="history">Korábbi</option>
        </select>
    </div>
    <p x-show="relatedRequest" class="mt-3 text-sm text-gray-600" role="status">Fuvarok betöltése…</p>
    <div x-show="relatedError" class="mt-3 space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
        <p role="alert" x-text="relatedError"></p>
        <button type="button" @click="loadRelated()" class="{{ $secondary }}">Betöltés újrapróbálása</button>
    </div>
    <template x-if="related && !relatedRequest && !relatedError">
        <div class="mt-3">
            <p x-show="!related.data.length" class="rounded-md bg-gray-50 p-3 text-sm text-gray-700">Nincs megjeleníthető fuvar ebben az állapotban.</p>
            <ul x-show="related.data.length" class="divide-y divide-gray-200 rounded-md border border-gray-200 px-3" aria-label="Kapcsolódó fuvarok listája">
                <template x-for="shipment in related.data" :key="shipment.id">
                    <li class="flex min-w-0 flex-wrap items-start justify-between gap-x-3 gap-y-1 py-2 text-sm">
                        <div class="min-w-0 flex-1 basis-40">
                            <a :href="shipment.url" class="inline-flex min-h-11 flex-col justify-center font-medium text-indigo-700 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                                <span x-text="shipment.reference"></span>
                                <span class="font-mono text-xs" x-text="shipment.shipment_number"></span>
                            </a>
                            <p class="text-xs text-gray-600" x-text="shipment.origin_warehouse_name"></p>
                        </div>
                        <span class="mt-1 inline-flex rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700" x-text="shipment.status_label"></span>
                    </li>
                </template>
            </ul>
            <div x-show="related.last_page > 1" class="mt-3 flex flex-wrap items-center justify-between gap-2" aria-label="Kapcsolódó fuvarok lapozása">
                <button type="button" @click="loadRelated(related.current_page - 1)" :disabled="related.current_page <= 1" class="{{ $secondary }}">Előző</button>
                <p class="text-xs text-gray-600" x-text="related.current_page + ' / ' + related.last_page + ' oldal'"></p>
                <button type="button" @click="loadRelated(related.current_page + 1)" :disabled="related.current_page >= related.last_page" class="{{ $secondary }}">Következő</button>
            </div>
        </div>
    </template>
</dialog>
