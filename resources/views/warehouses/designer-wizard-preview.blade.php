<template x-if="previewActive">
    <section x-ref="wizardPreview" aria-labelledby="wizard-preview-title" class="order-last min-w-0 rounded-lg border border-gray-200 bg-white lg:sticky lg:order-none"
        style="top: calc(var(--app-topbar-height, 4rem) + 1rem); scroll-margin-top: calc(var(--app-topbar-height, 4rem) + 1rem)">
        <header class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-200 p-4">
            <div>
                <h2 id="wizard-preview-title" class="font-semibold text-gray-900" x-text="floorDraft ? 'Padlóhelyek előnézete' : 'Állvány-előnézet'"></h2>
                <p class="text-sm text-gray-600">Válassz egy rekeszt a méretei és a terhelési határa megtekintéséhez.</p>
                <p class="text-xs text-gray-600">A rekeszméretek sorrendje szélesség × mélység × magasság.</p>
            </div>
            <strong class="rounded-full bg-indigo-50 px-3 py-1 text-sm text-indigo-800" x-text="`${draftBinCount} tárolóhely`"></strong>
        </header>
        <div x-show="step === 3 && draftCapacity" class="space-y-3 border-b border-gray-200 p-4">
            <dl class="grid grid-cols-2 gap-3 text-sm xl:grid-cols-4">
                <div><dt class="text-xs text-gray-600">Rekeszhatárok összege</dt><dd class="font-semibold" x-text="draftCapacity ? `${kilograms(draftCapacity.nominal)} kg` : '–'"></dd></div>
                <div x-show="!floorDraft"><dt class="text-xs text-gray-600">Szinthatárokkal számolva</dt><dd class="font-semibold" x-text="draftCapacity ? `${kilograms(draftCapacity.levels)} kg` : '–'"></dd></div>
                <div><dt class="text-xs text-gray-600">Teljes rendszer határa</dt><dd class="font-semibold" x-text="draftCapacity ? `${kilograms(draftCapacity.system)} kg` : '–'"></dd></div>
                <div class="rounded bg-indigo-50 px-2 py-1 text-indigo-900">
                    <dt class="text-xs font-medium">Használható összkapacitás</dt>
                    <dd data-wizard-usable class="text-lg font-semibold tabular-nums" x-text="draftCapacity ? `${kilograms(draftCapacity.usable)} kg` : '–'"></dd>
                </div>
            </dl>
            <p x-show="draftCapacity && draftCapacity.levels < draftCapacity.nominal" class="rounded bg-amber-50 px-3 py-2 text-xs text-amber-900">Legalább egy szint határa kisebb a rekeszei névleges teherbírásának összegénél. A szinten a kisebb érték használható.</p>
            <p x-show="draftCapacity && draftCapacity.system < draftCapacity.levels" class="rounded bg-amber-50 px-3 py-2 text-xs text-amber-900">A teljes rendszer határa kisebb a tárolóhelyek együttesen használható teherbírásánál. Ez a határ korlátozza az összkapacitást.</p>
        </div>
        <p x-show="!draftBinCount" class="p-4 text-sm text-gray-600">Az előnézethez adj meg érvényes szint- és rekeszszámot.</p>
        <div x-show="draftBinCount" tabindex="0" aria-label="Görgethető rekesznézet" class="max-h-[55vh] overflow-auto bg-slate-50 p-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
            <div class="min-w-max space-y-2">
                <template x-for="level in levelLoads.slice().reverse()" :key="level.number">
                    <div class="rounded-md border border-slate-300 bg-white p-2">
                        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-x-3">
                            <h3 class="text-xs font-semibold" x-text="floorDraft ? 'Padlóhelyek' : `${level.number}. szint`"></h3>
                            <span x-show="step === 3 && draftCapacity && !floorDraft" class="text-xs text-gray-600"
                                x-text="`${kilograms(level.usable)} kg használható · ${kilograms(Number(level.limit) * 1000)} kg szinthatár`"></span>
                        </div>
                        <div class="grid gap-1.5" :style="{ gridTemplateColumns: `repeat(${draftBins}, minmax(6rem, 1fr))` }">
                            <template x-for="bin in draftBins" :key="bin">
                                <button type="button" data-wizard-bin :aria-pressed="previewSelection?.level === level.number && previewSelection?.bin === bin"
                                    @click="previewSelection = { level: level.number, bin }"
                                    class="min-h-14 rounded border border-indigo-200 bg-indigo-50 p-2 text-left text-xs text-indigo-900 hover:border-indigo-500 aria-pressed:ring-2 aria-pressed:ring-inset aria-pressed:ring-indigo-600">
                                    <strong class="block break-all" x-text="binCode(level.number, bin)"></strong>
                                    <span class="block text-slate-700" x-text="previewDimensionLabel"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
        <p x-show="previewSelection && previewSelection.level <= draftLevels && previewSelection.bin <= draftBins" class="border-t border-gray-200 p-3 text-sm text-gray-700"
            x-text="previewSelection ? `${binCode(previewSelection.level, previewSelection.bin)} · ${previewDimensionLabel}${step === 3 && draftCapacity ? ` · ${kilograms(wizard.bin_weight * 1000)} kg rekeszhatár` : ''}` : ''"></p>
    </section>
</template>
