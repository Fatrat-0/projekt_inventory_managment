<x-transfer-layout>
    <div class="max-w-5xl mx-auto px-4 sm:px-6 py-4 space-y-4">
        <a href="{{ route('transfer-intent-pages.index') }}" class="text-sm text-gray-500 hover:text-indigo-600">← Átmozgatások</a><x-operation-feedback />
        <div class="flex flex-wrap items-center gap-3"><div><p class="text-xs font-mono text-indigo-600">{{ $shipment->shipment_number }}</p><h1 class="text-xl font-bold text-gray-900">{{ $shipment->reference }}</h1></div><span class="rounded-md bg-indigo-50 px-2.5 py-1 text-sm text-indigo-800">{{ \App\Models\Shipment::STATUSES[$shipment->status] }}</span>@include('shipments.pdf-preview')</div>
        <dl class="grid sm:grid-cols-3 gap-4 rounded-lg border border-gray-200 bg-white p-5 text-sm"><div><dt class="text-gray-500">Induló raktár</dt><dd class="mt-1 font-medium">{{ $shipment->origin->warehouse_name }}</dd></div><div><dt class="text-gray-500">Jármű</dt><dd class="mt-1">{{ $shipment->vehicle_label ?? 'Nincs megadva' }}</dd></div><div><dt class="text-gray-500">Tervezte</dt><dd class="mt-1">{{ $shipment->user->name }}</dd></div>@if($shipment->note)<div class="sm:col-span-3"><dt class="text-gray-500">Megjegyzés</dt><dd class="mt-1 whitespace-pre-wrap break-words">{{ $shipment->note }}</dd></div>@endif</dl>
        <p class="text-sm text-gray-600">Korábbi szállítmány · megtekintés</p>
        <section aria-label="Rakodási terv" class="rounded-lg border border-gray-200 bg-white p-5 space-y-4">
            <div><h3 class="font-semibold text-gray-900">Rakodási terv</h3><p class="mt-1 text-sm text-gray-500">Hátsó ajtós kirakodáshoz, megállónként csoportosítva. Az első megálló áruja marad legközelebb az ajtóhoz.</p></div>
            <div class="text-center text-xs uppercase tracking-wide text-gray-500">Kamion eleje · belső rész</div>
            <ol class="border-x-4 border-gray-300 px-3 space-y-3" aria-label="Felrakodási sorrend">@foreach(\App\Models\Shipment::loadingStops($shipment->stops) as $index => $stop)<li class="rounded-lg border p-4 {{ $stop->received_at ? 'border-green-200 bg-green-50' : 'border-indigo-100 bg-indigo-50' }}"><div class="flex flex-wrap justify-between gap-2 text-sm"><strong>{{ $index + 1 }}. felrakodás · {{ $stop->warehouse->warehouse_name }}</strong><span>{{ $stop->position }}. lerakodási megálló{{ $stop->received_at ? ' · Átvéve' : '' }}</span></div><ul class="mt-2 space-y-1 text-sm text-gray-600">@foreach($stop->items as $item)<li>{{ $item->product->product_name }} <span class="font-mono text-xs">({{ $item->product->sku }})</span> · {{ number_format($item->quantity, 0, ',', ' ') }} db
                        @if($item->destinationLocation)
                            · <a class="text-indigo-700 hover:underline" href="{{ route('warehouses.locations.show', ['warehouse' => $stop->warehouse_id, 'warehouseLocation' => $item->to_location_id]) }}">{{ $item->destinationLocation->code }}</a>
                            · {{ \App\Models\WarehouseLocation::formatWeight($item->cargo_weight_grams) }} kg
                            @if($item->pallet_slots) · {{ $item->pallet_slots }} raklap @endif
                        @endif
                    </li>@endforeach</ul></li>@endforeach</ol>
            <div class="rounded-md border-2 border-dashed border-gray-300 py-2 text-center text-xs font-semibold uppercase tracking-wide text-gray-600">Hátsó ajtó · innen indul a kirakodás</div>
        </section>
        <section aria-label="Útvonal és átvételi előzmények" class="space-y-3"><h3 class="font-semibold text-gray-900">Útvonal és átvételi előzmények</h3>
            @foreach($shipment->stops as $stop)<div data-legacy-stop-id="{{ $stop->id }}" class="rounded-lg border border-gray-200 bg-white p-4 flex flex-wrap items-center gap-3"><div class="flex-1 min-w-0"><p class="font-medium text-gray-900">{{ $stop->position }}. {{ $stop->warehouse->warehouse_name }}</p><p class="text-xs text-gray-500">{{ $stop->warehouse->location }}</p>@if($stop->received_at)<p class="mt-1 text-xs text-green-700">Átvéve: {{ $stop->received_at->format('Y.m.d H:i') }} · {{ $stop->receiver->name }}</p>@endif</div>
            </div>@endforeach
        </section>
    </div>
</x-transfer-layout>
