<template x-if="wizard">
    <section aria-label="Új tárolórendszer" class="min-w-0 space-y-3 rounded-lg border border-indigo-200 bg-white p-4" @input="clearWizardFeedback()">
        <header class="flex items-start justify-between gap-2">
            <div><p class="text-xs font-semibold text-indigo-700" x-text="`${step} / 4. lépés`"></p><h2 x-ref="wizardHeading" tabindex="-1" class="font-semibold">Új tárolórendszer</h2></div>
            <button type="button" @click="cancelWizard()" :disabled="saving || savingPlacement" class="min-h-10 px-2 text-sm text-gray-700">Mégse</button>
        </header>
        <button type="button" x-show="previewActive" @click="$refs.wizardPreview.scrollIntoView({ block: 'start' })" class="min-h-10 text-left text-sm font-medium text-indigo-700 lg:hidden"
            x-text="`Előnézet megtekintése · ${draftBinCount} tárolóhely`"></button>
        <fieldset :disabled="saving || savingPlacement" class="space-y-3 text-sm">
            <div x-show="step === 1" class="space-y-2">
                <p class="font-medium">Tárolórendszer típusa</p>
                <template x-for="type in storageTypes" :key="type.kind">
                    <button type="button" @click="chooseType(type.kind)" :aria-pressed="wizard.kind === type.kind"
                        class="block min-h-11 w-full rounded-md border px-3 text-left hover:bg-indigo-50"
                        :class="wizard.kind === type.kind ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200'" x-text="type.label"></button>
                </template>
            </div>
            <div x-show="step === 2" class="space-y-2">
                <p class="font-medium">Méret és felépítés</p>
                @foreach(['name' => 'Tárolórendszer neve', 'code' => 'Tárolórendszer azonosítója', 'zone' => 'Raktári zóna'] as $field => $label)
                    <div>
                        <label for="draft-{{ $field }}">{{ $label }}</label>
                        <input id="draft-{{ $field }}" type="text" maxlength="{{ $field === 'code' ? 24 : 80 }}"
                            @if($field === 'zone') list="floor-zones" @endif x-model="wizard.{{ $field }}"
                            :aria-invalid="!!currentWizardErrors['{{ $field }}']" aria-describedby="draft-{{ $field }}-error"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm aria-[invalid=true]:border-rose-500">
                        @if($field === 'code')<p class="mt-1 text-xs text-gray-600">A felajánlott azonosító átírható.</p>@endif
                        @include('warehouses.designer-wizard-error')
                    </div>
                @endforeach
                <div class="grid grid-cols-2 gap-2">
                    @foreach([
                        'width' => ['Szélesség (m)', 'widthMeters', '0.5', 'Math.max(columns, rows) / 2', '0.5'],
                        'height' => ['Mélység (m)', 'depthMeters', '0.5', 'Math.max(columns, rows) / 2', '0.5'],
                        'levels' => ['Szintek száma', 'levels', '1', '12', '1'],
                        'bins' => ['Tárolóhely szintenként', 'bins', '1', '24', '1'],
                        'rack_height' => ['Állvány magassága (m)', 'rack_height', '0.5', '30', '0.01'],
                    ] as $field => [$label, $model, $min, $max, $increment])
                        <div @if(in_array($field, ['levels', 'rack_height'], true)) x-show="!floorDraft" @endif>
                            <label for="draft-{{ $field }}" @if($field === 'bins') x-text="floorDraft ? 'Padlóhelyek száma' : 'Tárolóhely szintenként'" @endif>{{ $label }}</label>
                            <input id="draft-{{ $field }}" type="number" min="{{ $min }}" :max="{{ $max }}" step="{{ $increment }}"
                                x-model.number="wizard.{{ $model }}" :aria-invalid="!!currentWizardErrors['{{ $field }}']"
                                aria-describedby="draft-{{ $field }}-error" class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm aria-[invalid=true]:border-rose-500">
                            @include('warehouses.designer-wizard-error')
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-gray-600">Egy cella fél méter. Új zónához adj meg új nevet.</p>
                <details x-effect="if (Object.keys(wizardServerErrors).some(key => key.startsWith('bin_dimensions'))) $el.open = true" class="rounded border border-gray-200 p-2">
                    <summary class="cursor-pointer font-medium">Rekeszek mérete (cm)</summary>
                    <p class="mt-2 text-xs text-gray-600">A mezők alatt látható számított érték érvényes, amíg üresen hagyod a mezőt.</p>
                    <div class="mt-2 grid grid-cols-3 gap-1 text-xs">
                        @foreach(['width_cm' => 'Szélesség', 'depth_cm' => 'Mélység', 'height_cm' => 'Magasság'] as $dimension => $label)
                            @php($field = 'bin_dimensions.'.$dimension)
                            <div>
                                <label for="draft-{{ $field }}">{{ $label }}</label>
                                <input id="draft-{{ $field }}" type="number" min="1" x-model.number="wizard.bin_dimensions.{{ $dimension }}"
                                    :aria-invalid="!!currentWizardErrors['{{ $field }}']" aria-describedby="draft-{{ $field }}-error"
                                    class="mt-1 w-full min-w-0 rounded-md border-gray-300 px-1 text-sm aria-[invalid=true]:border-rose-500">
                                <span class="block text-gray-600" x-text="calculatedBinDimensions.{{ $dimension }} > 0 ? `Számított érték · ${calculatedBinDimensions.{{ $dimension }}} cm` : '–'"></span>
                                @include('warehouses.designer-wizard-error')
                            </div>
                        @endforeach
                    </div>
                    @include('warehouses.designer-wizard-error', ['field' => 'bin_dimensions'])
                </details>
            </div>
            <div x-show="step === 3" class="space-y-3">
                <p class="font-medium">Terhelési határok</p>
                @foreach(['bin_weight' => 'Rekeszenkénti terhelési határ (kg)', 'level_weight' => 'Szintenként (kg)', 'system_weight' => 'Teljes rendszer terhelési határa (kg)'] as $field => $label)
                    <div @if($field === 'level_weight') x-show="!floorDraft" @endif>
                        <label for="draft-{{ $field }}">{{ $label }}</label>
                        <input id="draft-{{ $field }}" type="number" min="0.001" max="1000000" step="0.001" x-model.number="wizard.{{ $field }}"
                            :aria-invalid="!!currentWizardErrors['{{ $field }}']" aria-describedby="draft-{{ $field }}-error"
                            class="mt-1 w-full rounded-md border-gray-300 text-sm aria-[invalid=true]:border-rose-500">
                        @include('warehouses.designer-wizard-error')
                    </div>
                @endforeach
                <details x-show="!floorDraft" x-effect="if (Object.keys(wizardServerErrors).some(key => key.startsWith('level_weights'))) $el.open = true" class="rounded border border-gray-200 p-2">
                    <summary class="cursor-pointer font-medium">Eltérő teherbírás szintenként</summary>
                    <p class="mt-2 text-xs text-gray-600">Az üres mezők a közös szinthatárt használják.</p>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <template x-for="number in draftLevels" :key="number">
                            <div>
                                <label :for="`draft-level-${number}`" x-text="`${number}. szint (kg)`"></label>
                                <input :id="`draft-level-${number}`" type="number" min="0.001" max="1000000" step="0.001" x-model.number="wizard.level_weights[number]"
                                    :aria-invalid="!!currentWizardErrors[`level_weights.${number}`]" :aria-describedby="`draft-level-${number}-error`"
                                    class="mt-1 w-full min-w-0 rounded-md border-gray-300 px-2 text-sm aria-[invalid=true]:border-rose-500">
                                <p :id="`draft-level-${number}-error`" x-show="currentWizardErrors[`level_weights.${number}`]" x-text="currentWizardErrors[`level_weights.${number}`]" class="mt-1 text-xs text-rose-700"></p>
                            </div>
                        </template>
                    </div>
                    @include('warehouses.designer-wizard-error', ['field' => 'level_weights'])
                </details>
                <p x-show="wizard.kind !== 'rack'" class="text-xs text-gray-600">Egy tárolóhely egy raklapot fogad.</p>
            </div>
            <div x-show="step === 4" class="space-y-2">
                <p class="font-medium">Elhelyezés a tervrajzon</p>
                <p x-show="positioning" class="text-xs text-gray-600">Kattints a kívánt bal felső cellára, vagy használd a nyilakat és az Entert. Az R forgat, az Esc visszalép.</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" x-show="positioning" @click="tool = 'select'; $refs.viewport.scrollIntoView({ block: 'center' }); $refs.viewport.focus({ preventScroll: true })" class="min-h-10 rounded border border-gray-300 px-2 text-xs font-medium">Ugrás a tervrajzhoz</button>
                    <button type="button" x-show="positioning" @click="rotatePlacement()" class="min-h-10 rounded border border-gray-300 px-2 text-xs font-medium">Előnézet forgatása</button>
                    <button type="button" x-show="positionSelected" @click="editPlacement()" class="min-h-10 rounded border border-indigo-300 px-2 text-xs font-medium text-indigo-700">Pozíció módosítása</button>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    @foreach(['x' => 'Balról (X)', 'y' => 'Felülről (Y)'] as $field => $label)
                        <div>
                            <label for="draft-{{ $field }}">{{ $label }}</label>
                            <input id="draft-{{ $field }}" type="number" min="0" max="99" x-model.number="wizard.{{ $field }}" @input="placementHover = null"
                                :readonly="!positioning" :aria-invalid="(positioning && !!placementIssue(placement)) || !!currentWizardErrors['{{ $field }}']" aria-describedby="draft-placement draft-{{ $field }}-error"
                                class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm aria-[invalid=true]:border-rose-500">
                            @include('warehouses.designer-wizard-error')
                        </div>
                    @endforeach
                </div>
                <p id="draft-placement" role="status" class="text-xs font-medium" :class="positioning && placementIssue(placement) ? 'text-rose-700' : 'text-emerald-800'"
                    x-text="savingPlacement ? 'Elhelyezés mentése…' : (positionSelected ? 'Pozíció rögzítve' : (placementIssue(placement) || 'A megadott koordináták rögzíthetők.'))"></p>
                <button type="button" x-show="positioning" @click="selectPlacement(placement)" :disabled="!!placementIssue(placement)" class="min-h-10 rounded border border-emerald-300 px-2 text-xs font-medium text-emerald-800 disabled:opacity-50">Pozíció rögzítése</button>
                @include('warehouses.designer-wizard-error', ['field' => 'positions'])
                <div class="space-y-1 rounded-md bg-slate-50 p-2 text-xs text-slate-800">
                    <strong class="block break-words" x-text="`${draftCode} · ${wizard.name}`"></strong>
                    <p x-text="`${draftBinCount} új tárolóhely jön létre.`"></p>
                    <p x-show="draftCapacity" x-text="draftCapacity ? `Használható összkapacitás · ${kilograms(draftCapacity.usable)} kg` : ''"></p>
                </div>
            </div>
        </fieldset>
        <p x-show="wizardError" role="alert" class="text-sm text-rose-700" x-text="wizardError"></p>
        <div class="flex flex-wrap gap-2">
            <button type="button" x-show="step > 1" @click="previousStep()" :disabled="saving || savingPlacement" class="min-h-10 rounded border border-gray-300 px-3 text-sm">Vissza</button>
            <button type="button" x-show="step < 4" @click="nextStep()" :disabled="Object.keys(currentWizardErrors).length > 0" class="min-h-10 rounded bg-indigo-600 px-3 text-sm font-medium text-white disabled:opacity-50">Tovább</button>
            <button type="button" x-show="step === 4" @click="confirmSystem()" :disabled="!canCreateSystem" class="min-h-10 rounded bg-indigo-600 px-3 text-sm font-medium text-white disabled:opacity-50" x-text="savingPlacement ? 'Mentés…' : 'Létrehozás és elhelyezés'"></button>
        </div>
    </section>
</template>
