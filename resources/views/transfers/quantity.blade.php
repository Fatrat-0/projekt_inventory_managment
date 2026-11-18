<div class="min-w-0 border-t border-gray-100 pt-3 sm:col-span-2" data-transfer-quantity-controls>
    <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
        <div class="min-w-0 sm:w-full sm:max-w-56">
            <div x-show="!boxMode">
                <label for="transfer-quantity" class="mb-1 block text-sm font-medium">Mennyiség (db)</label>
                <input id="transfer-quantity" type="text" inputmode="numeric" name="quantity" value="{{ $field('quantity', '1') }}"
                       :value="quantityDraft" @input="editQuantity($event.target.value)" :disabled="boxMode" required
                       class="min-h-11 w-full rounded-md border-gray-300 text-sm tabular-nums"
                       aria-invalid="{{ $errors->has('quantity') ? 'true' : 'false' }}"
                       :aria-invalid="String(Boolean(quantityInputError) || @js($errors->has('quantity')))"
                       aria-describedby="transfer-quantity-help transfer-quantity-input-error{{ $errors->has('quantity') ? ' transfer-quantity-error' : '' }}"
                       :aria-describedby="(packaging ? 'transfer-packaging-help' : 'transfer-quantity-help') + ' transfer-quantity-input-error{{ $errors->has('quantity') ? ' transfer-quantity-error' : '' }}'">
            </div>
            <div x-cloak x-show="boxMode">
                <label for="transfer-box-count" class="mb-1 block text-sm font-medium">Mennyiség (doboz)</label>
                <input id="transfer-box-count" type="text" inputmode="numeric" name="box_count" disabled
                       :value="quantityDraft" @input="editQuantity($event.target.value)" :disabled="!boxMode" required
                       class="min-h-11 w-full rounded-md border-gray-300 text-sm tabular-nums"
                       :aria-invalid="String(Boolean(quantityInputError) || @js($errors->has('box_count')) || @js($errors->has('quantity')))"
                       aria-describedby="transfer-packaging-help transfer-box-equivalent transfer-quantity-input-error{{ $errors->has('box_count') ? ' transfer-box-count-error' : '' }}{{ $errors->has('quantity') ? ' transfer-quantity-error' : '' }}">
                <input type="hidden" name="quantity" disabled :disabled="!boxMode" :value="quantity">
            </div>
        </div>

        <div x-cloak x-show="packaging" role="group" aria-label="Mennyiség egysége"
             class="inline-flex min-h-11 max-w-full self-start rounded-md border border-gray-300 bg-white p-0.5 text-sm sm:self-auto">
            <button type="button" @click="usePieces()" :aria-pressed="!boxMode"
                    class="rounded px-3 hover:bg-gray-50 aria-pressed:bg-indigo-50 aria-pressed:font-semibold aria-pressed:text-indigo-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Darab</button>
            <button type="button" @click="useBoxes()" :aria-pressed="boxMode"
                    class="rounded px-3 hover:bg-gray-50 aria-pressed:bg-indigo-50 aria-pressed:font-semibold aria-pressed:text-indigo-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Doboz</button>
        </div>

        @if($replenishment === '')
            <button type="button" x-cloak x-show="stock > 0" @click="fillAvailableQuantity()"
                    class="min-h-11 self-start rounded text-left text-xs font-medium text-indigo-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 sm:px-1 sm:self-auto">
                Teljes kiadható mennyiség
            </button>
        @endif
    </div>

    <div class="mt-1.5 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-xs">
        <p id="transfer-quantity-help" x-show="!packaging" class="text-gray-600">A készletet darabban tartjuk nyilván.</p>
        <p id="transfer-packaging-help" x-cloak x-show="packaging" class="break-words text-gray-600"
           x-text="packaging ? packaging.name + ' · 1 doboz = ' + packaging.pieces + ' db' : ''"></p>
        <p id="transfer-box-equivalent" x-cloak x-show="boxMode && quantityValid" aria-live="polite" class="font-semibold tabular-nums text-gray-900"
           x-text="quantityValid ? quantity + ' db kerül átmozgatásra.' : ''"></p>
    </div>

    <p id="transfer-quantity-input-error" x-cloak x-show="quantityInputError" x-text="quantityInputError"
       aria-live="polite" class="mt-1 text-sm text-red-700"></p>

    <div x-cloak x-show="confirmBoxChange" class="mt-1 text-xs text-gray-700">
        <p role="status">A megadott darabszám nem váltható át egész dobozokra.</p>
        <button type="button" @click="useBoxes(true)" class="min-h-9 text-left font-medium text-indigo-700 hover:underline">Új dobozmennyiség megadása</button>
    </div>
    @error('quantity')<p id="transfer-quantity-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
    @error('box_count')<p id="transfer-box-count-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
</div>
