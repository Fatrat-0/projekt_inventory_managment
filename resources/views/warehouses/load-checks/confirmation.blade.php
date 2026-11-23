<dialog x-ref="dialog" @cancel.prevent="closeReview()" aria-labelledby="load-confirm-title"
        class="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-lg border border-gray-200 bg-white p-0 text-gray-900 shadow-xl backdrop:bg-slate-950/40 [overflow-wrap:anywhere]">
    <div class="min-w-0 space-y-3 p-4 sm:p-5">
        <h2 id="load-confirm-title" x-ref="dialogTitle" tabindex="-1" class="text-lg font-semibold">Helyszíni mérés megerősítése</h2>
        <dl class="grid min-w-0 gap-2 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-gray-600">Termék</dt><dd class="font-medium" x-text="preview?.placement.product.name"></dd></div>
            <div><dt class="text-xs text-gray-600">Rekesz</dt><dd x-text="preview?.placement.location.code"></dd></div>
            <div><dt class="text-xs text-gray-600">Aktuális mennyiség</dt><dd x-text="preview ? preview.placement.quantity + ' db' : ''"></dd></div>
            <div><dt class="text-xs text-gray-600">Mért bruttó tömeg és raklaphely</dt><dd x-text="mass(preview?.measurement.weight_grams) + ' · ' + pallet(preview?.measurement.pallet_slots)"></dd></div>
        </dl>
        <div x-show="criticalPreview" class="flex items-start gap-2 rounded-md border border-rose-300 bg-rose-50 p-3 text-sm text-rose-950">
            <x-icon name="warning" />
            <div><p class="font-semibold">A megadott mérés kapacitáskorlát megsértését jelzi.</p>
                <p>A mérést naplózzuk. Az ellenőrzés nyitva marad, a további terhelés korlátozott.</p></div>
        </div>
        <p x-show="!criticalPreview" class="text-sm font-medium text-emerald-900">Várható eredmény: az ellenőrzés lezárul.</p>
        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" x-model="confirmed" :disabled="submitting || !!retry" class="mt-0.5 rounded border-gray-300 text-indigo-600">
            <span x-text="emptyCheck ? 'Megerősítem, hogy ebből a tételből a helyszínen ellenőrizve semmi sem maradt a rekeszben.' : 'Megerősítem, hogy az értékeket a helyszínen mértem vagy ellenőriztem.'"></span>
        </label>
        <p x-show="submissionError" role="alert" x-text="submissionError" class="rounded-md bg-amber-50 p-2 text-sm text-amber-950"></p>
        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" @click="closeReview()" :disabled="submitting" class="{{ $secondary }}">Vissza</button>
            <button type="button" @click="save()" :disabled="submitting || (!retry && (!confirmed || !canReview))" class="inline-flex min-h-11 items-center justify-center rounded-md px-4 py-2 text-sm font-semibold text-white focus-visible:outline focus-visible:outline-2 disabled:opacity-50"
                    :class="criticalPreview ? 'bg-rose-700 hover:bg-rose-800 focus-visible:outline-rose-700' : 'bg-indigo-600 hover:bg-indigo-700 focus-visible:outline-indigo-600'"
                    x-text="submitting ? 'Rögzítés folyamatban…' : (retry ? 'Mentés újrapróbálása' : (criticalPreview ? 'Eltérés rögzítése' : 'Mérés rögzítése'))"></button>
        </div>
    </div>
</dialog>
