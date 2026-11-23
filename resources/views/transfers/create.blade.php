<x-transfer-layout>
    @php
        $field = static function ($key, $default = '') use ($prefill) {
            $value = old($key, $prefill[$key] ?? $default);
            return is_scalar($value) ? (string) $value : $default;
        };
        $destination = $field('to_warehouse_id');
        $replenishment = $field('replenishment_stock_id');
        $palletSlots = $field('pallet_slots', $field('putaway_pallet_slots'));
        $previousWeight = $field('cargo_weight_kg');
        $weightMode = $field('putaway_weight_mode', $previousWeight === '' ? 'automatic' : 'manual');
        $palletMode = $field('putaway_pallet_mode');
        if (!in_array($palletMode, ['unknown', 'non-pallet', 'pallet'], true)) {
            $palletMode = match ($palletSlots) {
                '' => 'unknown',
                '0' => 'non-pallet',
                default => 'pallet',
            };
        }
        $warehouseItems = $warehouses->map(fn ($warehouse) => [
            'value' => (string) $warehouse->warehouse_id, 'name' => $warehouse->warehouse_name,
            'label' => $warehouse->warehouse_name.($warehouse->location ? ' · '.$warehouse->location : ''),
        ])->values();
        $oldSources = old('source_allocations', []);
        $previousSources = collect(is_array($oldSources) ? $oldSources : [])
            ->filter(fn ($row) => is_array($row) && array_key_exists('location_id', $row)
                && ($row['location_id'] === null || ctype_digit((string) (is_scalar($row['location_id']) ? $row['location_id'] : '')))
                && is_scalar($row['quantity'] ?? null))
            ->map(fn ($row) => ['location_id' => $row['location_id'] === null ? null : (int) $row['location_id'], 'quantity' => $row['quantity']])
            ->values();
        $sourceErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => $key === 'source_allocations' || str_starts_with($key, 'source_allocations.'));
        $transferForm = [
            'stock' => ['product' => $field('product_id'), 'warehouse' => $field('from_warehouse_id'), 'quantity' => $field('quantity', '1')],
            'putawayEndpoint' => route('transfers.putaway-recommendations'),
            'pickingEndpoint' => route('transfers.picking-sources'),
            'previousSources' => $previousSources,
            'destination' => $destination,
            'productNames' => $products->pluck('product_name', 'product_id'),
            'packagings' => $products->filter(fn ($product) => $product->defaultPackaging?->pieces_per_package > 0)
                ->mapWithKeys(fn ($product) => [$product->product_id => [
                    'name' => $product->defaultPackaging->name,
                    'pieces' => (int) $product->defaultPackaging->pieces_per_package,
                    'weightGrams' => $product->defaultPackaging->grossWeightGrams(),
                    'revision' => \App\Services\TransferCargoWeight::packagingRevision($product->defaultPackaging),
                ]]),
            'missingPackagingRevision' => \App\Services\TransferCargoWeight::packagingRevision(null),
            'boxCount' => $field('box_count'),
            'palletMode' => $palletMode,
            'weightMode' => $weightMode === 'manual' ? 'manual' : 'automatic',
            'manualWeight' => $field('putaway_weight_kg', $weightMode === 'manual' ? $previousWeight : ''),
            'previousEffectiveWeight' => $previousWeight,
            'previousWeightBasis' => $field('putaway_weight_basis'),
            'selectionMode' => $field('putaway_selection_mode') === 'recommended' ? 'recommended' : 'manual',
            'cargo' => ['to_location_id' => $field('to_location_id'),
                'pallet_slots' => $palletSlots === '0' ? '' : $palletSlots],
            'dimensions' => ['width' => $field('dimensions_cm.width'), 'depth' => $field('dimensions_cm.depth'),
                'height' => $field('dimensions_cm.height')],
            'storageLocations' => $storageLocations, 'sourceProducts' => $sourceProducts, 'warehouseItems' => $warehouseItems,
        ];
    @endphp

    <div class="app-page mx-auto max-w-[87.5rem] space-y-4 py-4">
        <x-page-toolbar title="Új átmozgatás" description="Készlet áthelyezése két raktár között.">
            <a href="{{ $returnUrl }}" class="text-sm font-medium text-indigo-700 hover:underline">← Átmozgatások</a>
            @if($products->isNotEmpty() && $warehouses->count() >= 2)
                <a href="#transfer-summary" class="text-sm font-medium text-indigo-700 hover:underline xl:hidden">Ugrás az összegzéshez</a>
            @endif
        </x-page-toolbar>
        <x-operation-feedback :except="['from_warehouse_id', 'to_warehouse_id', ...$sourceErrors->keys()->all()]" />
        @error('request_key')
            <a href="{{ route('transfers.create', ['context' => $context]) }}" class="text-sm text-indigo-700 underline">Új átmozgatási űrlap</a>
        @enderror
        @if($replenishment !== '')
            <p class="rounded-md border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900">
                Utánpótlási javaslatból előkészített átmozgatás. A mennyiség csökkenthető. Másik termékhez vagy célhoz
                <a href="{{ route('transfers.create') }}" class="font-semibold underline">nyiss normál átmozgatást</a>.
            </p>
        @endif
        @if($products->isEmpty() || $warehouses->count() < 2)
            <p class="rounded-md bg-amber-50 p-4 text-sm text-amber-900">Átmozgatáshoz legalább egy termék és két raktár szükséges.</p>
        @else
            <form action="{{ route('transfers.store') }}" method="POST" x-data="transferPutaway(@js($transferForm))"
                  @submit="submit($event)" @pageshow.window="restored($event)"
                  class="grid items-start gap-4 xl:grid-cols-[minmax(0,13fr)_minmax(0,7fr)] xl:gap-5">
                @csrf
                <input type="hidden" name="request_key" value="{{ $requestKey }}">
                <input type="hidden" name="context" value="{{ $context }}">
                <input type="hidden" name="to_location_id" :value="cargo.to_location_id">
                <input type="hidden" name="putaway_selection_mode" :value="selectionMode ?? ''">
                @if($replenishment !== '')
                    <input type="hidden" name="replenishment_stock_id" value="{{ $replenishment }}">
                @endif
                <div class="min-w-0 space-y-4">
                    <section aria-labelledby="transfer-basics-title" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5 [&_[role=combobox]]:min-h-11">
                        <h2 id="transfer-basics-title" class="font-semibold text-gray-900"><span class="mr-2 text-indigo-700">1.</span>Átmozgatás alapadatai</h2>
                        <p id="transfer-filter-help" class="text-xs text-gray-600"
                           x-text="filterHelp">
                            Kezdd a termékkel vagy a forrásraktárral. A másik lista ezután szűkül.
                        </p>
                        <div class="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                            <div class="min-w-0 sm:col-span-2" @product-selected="setProduct($event.detail.value)">
                                <label for="transfer-product" class="mb-1 block text-sm font-medium">Termék</label>
                                <x-product-picker :$products id="transfer-product" :value="$field('product_id')" :required="true" available-values="productOptions" described-by="transfer-filter-help" />
                            </div>
                            <div class="min-w-0" @source-selected="setSource($event.detail.value)" @input="sourceEdited = true">
                                <label for="transfer-source" class="mb-1 block text-sm font-medium">Forrásraktár</label>
                                <x-autocomplete-picker :items="$warehouseItems" id="transfer-source" name="from_warehouse_id" :value="$field('from_warehouse_id')" :required="true"
                                    event-name="source-selected" placeholder="Raktár neve vagy helye…" available-values="sourceOptions" error-active="!sourceEdited" described-by="transfer-filter-help" />
                            </div>
                            <div class="min-w-0" @destination-selected="setDestination($event.detail.value)" @input="destinationEdited = true">
                                <label for="transfer-destination" class="mb-1 block text-sm font-medium">Célraktár</label>
                                <x-autocomplete-picker :items="$warehouseItems" id="transfer-destination" name="to_warehouse_id" :value="$destination" :required="true"
                                    event-name="destination-selected" placeholder="Raktár neve vagy helye…" available-values="destinationOptions"
                                    model-value="destination" error-active="destinationErrorActive" />
                            </div>
                            @include('transfers.quantity')
                        </div>
                        @include('transfers.picking')
                    </section>
                    @include('transfers.cargo')
                    @include('transfers.putaway')
                </div>

                <aside id="transfer-summary" data-transfer-summary
                       class="min-w-0 xl:sticky xl:top-[calc(var(--app-topbar-height,4rem)+1rem)] xl:max-h-[calc(100dvh-var(--app-topbar-height,4rem)-2rem)] xl:overflow-y-auto">
                    @include('transfers.summary')
                </aside>
            </form>
        @endif
    </div>
</x-transfer-layout>
