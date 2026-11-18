<section aria-labelledby="transfer-putaway-title" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
    <h2 id="transfer-putaway-title" x-ref="targetChoice" tabindex="-1" class="font-semibold text-gray-900 focus-visible:outline-indigo-600">
        <span class="mr-2 text-indigo-700">3.</span>Célhely kiválasztása
    </h2>
    <p x-show="!destination" class="text-sm text-gray-600">Válassz célraktárt a tárhelyek megtekintéséhez.</p>
    <div x-cloak x-show="destination" class="space-y-3">
        <button type="button" x-show="!recommendationsOpen" @click="openRecommendations()" aria-controls="transfer-recommendations"
                class="min-h-10 text-sm font-medium text-indigo-700 hover:underline">Ajánlások megjelenítése</button>
        <div id="transfer-recommendations" x-show="recommendationsOpen" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                <button type="button" @click="loadRecommendations()" :disabled="!canRecommend || putawayStatus === 'loading'"
                        class="inline-flex min-h-10 items-center gap-2 rounded-md bg-indigo-50 px-3 py-2 text-left text-sm font-semibold text-indigo-800 hover:bg-indigo-100 disabled:opacity-50">
                    <x-icon name="search" />
                    Ajánlott tárolóhelyek keresése
                </button>
                <p x-show="!canRecommend" class="text-xs text-gray-600"
                   x-text="quantityExceedsStock ? 'Csökkentsd a mennyiséget az ajánláskéréshez.' : 'A kereséshez adj meg terméket és érvényes mennyiséget.'"></p>
                <p x-show="recommendations.length" class="text-xs tabular-nums text-gray-600"
                   x-text="recommendations.length + ' találat'" aria-live="polite"></p>
            </div>
            <div aria-live="polite" class="text-sm text-gray-700">
                <p x-show="putawayStatus === 'loading'">Ajánlások keresése…</p>
                <p x-show="putawayStatus === 'error'" x-text="putawayError" class="text-red-700"></p>
                <p x-show="putawayStatus === 'ready' && !recommendations.length">A megadott adatokkal nincs ajánlható tárhely. Ellenőrizd a rakományadatokat, vagy válassz kézzel.</p>
            </div>

            <p x-show="missingCargoInputs" class="rounded-md bg-sky-50 px-3 py-2 text-xs text-sky-900">
                <strong>Az ajánlás pontosításához</strong> <span x-text="missingCargoInputs"></span>.
            </p>
            <ol id="transfer-recommendation-list" x-show="recommendations.length" class="divide-y divide-gray-200 rounded-md border border-gray-200" aria-label="Ajánlott tárolóhelyek">
                <template x-for="recommendation in visibleRecommendations" :key="recommendation.location_id">
                    <li class="grid items-start gap-x-4 gap-y-1 px-3 py-2 text-sm lg:grid-cols-[minmax(0,1fr)_auto]">
                        <details name="transfer-recommendation-details" class="group min-w-0">
                            <summary class="grid min-h-10 cursor-pointer list-none grid-cols-[minmax(0,1fr)_auto] items-start gap-2 rounded text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0 space-y-0.5">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <strong class="break-all font-semibold" x-text="recommendation.code"></strong>
                                        @include('transfers.putaway-status', ['advice' => 'recommendation'])
                                    </span>
                                    <span class="block break-words text-xs text-gray-600" x-text="recommendationPosition(recommendation)"></span>
                                    <span class="block break-words text-xs text-gray-700" x-text="shortReason(recommendation)"></span>
                                </span>
                                <span class="flex items-start gap-1.5 whitespace-nowrap text-right text-xs text-gray-600">
                                    <span>
                                        <strong class="block font-semibold tabular-nums text-gray-900" x-text="weight(recommendation.free_weight_grams)"></strong>
                                        szabad
                                    </span>
                                    <x-icon name="chevron-right" class="mt-0.5 h-3.5 w-3.5 text-gray-500 group-open:rotate-90" />
                                    <span class="sr-only">Részletes ellenőrzések</span>
                                </span>
                            </summary>
                            <div class="mt-2 space-y-2 rounded-md bg-gray-50 p-2.5 text-xs text-gray-700">
                                <p><strong>Sikeres ellenőrzések</strong><br><span x-text="passedChecks(recommendation)"></span></p>
                                <div x-show="onsiteChecks(recommendation).length">
                                    <p class="font-semibold">Helyszínen ellenőrizendő</p>
                                    <ul class="list-disc ps-4">
                                        <template x-for="message in onsiteChecks(recommendation)" :key="message"><li x-text="message"></li></template>
                                    </ul>
                                </div>
                                <p x-show="recommendation.free_pallet_slots != null">
                                    Szabad raklaphelyek <strong x-text="recommendation.free_pallet_slots"></strong>
                                </p>
                                <ul x-show="recommendation.reasons.length" class="list-disc ps-4">
                                    <template x-for="reason in recommendation.reasons" :key="reason"><li x-text="reason"></li></template>
                                </ul>
                            </div>
                        </details>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 lg:justify-end">
                            <button type="button" @click="selectRecommendation(recommendation)"
                                    :aria-label="'Tárhely kiválasztása: ' + recommendation.code"
                                    :aria-pressed="selectedAdvice?.location_id === recommendation.location_id"
                                    class="min-h-10 rounded-md border border-indigo-200 bg-white px-2.5 text-xs font-semibold text-indigo-800 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"
                                    x-text="selectedAdvice?.location_id === recommendation.location_id ? 'Kiválasztva' : 'Kiválasztás'"></button>
                            @include('transfers.putaway-plan-link', ['advice' => 'recommendation'])
                        </div>
                    </li>
                </template>
            </ol>
            <button type="button" x-show="recommendations.length > 3" @click="showAllRecommendations = !showAllRecommendations"
                    :aria-expanded="showAllRecommendations" aria-controls="transfer-recommendation-list"
                    class="min-h-10 text-sm font-medium text-indigo-700 hover:underline"
                    x-text="showAllRecommendations ? 'Kevesebb ajánlás' : 'További ajánlások (' + (recommendations.length - 3) + ')'"></button>
        </div>
        <div x-show="destinationChoices?.length" class="border-t border-gray-100 pt-1">
            <button type="button" @click="toggleManual()" :aria-expanded="manualOpen" aria-controls="transfer-manual"
                    class="min-h-10 text-sm font-medium text-indigo-700 hover:underline">Kézi célhelyválasztás</button>
            <div id="transfer-manual" @manual-target-selected="selectManual($event.detail.value)">
                <template x-if="manualOpen">
                    <div class="mt-1">
                        <label for="transfer-location" class="mb-1 block text-sm font-medium">Cél tárhely</label>
                        <x-autocomplete-picker :items="[]" :name="null" id="transfer-location" items-source="manualChoices"
                            model-value="cargo.to_location_id" :max-results="30" event-name="manual-target-selected"
                            clear-label="Cél tárhely törlése a mezőből" results-label="Tárhelytalálatok"
                            placeholder="Kód, zóna, állvány, szint vagy rekesz…" />
                    </div>
                </template>
            </div>
        </div>
        <p x-show="destinationChoices?.length === 0" class="text-sm text-gray-700">A célraktárban nincs aktív tárhely. Az indításhoz egy tárhelyet aktiválni kell.</p>
    </div>
</section>
