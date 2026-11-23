<x-transfer-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-4 space-y-4">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Átmozgatások</a>
        <x-operation-feedback />
        <div class="flex flex-wrap items-center gap-3"><h1 class="text-xl font-bold text-gray-900">{{ $transfer->transfer_number }}</h1><span class="rounded-md bg-gray-100 px-2.5 py-1 text-sm">{{ \App\Models\InventoryTransfer::STATUSES[$transfer->status] }}</span></div>
        <div class="rounded-lg border border-gray-200 bg-white p-5 space-y-5">
            <div><p class="font-semibold text-gray-900">{{ $transfer->product->product_name }}</p><p class="font-mono text-xs text-gray-500">{{ $transfer->product->sku }}</p></div>
            <dl class="grid sm:grid-cols-2 gap-5 text-sm">
                <div><dt class="text-gray-500">Forrásraktár</dt><dd class="mt-1 font-medium">{{ $transfer->fromWarehouse->warehouse_name }}</dd></div>
                <div><dt class="text-gray-500">Célraktár</dt><dd class="mt-1 font-medium">{{ $transfer->toWarehouse->warehouse_name }}</dd></div>
                <div><dt class="text-gray-500">Mennyiség</dt><dd class="mt-1 font-semibold">{{ number_format($transfer->quantity, 0, ',', ' ') }} db</dd></div>
                <div><dt class="text-gray-500">Elindította</dt><dd class="mt-1">{{ $transfer->user->name }} · {{ $transfer->created_at->format('Y.m.d H:i') }}</dd></div>
                @if($transfer->destinationLocation)
                    <div><dt class="text-gray-500">Cél tárhely</dt><dd class="mt-1 font-medium"><a class="text-indigo-700 hover:underline" href="{{ route('warehouses.locations.show', ['warehouse' => $transfer->to_warehouse_id, 'warehouseLocation' => $transfer->to_location_id]) }}">{{ $transfer->destinationLocation->code }} · {{ $transfer->destinationLocation->name }}</a></dd></div>
                    <div><dt class="text-gray-500">Terhelés</dt><dd class="mt-1 font-medium">{{ \App\Models\WarehouseLocation::formatWeight($transfer->cargo_weight_grams) }} kg @if($transfer->pallet_slots) · {{ $transfer->pallet_slots }} raklap @endif</dd></div>
                @endif
            </dl>
            @if($transfer->sourceAllocations->isNotEmpty())
                <section class="border-t border-gray-200 pt-4" aria-labelledby="transfer-picking-history">
                    <h2 id="transfer-picking-history" class="text-sm font-semibold text-gray-900">Kitárolási forráshelyek</h2>
                    <ul class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach($transfer->sourceAllocations->groupBy('location_code') as $allocations)
                            @php
                                $source = $allocations->first();
                                $needsPlacement = $allocations->whereStrict('returned_to_location', false)->sum('quantity');
                            @endphp
                            <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-2">
                                <div class="min-w-0">
                                    @if($source->warehouse_location_id)
                                        <a class="font-medium text-indigo-700 hover:underline" href="{{ route('warehouses.locations.show', ['warehouse' => $transfer->from_warehouse_id, 'warehouseLocation' => $source->warehouse_location_id]) }}">{{ $source->location_code }} · {{ $source->location_name }}</a>
                                    @else
                                        <span class="font-medium text-gray-900">{{ $source->location_code !== null ? $source->location_code.' · '.$source->location_name : 'Tárhely nélküli készlet' }}</span>
                                    @endif
                                    @if($needsPlacement > 0)
                                        <p class="text-xs text-amber-800">
                                            {{ number_format($needsPlacement, 0, ',', ' ') }} db tárhely nélkül került vissza.
                                            <a class="font-medium underline" href="{{ route('warehouses.locations.index', $transfer->from_warehouse_id) }}">Tárhely keresése</a>
                                        </p>
                                    @endif
                                </div>
                                <span class="font-medium text-gray-900">{{ number_format($allocations->sum('quantity'), 0, ',', ' ') }} db</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            <p class="text-xs text-gray-500">Az úton lévő mennyiség már nem elérhető a forrásraktárban. A célraktár készlete az átvételkor növekszik.</p>
            @if($transfer->to_location_id && $transfer->status === 'pending')
                <p class="text-sm text-indigo-700">A tétel helye az átvételig le van foglalva a cél tárhelyen.</p>
            @endif
            <x-transfer-actions :transfer="$transfer" :context="$context" />
            <a href="{{ route('export.transfer', $transfer) }}" class="inline-block text-sm text-indigo-600 underline">Szállítólevél letöltése (PDF)</a>
        </div>
    </div>
</x-transfer-layout>
