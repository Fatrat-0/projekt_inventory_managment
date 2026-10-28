<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-6">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Készletmozgások</a>
        <h2 class="mt-3 mb-5 text-xl font-bold text-gray-900">Új készletmozgás</h2>
        @if($errors->any())<div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @error('request_key')
            <a href="{{ route('movements.create', ['context' => $context]) }}" class="mb-4 inline-block text-sm font-medium text-indigo-600 underline">Új készletmozgás indítása</a>
        @enderror
        @if($products->isEmpty() || $warehouses->isEmpty())
            <div role="status" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Mozgás rögzítéséhez legalább egy termék és egy raktár szükséges.</div>
        @else
            <form method="POST" action="{{ route('movements.store') }}" x-data="movementForm({{ Illuminate\Support\Js::from(['endpoint' => route('movements.stock'), 'product' => (string)old('product_id', ''), 'warehouse' => (string)old('warehouse_id', ''), 'type' => old('type', 'in'), 'quantity' => old('quantity', 1)]) }})" @submit="submit($event)" class="rounded-lg border border-gray-200 bg-white p-5 sm:p-6 shadow-sm space-y-5">
                @csrf
                <input type="hidden" name="request_key" value="{{ $requestKey }}">
                <input type="hidden" name="context" value="{{ $context }}">
                <div><label for="movement-type" class="block text-sm font-medium text-gray-700 mb-1">Mozgás típusa</label><select id="movement-type" name="type" x-model="type" class="w-full rounded-md {{ $errors->has('type') ? 'border-red-500' : 'border-gray-300' }}" required aria-invalid="{{ $errors->has('type') ? 'true' : 'false' }}" @if($errors->has('type')) aria-describedby="movement-type-error" @endif><option value="in" @selected(old('type','in') === 'in')>Bevételezés – készlet növelése</option><option value="out" @selected(old('type') === 'out')>Kiadás – készlet csökkentése</option></select>@error('type')<p id="movement-type-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div @product-selected="product = $event.detail.value; loadStock()">
                    <label for="movement-product" class="block text-sm font-medium text-gray-700 mb-1">Termék</label>
                    <x-product-picker :products="$products" id="movement-product" :value="old('product_id', '')" :required="true" />
                </div>
                <div @warehouse-selected="warehouse = $event.detail.value; loadStock()">
                    <label for="movement-warehouse" class="block text-sm font-medium text-gray-700 mb-1">Raktár</label>
                    @php($warehouseItems = $warehouses->map(fn($warehouse) => ['value' => (string)$warehouse->warehouse_id, 'label' => $warehouse->warehouse_name.($warehouse->location ? ' · '.$warehouse->location : '')])->values())
                    <x-autocomplete-picker :items="$warehouseItems" id="movement-warehouse" name="warehouse_id" :value="old('warehouse_id', '')" :required="true" placeholder="Raktár neve vagy helye…" event-name="warehouse-selected" />
                </div>
                <div role="status" aria-live="polite" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-600">
                    <span x-text="stockText">Válassz terméket és raktárt a készlet megtekintéséhez.</span>
                    <button type="button" x-show="product && warehouse" @click="loadStock()" class="ml-2 text-indigo-600 underline">Frissítés</button>
                    <p class="mt-1 text-xs text-gray-500">Tájékoztató adat; mentéskor a rendszer újra ellenőrzi a rendelkezésre álló készletet.</p>
                </div>
                <div><label for="movement-quantity" class="block text-sm font-medium text-gray-700 mb-1">Mennyiség (db)</label><input id="movement-quantity" type="number" name="quantity" x-model="quantity" min="1" max="2147483647" step="1" value="{{ old('quantity',1) }}" required class="w-full rounded-md {{ $errors->has('quantity') ? 'border-red-500' : 'border-gray-300' }}" aria-invalid="{{ $errors->has('quantity') ? 'true' : 'false' }}" @if($errors->has('quantity')) aria-describedby="movement-quantity-error" @endif>@error('quantity')<p id="movement-quantity-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror<p x-show="type === 'out' && stock !== null && Number(quantity) > stock" style="display: none;" class="mt-1 text-sm text-red-700">A megadott mennyiség meghaladja a legutóbb lekért készletet.</p></div>
                <div><label for="movement-reference" class="block text-sm font-medium text-gray-700 mb-1">Bizonylatszám vagy megjegyzés <span class="font-normal text-gray-400">(opcionális)</span></label><input id="movement-reference" name="reference" maxlength="255" value="{{ old('reference') }}" placeholder="Például: BEV-2026/001" class="w-full rounded-md {{ $errors->has('reference') ? 'border-red-500' : 'border-gray-300' }}" aria-invalid="{{ $errors->has('reference') ? 'true' : 'false' }}" @if($errors->has('reference')) aria-describedby="movement-reference-error" @endif>@error('reference')<p id="movement-reference-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div class="flex justify-end items-center gap-4 border-t border-gray-100 pt-4"><a href="{{ $returnUrl }}" class="text-sm text-gray-600">Mégse</a><button type="submit" :disabled="submitting" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" x-text="submitting ? 'Mentés…' : 'Mozgás rögzítése'">Mozgás rögzítése</button></div>
            </form>
        @endif
    </div>
</x-app-layout>
