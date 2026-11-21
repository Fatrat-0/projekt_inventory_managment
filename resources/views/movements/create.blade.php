<x-movement-layout>
    @php($formData = [
        'endpoint' => route('movements.stock'), 'sourcesUrl' => route('movements.out.sources'), 'validateUrl' => route('movements.out.validate'),
        'issueOptionsUrl' => route('movements.out.options'),
        'detailUrl' => route('movements.show', ['movement' => '__movement__', 'context' => $context]), 'requestKey' => $requestKey,
        'context' => $context, 'productSkus' => $products->pluck('sku', 'product_id'),
        'product' => (string) old('product_id', $prefill['product_id'] ?? ''), 'warehouse' => (string) old('warehouse_id', $prefill['warehouse_id'] ?? ''),
        'type' => old('type', 'in'), 'quantity' => old('quantity', 1), 'partner' => (string) old('partner_id', ''), 'reference' => (string) old('reference', ''),
        'productNames' => $products->pluck('product_name', 'product_id'), 'warehouseNames' => $warehouses->pluck('warehouse_name', 'warehouse_id'),
        'partnerNames' => $partners->pluck('name', 'value'),
        'packagings' => $products->filter(fn ($product) => $product->defaultPackaging?->pieces_per_package > 0)
            ->mapWithKeys(fn ($product) => [$product->getKey() => ['pieces' => $product->defaultPackaging->pieces_per_package]]),
    ])
    <div class="mx-auto max-w-[85rem] px-4 py-4 sm:px-6"
        x-data="manualMovementForm(@js($formData))" @pageshow.window="restored($event)">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Készletmozgások</a>
        <h1 class="mt-3 mb-5 text-xl font-bold text-gray-900">Új készletmozgás</h1>
        @if($errors->any())<div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @error('request_key')
            <a href="{{ route('movements.create', ['context' => $context]) }}" class="mb-4 inline-block text-sm font-medium text-indigo-600 underline">Új készletmozgás indítása</a>
        @enderror
        @if($products->isEmpty() || $warehouses->isEmpty())
            <div role="status" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Mozgás rögzítéséhez legalább egy termék és egy raktár szükséges.</div>
        @else
            <form x-ref="form" x-show="!receiptResult" method="POST" action="{{ route('movements.store') }}" @submit="submit($event)" class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                @csrf
                <input type="hidden" name="request_key" value="{{ $requestKey }}">
                <input type="hidden" name="context" value="{{ $context }}">
                <fieldset :disabled="savingIssue || pending || Boolean(receiptUrl) || submitting || receiptPending" class="min-w-0 space-y-4">
                <div class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
                <h2 x-text="type === 'out' ? 'Kiadás adatai' : 'Bevételezés adatai'" class="font-semibold text-gray-900">Bevételezés adatai</h2>
                <div class="max-w-full" :class="type === 'out' ? 'sm:w-fit' : ''"><label for="movement-type" class="block text-sm font-medium text-gray-700 mb-1">Mozgás típusa</label><select id="movement-type" name="type" x-model="type" class="w-full rounded-md {{ $errors->has('type') ? 'border-red-500' : 'border-gray-300' }}" required aria-invalid="{{ $errors->has('type') ? 'true' : 'false' }}" @if($errors->has('type')) aria-describedby="movement-type-error" @endif><option value="in" @selected(old('type','in') === 'in')>Bevételezés – készlet növelése</option><option value="out" @selected(old('type') === 'out')>Kiadás – készlet csökkentése</option></select>@error('type')<p id="movement-type-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                    <div @product-selected="productChanged($event.detail.value)" class="min-w-0">
                        <label for="movement-product" class="block text-sm font-medium text-gray-700 mb-1">Termék</label>
                        <x-product-picker :products="$products" id="movement-product" :value="old('product_id', $prefill['product_id'] ?? '')" :required="true"
                            model-value="product" available-values="issueProductIds" details-source="issueProductDetails" empty-message="issuePickerEmptyText" />
                    </div>
                    <div @warehouse-selected="warehouseChanged($event.detail.value)" class="min-w-0">
                        <label for="movement-warehouse" class="block text-sm font-medium text-gray-700 mb-1">Raktár</label>
                        @php($warehouseItems = $warehouses->map(fn($warehouse) => ['value' => (string)$warehouse->warehouse_id, 'label' => $warehouse->warehouse_name.($warehouse->location ? ' · '.$warehouse->location : '')])->values())
                        <x-autocomplete-picker :items="$warehouseItems" id="movement-warehouse" name="warehouse_id" :value="old('warehouse_id', $prefill['warehouse_id'] ?? '')"
                            :required="true" placeholder="Raktár neve vagy helye…" event-name="warehouse-selected" model-value="warehouse" available-values="issueWarehouseIds"
                            details-source="issueWarehouseDetails" empty-message="issuePickerEmptyText" />
                    </div>
                    <div x-cloak x-show="type === 'out' && (issueOptionNotice || issueOptionFailure)" class="text-xs text-amber-900 sm:col-span-2">
                        <p role="status" x-text="issueOptionNotice || issueOptionFailure"></p>
                        <button type="button" x-show="issueOptionFailure" @click="refreshIssueOptions()" class="min-h-8 text-indigo-700 underline">Választók újratöltése</button>
                    </div>
                    @include('movements.issue-quantity')
                <div @item-selected="partner = $event.detail.value; delete fieldErrors.partner_id" class="min-w-0">
                    <label for="movement-partner" class="block text-sm font-medium text-gray-700 mb-1">Partner</label>
                    <x-autocomplete-picker :items="$partners" id="movement-partner" name="partner_id" :value="old('partner_id', '')" :movement-partner="true" model-value="partner"
                        placeholder="Partner neve, kapcsolattartója vagy e-mailje…" clear-label="Partner törlése a mezőből" results-label="Partnertalálatok"
                        invalid-value-label="A korábban választott partner már nem elérhető. Válassz másikat vagy töröld a mezőt." described-by="movement-partner-help" />
                    <p id="movement-partner-help" class="mt-1 text-xs text-gray-500" x-text="type === 'in' ? 'Opcionális: aktív beszállító választható. A kettős szerepű partnerek is megjelennek.' : 'Opcionális. Aktív vevők választhatók.'">Opcionális.</p>
                    <p id="receipt-partner-error" x-cloak x-show="type === 'in' && fieldErrors.partner_id" role="alert" class="mt-1 text-xs text-red-700" x-text="fieldErrors.partner_id?.[0]"></p>
                </div>
                </div>
                <div x-cloak x-show="type === 'out'">@include('movements.availability', ['compactIssue' => true])</div>
                <div><label for="movement-reference" class="block text-sm font-medium text-gray-700 mb-1">Bizonylatszám vagy megjegyzés <span class="font-normal text-gray-400">(opcionális)</span></label><input id="movement-reference" name="reference" maxlength="255" x-model="reference" value="{{ old('reference') }}" placeholder="Például: BEV-2026/001" class="w-full rounded-md {{ $errors->has('reference') ? 'border-red-500' : 'border-gray-300' }}" aria-invalid="{{ $errors->has('reference') ? 'true' : 'false' }}" @if($errors->has('reference')) aria-describedby="movement-reference-error" @endif>@error('reference')<p id="movement-reference-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                <noscript><button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Mozgás rögzítése</button></noscript>
                </div>
                @include('movements.issue-sources')
                </fieldset>
                @include('movements.issue-review')
                @include('movements.receipt-review')
            </form>
            <section x-cloak x-show="receiptResult" x-ref="receiptSuccess" tabindex="-1" role="status"
                class="max-w-2xl space-y-3 rounded-lg border border-emerald-200 bg-white p-5 [overflow-wrap:anywhere]">
                <h2 class="text-lg font-semibold text-emerald-900" x-text="receiptResult && formatStock(receiptResult.movement.quantity) + ' sikeresen bevételezve.'"></h2>
                <p x-show="receiptResult?.batch" x-text="receiptResult?.batch && 'Betárolásra vár: ' + formatStock(receiptResult.batch.pending_putaway_quantity) + '.'" class="text-gray-700"></p>
                <p x-show="receiptResult?.batch?.pending_putaway_quantity && !receiptResult?.putaway_url" class="text-sm text-gray-700">A betárolást a célraktár átvételi jogosultságával rendelkező munkatárs végezheti.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <a x-show="receiptResult?.putaway_url" :href="receiptResult?.putaway_url" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Betárolás megkezdése</a>
                    <a :href="receiptResult?.movement_url" x-text="receiptResult?.batch?.can_putaway ? 'Később tárolom be' : 'Készletmozgás megnyitása'" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-800"></a>
                </div>
            </section>
        @endif
    </div>
</x-movement-layout>
