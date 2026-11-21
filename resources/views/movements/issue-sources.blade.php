<section x-cloak x-show="type === 'out'" class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5" aria-labelledby="issue-sources-title">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="issue-sources-title" tabindex="-1" x-ref="sourceHeading" class="font-semibold text-gray-900">Forráshelyek</h2>
        <span x-show="rows.length" class="text-xs text-gray-600" x-text="rows.length + ' kijelölt forrás'"></span>
    </div>
    <p x-show="sourceStatus" aria-live="polite" x-text="sourceStatus" class="text-sm text-gray-700"></p>
    <div x-show="rows.length" class="rounded-md border border-gray-200 sm:overflow-hidden">
        <table x-ref="sourceTable" class="block w-full table-fixed text-sm sm:table" role="table" aria-label="Kiválasztott források">
            <thead class="sr-only bg-gray-50 text-xs text-gray-600 sm:not-sr-only sm:table-header-group" role="rowgroup">
                <tr role="row">
                    <th scope="col" role="columnheader" class="px-3 py-2 text-left font-medium">Forrás</th>
                    <th scope="col" role="columnheader" class="w-24 px-2 py-2 text-right font-medium">Kiadható</th>
                    <th scope="col" role="columnheader" class="w-24 px-2 py-2 text-right font-medium">Kiadandó</th>
                    <th scope="col" role="columnheader" class="w-12"><span class="sr-only">Műveletek</span></th>
                </tr>
            </thead>
            <tbody role="rowgroup" class="block divide-y divide-gray-200 sm:table-row-group">
                <template x-for="(row, index) in rows" :key="row.scope + ':' + row.key">
                    <tr role="row" class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-x-2 gap-y-2 p-3 sm:table-row sm:p-0">
                        <td role="cell" class="col-span-full min-w-0 [overflow-wrap:anywhere] sm:px-3 sm:py-2">
                            <a x-show="row.source.plan_url" :href="row.source.plan_url" target="_blank" rel="noopener" class="font-semibold text-indigo-700 underline"
                                :aria-label="sourceName(row.source) + ' megtekintése az alaprajzon'" x-text="sourceName(row.source)"></a>
                            <p x-show="!row.source.plan_url" class="font-semibold text-gray-900" x-text="sourceName(row.source)"></p>
                            <p x-show="automatic" class="text-xs text-gray-600">Alapértelmezett forrás</p>
                            <p x-show="row.source.position_label" x-text="row.source.position_label" class="text-xs text-gray-600"></p>
                            <p x-show="row.source.load_requires_verification" class="text-xs text-amber-900">A rekesz terhelése ellenőrzésre vár.</p>
                        </td>
                        <td role="cell" class="min-w-0 text-gray-700 [overflow-wrap:anywhere] sm:px-2 sm:py-2 sm:text-right">
                            <span class="block text-xs text-gray-600 sm:hidden" aria-hidden="true">Kiadható</span>
                            <span class="tabular-nums" x-text="sources ? formatStock(findSource(row.key)?.available_quantity ?? 0) : '—'"></span>
                        </td>
                        <td role="cell" class="min-w-0 sm:px-2 sm:py-2">
                            <span class="block text-xs text-gray-600 sm:hidden" aria-hidden="true">Kiadandó</span>
                            <input type="text" inputmode="numeric" :value="row.quantity" @input="changeRow(index, $event.target.value)"
                                :aria-label="sourceName(row.source) + ' · Kiadandó darab'" :aria-invalid="Boolean(rowError(row))" :aria-describedby="'issue-source-error-' + index"
                                :data-source-key="row.key" class="w-full min-w-0 rounded-md border-gray-300 px-2 py-1.5 text-right text-sm tabular-nums">
                            <p :id="'issue-source-error-' + index" x-show="rowError(row)" x-text="rowError(row)" class="mt-1 text-xs text-red-700 [overflow-wrap:anywhere]"></p>
                        </td>
                        <td role="cell" class="self-end text-right sm:px-2 sm:py-2">
                            <button type="button" data-remove-source @click="removeRow(index)" :aria-label="sourceName(row.source) + ' eltávolítása'"
                                class="min-h-9 min-w-8 rounded text-lg text-gray-600 hover:bg-gray-100 focus-visible:outline focus-visible:outline-indigo-600">×</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <div aria-live="polite" x-show="sources?.consistent" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-gray-100 pt-2 text-sm">
        <p class="font-semibold tabular-nums" x-text="'Kijelölve: ' + formatStock(selectedTotal) + ' / ' + (quantityValid ? formatStock(quantity) : '—')"></p>
        <p :class="difference === 0 && !rows.some(row => rowError(row)) ? 'text-emerald-800' : 'text-amber-900'" x-text="selectionStatus"></p>
    </div>

    <button type="button" x-show="availableBins.length" @click="recommend()" :disabled="!canRecommend || adviceLoading"
        class="min-h-10 rounded-md border border-indigo-200 px-3 text-left text-sm font-medium text-indigo-700 hover:bg-indigo-50 disabled:opacity-50"
        x-text="adviceLoading ? 'Forráshelyek keresése…' : 'Smart Picking ajánlás'">Smart Picking ajánlás</button>
    <p x-show="adviceFailure" role="status" x-text="adviceFailure" class="text-sm text-red-700"></p>
    <div x-show="advice !== null" class="space-y-2 border-t border-gray-100 pt-2 text-sm" aria-label="Smart Picking ajánlás" role="region">
        <p x-show="!advice?.some(row => row.location_id !== null)">Nincs ajánlható rekesz. Válassz kézi forrást vagy használd a tárhely nélküli készletet, ha elérhető.</p>
        <p x-show="adviceQuantity > 0 && adviceQuantity < difference" class="font-medium text-amber-900" x-text="'Részleges ajánlás · ' + formatStock(selectedTotal + adviceQuantity) + ' / ' + formatStock(quantity)"></p>
        <ul class="divide-y divide-indigo-100">
            <template x-for="row in advice ?? []" :key="row.location_id ?? 'unassigned'">
                <li class="grid gap-x-3 gap-y-1 py-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <div class="min-w-0 break-words"><strong x-text="sourceName(row.source)"></strong><p x-show="row.source.position_label" x-text="row.source.position_label" class="text-xs text-gray-600"></p></div>
                    <span class="font-semibold tabular-nums" x-text="formatStock(row.quantity) + ' ajánlott'"></span>
                    <p class="text-xs text-gray-600 sm:col-span-2" x-text="'Nyilvántartott: ' + formatStock(row.source.stored_quantity) + ' · Kiadható: ' + formatStock(row.source.available_quantity)"></p>
                    <p x-show="row.source.load_requires_verification" class="text-xs font-medium text-amber-900 sm:col-span-2">A rekesz terhelése ellenőrzésre vár.</p>
                    <a x-show="row.source.plan_url" :href="row.source.plan_url" target="_blank" rel="noopener" class="w-fit text-xs font-medium text-indigo-700 underline">Megjelenítés az alaprajzon</a>
                </li>
            </template>
        </ul>
        <button x-show="advice?.some(row => row.location_id !== null)" type="button" @click="applyAdvice()" class="min-h-10 rounded-md border border-indigo-200 bg-white px-3 font-medium text-indigo-700">Ajánlás használata</button>
    </div>

    <div x-show="manualChoices.length" class="grid min-w-0 items-end gap-2 sm:grid-cols-[minmax(0,1fr)_auto]" @issue-bin-selected="pickerBinId = $event.detail.value">
        <div class="min-w-0">
            <label for="issue-bin" class="mb-1 block text-sm font-medium text-gray-700">Rekesz keresése</label>
            <x-autocomplete-picker :items="[]" id="issue-bin" :name="null" :validate-selection="false" items-source="manualChoices" model-value="pickerBinId" event-name="issue-bin-selected"
                placeholder="Rekeszkód, zóna vagy állvány…" clear-label="Rekeszkeresés törlése" results-label="Rekesztalálatok" :max-results="12" />
        </div>
        <button type="button" :disabled="!pickerBinId" @click="addSource(pickerBinId)" class="min-h-10 rounded-md border border-gray-300 px-3 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">Forráshely hozzáadása</button>
    </div>
    <button x-show="loose?.available_quantity > 0 && !rows.some(row => row.scope === sourceScope && row.key === 'unassigned')" type="button" @click="addSource('unassigned')"
        class="min-h-10 rounded-md border border-gray-300 px-3 text-left text-sm font-medium text-gray-700 hover:bg-gray-50">Tárhely nélküli készlet hozzáadása</button>

</section>
