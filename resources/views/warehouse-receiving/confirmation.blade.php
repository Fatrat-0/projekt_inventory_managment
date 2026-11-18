<dialog x-ref="confirmation" aria-labelledby="receiving-confirm-title" aria-describedby="receiving-confirm-explanation"
        @cancel.prevent="closeConfirmation()"
        class="m-auto max-h-[calc(100dvh-1.5rem)] w-[calc(100%-1.5rem)] max-w-lg overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 text-gray-900 shadow-xl backdrop:bg-gray-900/55 sm:p-5">
    <form @submit.prevent="record()" class="[overflow-wrap:anywhere]">
        <h2 id="receiving-confirm-title" class="text-lg font-semibold">Átvétel megerősítése</h2>
        <p class="mt-1 text-sm font-medium">{{ $snapshot['shipment']['shipment_number'] }} · {{ $snapshot['stop']['warehouse_name'] }} · {{ $snapshot['stop']['position'] }}. megálló</p>
        <ul class="my-3 divide-y divide-gray-200 border-y border-gray-200 text-sm">
            <template x-for="line in lines" :key="line.id">
                <li class="grid min-w-0 gap-x-3 gap-y-1 py-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                    <p class="font-medium" x-text="line.product_name + ' · ' + line.sku"></p>
                    <p class="font-semibold tabular-nums" x-text="quantity(line.quantity)"></p>
                    <p x-show="line.after > 0" class="text-xs text-amber-900" x-text="quantity(line.after) + ' ebből a tételből tranzitban marad'"></p>
                    <p x-show="line.note" class="text-xs text-gray-700 sm:col-span-2" x-text="line.note"></p>
                </li>
            </template>
        </ul>
        <p x-show="remainder > 0" class="text-sm text-amber-900" x-text="'A célmegállóhoz összesen ' + quantity(remainder) + ' marad tranzitban.'"></p>
        <p x-show="note.trim()" class="mt-2 text-sm text-gray-700"><span class="font-medium">Átvételi megjegyzés</span> <span x-text="note.trim()"></span></p>
        <p id="receiving-confirm-explanation" class="mt-2 text-sm text-gray-700">A rögzített darabok bekerülnek a célraktár fizikai készletébe, és betárolásra várnak. A többi darab úton marad.</p>
        <label class="mt-3 flex min-h-11 items-start gap-2 rounded-md bg-gray-50 p-2 text-sm font-medium">
            <input type="checkbox" x-model="confirmed" :disabled="submitting || unconfirmed || needsRefresh"
                   class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            <span>Ellenőriztem a szállítmányt, és a megadott mennyiségeket ténylegesen átvettem.</span>
        </label>
        <div x-show="submissionError" role="alert" class="mt-3 space-y-1 rounded-md bg-amber-50 p-2 text-sm text-amber-900">
            <p x-text="submissionError"></p>
            <template x-for="[name, messages] in Object.entries(fieldErrors)" :key="name"><p x-text="messages.join(' ')"></p></template>
            <p x-show="needsRefresh">A frissítés után ellenőrizd újra a mennyiségeket. A beírt adatok megmaradnak.</p>
        </div>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button type="button" autofocus @click="closeConfirmation()" :disabled="submitting || unconfirmed" class="{{ $button }}">Vissza</button>
            <button type="button" x-ref="refreshInDialog" x-show="needsRefresh" @click="refresh()" :disabled="submitting" class="{{ $button }}">Átvételi adatok frissítése</button>
            <button type="submit" :disabled="!confirmed || submitting || (!unconfirmed && !canConfirm)" class="{{ $primary }}">
                <span x-text="submitting ? 'Rögzítés…' : (unconfirmed ? 'Átvétel újrapróbálása' : 'Átvétel véglegesítése')">Átvétel véglegesítése</span>
            </button>
        </div>
    </form>
</dialog>

