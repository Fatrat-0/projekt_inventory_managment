<dialog x-ref="confirmation" @cancel.prevent="closeReview()" aria-labelledby="relocation-confirm-title"
        class="max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-lg border border-gray-200 bg-white p-0 text-gray-900 shadow-xl backdrop:bg-slate-950/40">
    <div class="min-w-0 space-y-4 p-4 sm:p-5">
        <h2 id="relocation-confirm-title" x-ref="reviewTitle" tabindex="-1" class="text-lg font-semibold">Áthelyezés megerősítése</h2>
        <dl class="grid min-w-0 gap-3 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-gray-600">Termék</dt><dd x-text="chosenProduct?.label"></dd></div>
            <div><dt class="text-xs text-gray-600">Raktár</dt><dd x-text="warehouseName"></dd></div>
            <div><dt class="text-xs text-gray-600">Útvonal</dt><dd class="font-medium" x-text="(source?.code ?? '—') + ' → ' + (selected?.code ?? '—')"></dd></div>
            <div><dt class="text-xs text-gray-600">Áthelyezendő</dt><dd class="font-semibold" x-text="pieces(reviewed?.cargo.quantity)"></dd></div>
            <div><dt class="text-xs text-gray-600">Bruttó tömeg</dt><dd x-text="mass(reviewed?.cargo.cargo_weight_grams)"></dd></div>
            <div><dt class="text-xs text-gray-600">Raklaphelyigény</dt><dd x-text="slots(reviewed?.cargo.pallet_slots)"></dd></div>
            <div x-show="reviewed?.cargo.dimensions_cm"><dt class="text-xs text-gray-600">Külső méretek (Sz × M × Ma)</dt><dd
                x-text="reviewed?.cargo.dimensions_cm ? ['width', 'depth', 'height'].map(axis => reviewed.cargo.dimensions_cm[axis] ?? '—').join(' × ') + ' cm' : ''"></dd></div>
            <div><dt class="text-xs text-gray-600">Forrásban maradna</dt><dd x-text="pieces(reviewed ? reviewed.source.stored_quantity - reviewed.cargo.quantity : null)"></dd></div>
            <div><dt class="text-xs text-gray-600">Célban lenne</dt><dd x-text="pieces(reviewed?.location.product_stock?.bin?.after)"></dd></div>
            <div><dt class="text-xs text-gray-600">Raktári készletváltozás</dt><dd class="font-semibold text-emerald-800">0 db</dd></div>
        </dl>
        @include('warehouse-relocations.checks', ['phase' => 'review'])
        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" x-model="confirmed" :disabled="draftLocked" class="mt-0.5 rounded border-gray-300 text-indigo-600">
            Megerősítem, hogy a megadott árut fizikailag áthelyeztem a célrekeszbe.
        </label>
        <p x-show="submissionError" x-text="submissionError" role="alert" class="rounded-md bg-amber-50 p-2 text-sm text-amber-900"></p>
        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" @click="closeReview()" :disabled="submitting" class="{{ $secondary }}">Vissza a szerkesztéshez</button>
            <button type="button" @click="save()" :disabled="submitting || (!pendingCommand && (!confirmed || !reviewed?.allowed))" class="{{ $primary }}"
                    x-text="submitting ? 'Rögzítés folyamatban…' : (pendingCommand ? 'Mentés újrapróbálása' : 'Áthelyezés rögzítése')"></button>
        </div>
    </div>
</dialog>
