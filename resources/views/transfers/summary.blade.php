<section aria-labelledby="transfer-summary-title" class="rounded-lg border border-gray-200 bg-white shadow-sm">
    <header class="flex items-center gap-2 border-b border-gray-100 px-4 py-3.5 sm:px-5">
        <span class="rounded-md bg-indigo-50 p-2 text-indigo-700"><x-icon name="tasks" /></span>
        <h2 id="transfer-summary-title" class="font-semibold text-gray-900">Átmozgatás összegzése</h2>
    </header>

    <div class="space-y-4 p-4 sm:p-5">
        <dl x-cloak class="grid grid-cols-2 gap-x-3 gap-y-3 text-sm">
            <div class="col-span-2 min-w-0">
                <dt class="text-xs font-medium text-gray-600">Termék</dt>
                <dd class="mt-0.5 break-words font-medium text-gray-900" x-text="productName || 'Még nincs kiválasztva'"></dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs font-medium text-gray-600">Forrásraktár</dt>
                <dd class="mt-0.5 break-words font-medium text-gray-900" x-text="warehouseName(warehouse)"></dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs font-medium text-gray-600">Célraktár</dt>
                <dd class="mt-0.5 break-words font-medium text-gray-900" x-text="warehouseName(destination)"></dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-600">Mennyiség</dt>
                <dd data-transfer-summary-quantity class="mt-0.5 font-medium tabular-nums text-gray-900" x-text="quantityValid ? quantity + ' db' : '–'"></dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-600">Teljes tömeg</dt>
                <dd data-transfer-summary-weight class="mt-0.5 font-medium tabular-nums text-gray-900" x-text="summaryWeight"></dd>
            </div>
            <div x-show="product && warehouse && quantityValid" class="col-span-2 min-w-0">
                <dt class="text-xs font-medium text-gray-600">Kitárolás</dt>
                <dd data-transfer-summary-picking class="mt-0.5 break-words font-medium tabular-nums text-gray-900" x-text="sourceSummary"></dd>
            </div>
        </dl>

        <div class="border-t border-gray-100 pt-3">
            <div x-cloak x-show="!selectedLocation" class="rounded-md border border-dashed border-gray-200 p-3">
                <h3 class="text-xs font-medium text-gray-600">Célrekesz</h3>
                <p class="mt-0.5 text-sm text-gray-700"
                   x-text="destination && destinationChoices === null ? 'A célraktárhoz nincs tárhely beállítva.' : 'Még nincs kiválasztva'"></p>
            </div>
            <template x-if="selectedLocation">
                <div class="space-y-1.5 rounded-md border border-indigo-100 bg-indigo-50/50 p-3">
                    <h3 x-ref="selectedTarget" tabindex="-1" class="text-xs font-medium text-indigo-800 focus-visible:outline-indigo-600">Kiválasztott célhely</h3>
                    <p class="break-words text-sm font-semibold text-gray-900" x-text="selectedLocation.code + ' · ' + selectedLocation.name"></p>
                    <p class="break-words text-xs text-gray-700" x-show="selectedLocation.position_label !== selectedLocation.name" x-text="selectedLocation.position_label"></p>
                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-700">
                        <span x-text="selectionMode === 'recommended' ? 'Ajánlásból kiválasztva' : 'Kézzel kiválasztva'"></span>
                        <template x-if="selectedAdvice">
                            @include('transfers.putaway-status', ['advice' => 'selectedAdvice'])
                        </template>
                    </div>
                    <p x-show="selectedCapacityEstimate" x-text="selectedCapacityEstimate" class="text-xs font-medium text-gray-800"></p>
                    <p x-show="selectedAdvice?.status === 'conditional'" class="text-xs text-gray-700">Az elféréshez további ellenőrzés szükséges.</p>
                    <p x-show="selectionMode === 'recommended' && !selectedAdvice" class="text-xs text-gray-700">Az ellenőrzési állapothoz kérj friss ajánlást.</p>
                    <div class="flex flex-wrap items-center gap-x-3">
                        <button type="button" @click="editTarget()" class="min-h-10 text-xs font-medium text-indigo-700 hover:underline">Célhely módosítása</button>
                        @include('transfers.putaway-plan-link', ['advice' => '(selectedAdvice ?? selectedLocation)'])
                    </div>
                </div>
            </template>
        </div>

        <div x-cloak aria-live="polite">
            <div id="transfer-start-issues" x-show="startIssues.length" class="rounded-md bg-gray-50 px-3 py-2 text-sm">
                <p class="font-medium text-gray-900">Az indításhoz még szükséges</p>
                <ul class="mt-1">
                    <template x-for="issue in startIssues" :key="issue.message">
                        <li>
                            <button type="button" @click="focusIssue(issue.field)" class="flex min-h-8 items-start gap-2 py-1.5 text-left text-xs font-medium text-indigo-700 hover:underline">
                                <span aria-hidden="true">→</span><span x-text="issue.message"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
            <p x-show="!startIssues.length" class="flex items-center gap-2 text-sm font-medium text-emerald-800">
                <span aria-hidden="true">✓</span> A kötelező mezők ki vannak töltve.
            </p>
        </div>

        <div class="space-y-2 border-t border-gray-100 pt-3">
            <button type="submit" :disabled="submitting || startIssues.length > 0" :aria-describedby="startIssues.length ? 'transfer-start-issues' : null"
                    class="min-h-11 w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                    x-text="submitting ? 'Indítás…' : 'Átmozgatás indítása'">Átmozgatás indítása</button>
            <div class="flex items-start justify-between gap-3">
                <p class="text-xs text-gray-600">Indításkor a rendszer ellenőrzi a készletet és a kapacitást.</p>
                <a href="{{ $returnUrl }}" class="shrink-0 text-xs font-medium text-gray-700 underline-offset-2 hover:underline">Mégse</a>
            </div>
        </div>
    </div>
</section>
