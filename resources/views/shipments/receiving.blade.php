<x-app-layout>
    <div class="app-page min-w-0 space-y-4 py-4 [overflow-wrap:anywhere]">
        <x-page-toolbar title="Fuvaradatlap" :description="$shipment->shipment_number">
            <a href="{{ route('warehouse-receiving-pages.index') }}" class="inline-flex min-h-10 items-center font-medium text-indigo-700 hover:underline">Vissza az átvételi feladatokhoz</a>
        </x-page-toolbar>
        @include('transfers.tabs')

        <header class="min-w-0 rounded-lg border border-gray-200 bg-white p-4">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="min-w-0 font-semibold text-gray-900">{{ $shipment->reference ?: $shipment->shipment_number }}</h2>
                <span class="rounded-md bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-900">{{ \App\Models\Shipment::STATUSES[$shipment->status] }}</span>
            </div>
            <dl class="mt-3 grid min-w-0 gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-gray-600">Forrásraktár</dt><dd class="font-medium">{{ $shipment->origin->warehouse_name }}</dd></div>
                <div><dt class="text-xs text-gray-600">Tervezett indulás</dt><dd class="font-medium">{{ $shipment->planned_departure_at?->timezone('Europe/Budapest')->format('Y. m. d. H:i') ?? 'Nincs megadva' }}</dd></div>
                <div><dt class="text-xs text-gray-600">Tényleges feladás</dt><dd class="font-medium">{{ \Illuminate\Support\Carbon::parse($receivingStops[0]['dispatch']['dispatched_at'])->timezone('Europe/Budapest')->format('Y. m. d. H:i') }}</dd></div>
            </dl>
        </header>

        @include('shipments.plan-receiving')
    </div>
</x-app-layout>

