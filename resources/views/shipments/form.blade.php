<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-6 space-y-5">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Szállítmányok</a>
        <h2 class="text-xl font-bold text-gray-900">{{ $shipment ? 'Rakodási terv szerkesztése' : 'Új rakodási terv' }}</h2><x-operation-feedback />
        @if($products->isEmpty() || $warehouses->count() < 2)<p class="rounded-md bg-amber-50 p-4 text-sm text-amber-900">A tervezéshez legalább egy termék és két raktár szükséges.</p>@else
        @php($warehouseItems = $warehouses->map(fn($w) => ['value' => (string)$w->warehouse_id, 'label' => $w->warehouse_name])->values())
        <form method="POST" action="{{ $shipment ? route('shipments.update', $shipment) : route('shipments.store') }}" x-data="shipmentBuilder({{ Illuminate\Support\Js::from(['stops' => $stops, 'origin' => $values['origin_warehouse_id'], 'warehouses' => $warehouseItems]) }})" @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" class="space-y-5">
            @csrf<input type="hidden" name="context" value="{{ $context }}"> @if($shipment) @method('PUT')<input type="hidden" name="version" value="{{ $values['version'] }}">@endif
            <div class="rounded-lg border border-gray-200 bg-white p-5 grid sm:grid-cols-2 gap-4">
                <div><label for="shipment-reference" class="block text-sm font-medium mb-1">Szállítmány hivatkozása</label><input id="shipment-reference" name="reference" value="{{ $values['reference'] }}" maxlength="100" required placeholder="Például: Nyugati körjárat" class="w-full rounded-md border-gray-300"></div>
                <div><label for="shipment-vehicle" class="block text-sm font-medium mb-1">Jármű / rendszám (opcionális)</label><input id="shipment-vehicle" name="vehicle_label" value="{{ $values['vehicle_label'] }}" maxlength="100" class="w-full rounded-md border-gray-300"></div>
                <div @origin-selected="setOrigin($event.detail.value)"><label for="shipment-origin" class="block text-sm font-medium mb-1">Induló raktár</label><x-autocomplete-picker :items="$warehouseItems" id="shipment-origin" name="origin_warehouse_id" :value="$values['origin_warehouse_id']" :required="true" event-name="origin-selected" placeholder="Raktár neve…" /></div>
                <div><label for="shipment-note" class="block text-sm font-medium mb-1">Megjegyzés (opcionális)</label><textarea id="shipment-note" name="note" maxlength="1000" rows="2" class="w-full rounded-md border-gray-300">{{ $values['note'] }}</textarea></div>
                <p class="sm:col-span-2 text-xs text-gray-500">A tervezet nem foglal és nem von le készletet. A teljes rakomány rendelkezésre állását indításkor ellenőrizzük.</p>
            </div>
            <section aria-label="Megállók szerkesztése" class="space-y-4">
                <div><h3 class="font-semibold text-gray-900">Lerakodási sorrend</h3><p class="text-sm text-gray-500">Az útvonal sorrendjét a fel/le gombokkal állíthatod. Egy célraktár egyszer szerepelhet.</p></div>
                <template x-for="(stop, stopIndex) in stops" :key="stop.key">
                    <fieldset class="rounded-lg border border-gray-200 bg-white p-4 sm:p-5 space-y-4">
                        <legend class="sr-only" x-text="(stopIndex + 1) + '. megálló'"></legend>
                        <div class="flex flex-wrap items-center gap-2"><span class="rounded-md bg-indigo-50 px-2.5 py-1 text-sm font-semibold text-indigo-700" x-text="(stopIndex + 1) + '. megálló'"></span><div class="ml-auto flex gap-2">
                            <button type="button" @click="move(stopIndex, -1)" :disabled="stopIndex === 0" :aria-label="(stopIndex + 1) + '. megálló előrébb'" class="rounded border px-3 py-1 text-sm disabled:opacity-30">↑</button>
                            <button type="button" @click="move(stopIndex, 1)" :disabled="stopIndex === stops.length - 1" :aria-label="(stopIndex + 1) + '. megálló hátrébb'" class="rounded border px-3 py-1 text-sm disabled:opacity-30">↓</button>
                            <button type="button" @click="stops.splice(stopIndex, 1)" :disabled="stops.length === 1" :aria-label="(stopIndex + 1) + '. megálló törlése'" class="rounded border px-3 py-1 text-sm text-red-600 disabled:opacity-30">Törlés</button>
                        </div></div>
                        <div><label :for="'stop-warehouse-' + stop.key" class="block text-sm font-medium mb-1">Célraktár</label><select :id="'stop-warehouse-' + stop.key" :name="'stops[' + stopIndex + '][warehouse_id]'" x-model="stop.warehouse_id" required class="w-full rounded-md border-gray-300 text-sm"><option value="">Válassz célraktárt…</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->warehouse_id }}" :disabled="destinationUnavailable('{{ $warehouse->warehouse_id }}', stop)">{{ $warehouse->warehouse_name }}</option>@endforeach</select></div>
                        <template x-for="(item, itemIndex) in stop.items" :key="item.key">
                            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end border-t border-gray-100 pt-3">
                                <div class="sm:col-span-8"><label :for="'shipment-product-' + item.key" class="block text-xs font-medium text-gray-600 mb-1">Termék</label><select :id="'shipment-product-' + item.key" :name="'stops[' + stopIndex + '][items][' + itemIndex + '][product_id]'" x-model="item.product_id" required class="w-full rounded-md border-gray-300 text-sm"><option value="">Válassz terméket…</option>@foreach($products as $product)<option value="{{ $product->product_id }}">{{ $product->product_name }} ({{ $product->sku }})</option>@endforeach</select></div>
                                <div class="sm:col-span-3"><label :for="'shipment-quantity-' + item.key" class="block text-xs font-medium text-gray-600 mb-1">Mennyiség (db)</label><input :id="'shipment-quantity-' + item.key" :name="'stops[' + stopIndex + '][items][' + itemIndex + '][quantity]'" x-model="item.quantity" type="number" min="1" max="2147483647" step="1" required class="w-full rounded-md border-gray-300 text-sm"></div>
                                <button type="button" @click="stop.items.splice(itemIndex, 1)" :disabled="stop.items.length === 1" :aria-label="(itemIndex + 1) + '. termék törlése a(z) ' + (stopIndex + 1) + '. megállóból'" class="sm:col-span-1 rounded border px-2 py-2 text-sm text-red-600 disabled:opacity-30">×</button>
                            </div>
                        </template>
                        <button type="button" @click="addItem(stop)" :disabled="stop.items.length >= 50" class="text-sm font-medium text-indigo-600 disabled:opacity-30">+ Termék hozzáadása</button>
                    </fieldset>
                </template>
                <button type="button" @click="addStop()" :disabled="!canAddStop" class="rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 disabled:opacity-30">+ Megálló hozzáadása</button>
            </section>
            <section aria-label="Rakodási sorrend előnézete" class="rounded-lg border border-indigo-100 bg-indigo-50 p-5 space-y-3"><h3 class="font-semibold text-indigo-900">Automatikus felrakodási sorrend</h3><p class="text-sm text-indigo-800">A későbbi megállók áruja kerül először belülre; az első megálló áruja utoljára, az ajtóhoz közel.</p><ol class="space-y-2"><template x-for="(stop, index) in loadingOrder" :key="stop.key"><li class="rounded-md bg-white p-3 text-sm text-gray-700"><strong x-text="(index + 1) + '. felrakodás: '"></strong><span x-text="warehouseName(stop.warehouse_id)"></span><span class="text-gray-500" x-text="' · ' + stop.deliveryPosition + '. lerakodási megálló'"></span></li></template></ol></section>
            <div class="flex flex-wrap justify-end items-center gap-4"><a href="{{ $returnUrl }}" class="text-sm text-gray-600">Mégse</a><button :disabled="submitting" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" x-text="submitting ? 'Mentés…' : 'Tervezet mentése'">Tervezet mentése</button></div>
        </form>@endif
    </div>
</x-app-layout>
