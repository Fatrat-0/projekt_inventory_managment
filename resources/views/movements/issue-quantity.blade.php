<div class="min-w-0">
    <label for="movement-quantity" class="mb-1 block text-sm font-medium text-gray-700" x-text="boxMode ? 'Mennyiség (doboz)' : 'Mennyiség (db)'">Mennyiség (db)</label>
    <div class="flex flex-wrap items-center gap-1.5">
        <input id="movement-quantity" type="number" name="quantity" :value="quantityDraft" @input="editQuantity($event.target.value)"
        min="1" max="2147483647" :max="boxMode ? maxBoxCount : 2147483647" step="1" value="{{ old('quantity', 1) }}" required
        class="min-w-[6.25rem] flex-1 rounded-md {{ $errors->has('quantity') ? 'border-red-500' : 'border-gray-300' }}"
        aria-invalid="{{ $errors->has('quantity') ? 'true' : 'false' }}" :aria-invalid="Boolean(quantityInputError) || @js($errors->has('quantity'))"
        aria-describedby="{{ $errors->has('quantity') ? 'movement-quantity-error' : 'movement-quantity-help' }}">
        <div x-show="packaging" x-cloak role="group" aria-label="Mennyiség egysége" class="flex shrink-0 gap-0.5 rounded-md border border-gray-200 p-0.5 text-sm">
            <button type="button" @click="usePieces()" :aria-pressed="!boxMode" :class="!boxMode ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-gray-700'" class="min-h-9 rounded px-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Darab</button>
            <button type="button" @click="useBoxes()" :aria-pressed="boxMode" :class="boxMode ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-gray-700'" class="min-h-9 rounded px-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Doboz</button>
        </div>
    </div>
    <div id="movement-quantity-help" x-cloak class="space-y-1 text-xs">
        <p x-show="packaging && !boxMode" class="mt-1 text-gray-600" x-text="packaging ? '1 doboz = ' + formatStock(packaging.pieces) : ''"></p>
        <p x-show="boxMode && quantityValid" class="mt-1 text-gray-700" x-text="packaging ? quantityDraft + ' doboz × ' + formatStock(packaging.pieces) + ' = ' + formatStock(quantity) : ''"></p>
        <p x-show="quantityInputError" class="mt-1 text-red-700" x-text="quantityInputError"></p>
        <p x-show="confirmBoxChange" class="mt-1 text-amber-900">A megadott mennyiség nem váltható egész dobozokra. A darabszám megmaradt.</p>
    </div>
    @error('quantity')<p id="movement-quantity-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
</div>
