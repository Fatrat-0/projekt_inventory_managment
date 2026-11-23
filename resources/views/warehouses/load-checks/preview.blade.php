<section x-cloak x-show="preview" class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5" aria-labelledby="load-preview-title">
    <h2 id="load-preview-title" x-ref="previewTitle" tabindex="-1" class="font-semibold text-gray-900">Mérés előnézete</h2>
    <div role="status" class="flex items-start gap-2 rounded-md px-3 py-2 text-sm"
         :class="preview?.can_verify ? 'bg-emerald-50 text-emerald-950' : (criticalPreview ? 'bg-rose-50 text-rose-950' : 'bg-amber-50 text-amber-950')">
        <x-icon name="check-circle" x-show="preview?.can_verify" />
        <x-icon name="warning" x-show="!preview?.can_verify" />
        <div class="min-w-0">
            <p class="font-semibold" x-text="preview?.can_verify ? 'A mérés rögzíthető és az ellenőrzés lezárható.' :
                (criticalPreview ? 'A mérés kapacitáskorlát megsértését jelzi.' : 'A mérés a jelenlegi feltételekkel nem rögzíthető.')"></p>
            <p x-show="criticalPreview && preview?.requires_physical_confirmation">A mérés dokumentálható, de az ellenőrzés nyitva marad.</p>
        </div>
    </div>
    <ul x-show="previewChecks.some(check => check.passed === false)" class="space-y-1 text-sm font-semibold"
        :class="criticalPreview ? 'text-rose-900' : 'text-amber-950'">
        <template x-for="check in previewChecks.filter(check => check.passed === false)" :key="check.key"><li x-text="check.message"></li></template>
    </ul>
    <dl class="grid min-w-0 gap-2 text-sm sm:grid-cols-2">
        <div><dt class="text-xs font-medium text-gray-600">Bruttó tömeg (korábbi → mért)</dt><dd x-text="mass(preview?.placement.recorded_weight_grams) + ' → ' + mass(preview?.measurement.weight_grams)"></dd><dd x-show="!emptyCheck" class="text-gray-600" x-text="'Különbség: ' + difference(preview?.measurement.weight_difference_grams)"></dd></div>
        <div><dt class="text-xs font-medium text-gray-600">Raklaphelyigény (korábbi → mért)</dt><dd x-text="pallet(preview?.placement.recorded_pallet_slots) + ' → ' + pallet(preview?.measurement.pallet_slots)"></dd><dd x-show="!emptyCheck" class="text-gray-600" x-text="'Különbség: ' + difference(preview?.measurement.pallet_difference, true)"></dd></div>
    </dl>
    <details x-show="previewChecks.length" class="rounded-md border border-gray-200 p-3">
        <summary class="cursor-pointer text-sm font-medium">Részletes ellenőrzések</summary>
        <ul class="mt-2 space-y-1 text-sm">
            <template x-for="check in previewChecks" :key="check.key">
                <li :class="check.passed === false ? 'font-medium text-rose-900' : 'text-gray-700'">
                    <strong x-text="check.label"></strong>
                    <span x-text="check.passed === true ? ' · Megfelel' : (check.passed === false ? ' · Nem felel meg' : ' · Nem ellenőrizhető')"></span>
                </li>
            </template>
        </ul>
    </details>
    <ul x-show="preview?.other_discrepancies && Object.keys(preview.other_discrepancies).length"
        class="list-disc pl-5 text-sm text-amber-950">
        <template x-for="(message, key) in preview?.other_discrepancies ?? {}" :key="key"><li x-text="message"></li></template>
    </ul>
    <details x-show="previewCapacity.length" class="rounded-md border border-gray-200 p-3" data-load-capacity-comparison>
        <summary class="cursor-pointer text-sm font-medium">Kapacitás a mérés előtt és után</summary>
        <p class="mt-2 text-xs text-gray-700">A rögzítés várható hatása. A rekeszben az alábbi korlátok egyszerre érvényesek.</p>
        <div class="mt-2 space-y-3">
            <template x-for="scope in previewCapacity" :key="scope.key">
                <div class="space-y-2 border-t border-gray-200 pt-2 text-sm" :data-capacity-scope="scope.key">
                    <h3 class="font-semibold" x-text="scope.label"></h3>
                    <p class="text-xs text-gray-700" x-text="'Névleges határ: ' + capacityValue(scope.limit, scope.unit)"></p>
                    <div class="grid min-w-0 grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] gap-2">
                        <template x-for="phase in ['before', 'after']" :key="phase">
                            <div class="min-w-0 space-y-1 rounded-md bg-gray-50 p-2" :data-capacity-phase="phase">
                                <h4 class="text-xs font-semibold" x-text="phase === 'before' ? 'Mérés előtt' : 'Rögzítés után várható'"></h4>
                                <dl class="space-y-1 text-xs">
                                    <div>
                                        <dt class="text-gray-700">Figyelembe vett terhelés</dt>
                                        <dd class="font-medium" x-text="capacityValue(scope[phase].committed, scope.unit)"></dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-700">Számított kapacitásmaradék</dt>
                                        <dd :class="scope[phase].excess > 0 ? 'font-semibold text-rose-900' : 'font-medium'"
                                            x-text="scope[phase].excess > 0 ? 'Túllépés: ' + capacityValue(scope[phase].excess, scope.unit)
                                                : capacityValue(scope[phase].remaining, scope.unit)"></dd>
                                    </div>
                                    <div>
                                        <dt class="text-gray-700">Új elhelyezésre felhasználható</dt>
                                        <dd class="font-medium" x-text="capacityValue(scope[phase].usable, scope.unit)"></dd>
                                    </div>
                                </dl>
                                <p x-show="scope[phase].block" class="text-xs font-medium text-amber-950"
                                   x-text="scope[phase].block === 'load_discrepancy' ? 'Nyitott eltérés miatt nem használható új elhelyezésre.'
                                       : 'A rekesz ki van kapcsolva.'"></p>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </details>
    @if($worksheet['can_confirm'])
        <button type="button" x-show="canReview" x-ref="openReview" @click="openReview()" class="{{ $primary }}">Tovább a megerősítéshez</button>
    @endif
</section>
