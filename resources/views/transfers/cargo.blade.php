<section aria-labelledby="transfer-cargo-title" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
    <h2 id="transfer-cargo-title" class="font-semibold text-gray-900"><span class="mr-2 text-indigo-700">2.</span>Rakományadatok</h2>
    <div class="grid items-start gap-4 sm:grid-cols-2">
        <div class="min-w-0">
            <label for="transfer-weight" class="block text-sm font-medium">Teljes tömeg (kg)</label>
            <input id="transfer-weight" :value="displayWeight" @input="editWeight($event.target.value)"
                   :required="Boolean(selectedLocation)" type="number" min="0.001" max="1000000" step="0.001"
                   aria-describedby="transfer-weight-help transfer-weight-status" class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
            <input type="hidden" name="cargo_weight_kg" disabled :disabled="!selectedLocation || !effectiveWeight" :value="effectiveWeight">
            <input type="hidden" name="cargo_weight_source" disabled :disabled="!selectedLocation || !weightSource" :value="weightSource">
            <input type="hidden" name="cargo_weight_packaging" disabled :disabled="!selectedLocation || !effectiveWeight" :value="packagingRevision">
            <input type="hidden" name="putaway_weight_mode" :value="weightMode">
            <input type="hidden" name="putaway_weight_kg" :value="manualWeight">
            <input type="hidden" name="putaway_weight_basis" :value="confirmedWeightBasis ?? ''">
            <p id="transfer-weight-help" class="mt-1 text-xs text-gray-600">
                <strong x-cloak x-show="needsWeight" class="font-semibold text-gray-800">Indításhoz kötelező.</strong>
                Csomagolással, raklappal és rögzítéssel együtt.
            </p>
            <div id="transfer-weight-status" x-cloak class="mt-1.5 space-y-1 text-xs text-gray-700" aria-live="polite">
                <div x-show="(weightMode === 'automatic' && calculatedWeight) || (weightMode === 'manual' && manualWeight)"
                     class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span x-show="weightMode === 'automatic' && calculatedWeight" class="rounded-full bg-indigo-50 px-2 py-0.5 font-medium text-indigo-800">Számított</span>
                    <span x-show="weightMode === 'manual' && manualWeight" class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-700">Manuálisan megadva</span>
                    <span x-show="effectiveWeight" class="rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-800">Megerősített</span>
                    <span x-show="weightMode === 'automatic' && calculatedWeight" class="tabular-nums"
                          x-text="calculatedWeight ? calculatedWeight.boxes + ' doboz × ' + weight(calculatedWeight.unitGrams) + ' = ' + weight(calculatedWeight.grams) : ''"></span>
                </div>
                <p x-show="weightMode === 'automatic' && weightCalculationIssue" x-text="weightCalculationIssue"></p>
                <p x-show="stalePackaging">A csomagolás közben módosult. Töltsd újra az űrlapot, és ellenőrizd a teljes tömeget.</p>
                <p x-show="!stalePackaging && weightNeedsConfirmation && weightMode === 'manual'">A rakomány megváltozott. Ellenőrizd a tömeget.</p>
            </div>
            <div x-cloak x-show="(!stalePackaging && weightNeedsConfirmation) || (weightMode === 'manual' && calculatedWeight)"
                 class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                <button id="transfer-weight-confirmation" type="button" x-show="!stalePackaging && weightNeedsConfirmation" @click="confirmWeight(); $nextTick(() => document.getElementById('transfer-weight').focus())"
                        class="min-h-9 rounded-md border border-indigo-200 px-2.5 text-left text-xs font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                        x-text="weightMode === 'automatic' ? 'Ez a teljes tömeg' : 'Tömeg megerősítése'"></button>
                <button type="button" x-show="weightMode === 'manual' && calculatedWeight" @click="useCalculatedWeight(); $nextTick(() => document.getElementById('transfer-weight').focus())"
                        class="min-h-9 rounded text-left text-xs font-medium text-indigo-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Vissza a számítotthoz</button>
            </div>
        </div>
        <div class="min-w-0">
            <label for="transfer-pallet-mode" class="block text-sm font-medium">Raklapigény</label>
            <select id="transfer-pallet-mode" name="putaway_pallet_mode" x-model="palletMode"
                    :aria-describedby="pallet && palletMode !== 'pallet' ? 'transfer-pallet-help' : null"
                    class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
                <option value="unknown">Még nem ismert</option>
                <option value="non-pallet">Nem raklapos</option>
                <option value="pallet">Raklapos</option>
            </select>
            <p id="transfer-pallet-help" x-cloak x-show="pallet && palletMode !== 'pallet'" class="mt-1 text-xs text-gray-600">A célhely raklapot igényel.</p>
            <div x-cloak x-show="palletMode === 'pallet'" class="mt-3 min-w-0">
                <label for="transfer-slots" class="block text-sm font-medium">Szükséges raklaphelyek</label>
                <input id="transfer-slots" :name="selectedLocation ? 'pallet_slots' : 'putaway_pallet_slots'" x-model="cargo.pallet_slots"
                       :required="pallet && palletMode === 'pallet'" :disabled="palletMode !== 'pallet'" type="number"
                       inputmode="numeric" min="1" max="1000000" step="1" class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
            </div>
        </div>
    </div>
    <details class="rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700">
        <summary class="cursor-pointer font-medium">Rakományméretek (opcionális)</summary>
        <p id="transfer-putaway-dimensions-help" class="mt-2 text-xs text-gray-600">Az ajánlások pontosításához add meg a teljes rakomány külső méreteit.</p>
        <div class="mt-2 grid gap-2 sm:grid-cols-3">
            @foreach(['width' => 'Szélesség (cm)', 'depth' => 'Mélység (cm)', 'height' => 'Magasság (cm)'] as $axis => $label)
                <label for="transfer-dimension-{{ $axis }}" class="min-w-0">{{ $label }}
                    <input id="transfer-dimension-{{ $axis }}" name="dimensions_cm[{{ $axis }}]" x-model="dimensions.{{ $axis }}"
                           type="text" inputmode="numeric" aria-describedby="transfer-putaway-dimensions-help"
                           class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
                </label>
            @endforeach
        </div>
    </details>
</section>
