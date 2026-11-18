<dialog x-ref="confirmation" aria-labelledby="putaway-confirm-title" @cancel.prevent="closeConfirmation()"
        class="m-auto max-h-[calc(100dvh-1.5rem)] w-[calc(100%-1.5rem)] max-w-xl overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 text-gray-900 shadow-xl backdrop:bg-gray-900/55 sm:p-5 [overflow-wrap:anywhere]">
    <form @submit.prevent="submit()" class="space-y-4">
        <h2 id="putaway-confirm-title" class="text-lg font-semibold">Betárolás megerősítése</h2>
        <dl class="grid min-w-0 gap-x-4 gap-y-2 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-gray-600">Termék</dt><dd class="font-medium">{{ $item['product_name'] }}</dd></div>
            <div><dt class="text-xs text-gray-600">Betárolandó</dt><dd class="font-medium" x-text="pieces(quantityValue)"></dd></div>
            <div><dt class="text-xs text-gray-600">Célraktár</dt><dd class="font-medium">{{ $item['warehouse_name'] }}</dd></div>
            <div><dt class="text-xs text-gray-600">Célrekesz</dt><dd class="font-medium" x-text="selected?.code"></dd></div>
            <div><dt class="text-xs text-gray-600">Bruttó tömeg</dt><dd class="font-medium" x-text="mass(weightValue)"></dd></div>
            <div><dt class="text-xs text-gray-600">Raklaphelyigény</dt><dd class="font-medium" x-text="slots(palletValue)"></dd></div>
            <div><dt class="text-xs text-gray-600">Fennmaradó mennyiség</dt><dd class="font-medium" x-text="pieces(quantityValue === null ? null : item.pending_putaway_quantity - quantityValue)"></dd></div>
            <div><dt class="text-xs text-gray-600">Alkalmasság</dt><dd class="font-medium" x-text="locationStatus(selected?.status)"></dd></div>
        </dl>
        <p x-show="note.trim()" class="whitespace-pre-wrap text-sm text-gray-700"><strong>Megjegyzés</strong> <span x-text="note"></span></p>
        <p class="text-sm text-gray-700">A művelet hozzárendeli az árut a rekeszhez, és csökkenti a betárolásra váró mennyiséget.</p>
        <div x-show="requiresSiteCheck" class="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
            <ul class="list-disc space-y-0.5 ps-4">
                <template x-for="(reason, index) in checks(selected?.checks?.missing)" :key="index"><li x-text="reason"></li></template>
            </ul>
            <label class="flex items-start gap-2 font-medium">
                <input data-putaway-site-check type="checkbox" x-model="siteVerified" :disabled="draftLocked" class="mt-0.5 rounded border-gray-400 text-indigo-700 focus:ring-indigo-600">
                <span>A helyszínen ellenőriztem, hogy a rakomány elfér a rekeszben.</span>
            </label>
        </div>
        <label class="flex items-start gap-2 rounded-md border border-gray-200 p-3 text-sm font-medium">
            <input type="checkbox" x-model="confirmation" :disabled="draftLocked" class="mt-0.5 rounded border-gray-400 text-indigo-700 focus:ring-indigo-600">
            <span>Ellenőriztem, hogy a megadott mennyiséget ténylegesen ebbe a rekeszbe helyezték.</span>
        </label>
        <p x-show="submissionError" x-text="submissionError" role="alert" class="rounded-md bg-amber-50 p-3 text-sm text-amber-950"></p>
        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" @click="closeConfirmation()" :disabled="submitting" class="{{ $secondary }}" autofocus x-text="pendingCommand ? 'Bezárás' : 'Mégse'"></button>
            <button x-show="pendingCommand" type="button" @click="retry()" :disabled="submitting" class="{{ $primary }}">Mentés újrapróbálása</button>
            <button x-show="!pendingCommand" data-putaway-confirm type="submit" :disabled="!canSave || submitting" class="{{ $primary }}"
                    x-text="submitting ? 'Betárolás rögzítése…' : 'Betárolás rögzítése'"></button>
        </div>
    </form>
</dialog>
