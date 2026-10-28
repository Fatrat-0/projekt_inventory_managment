<x-app-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-5">
        @include('transfers.tabs')
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-bold text-gray-900">Szállítmányok</h2><p class="mt-1 text-sm text-gray-500">Több megálló, közös rakomány, fordított felrakodási sorrend.</p></div><a href="{{ route('shipments.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">+ Új rakodási terv</a></div>
        <x-operation-feedback />
        <form method="GET" class="flex flex-wrap gap-3 rounded-lg border border-gray-200 bg-white p-4">
            <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="200" aria-label="Szállítmányok keresése" placeholder="Hivatkozás, jármű vagy induló raktár…" class="min-w-0 flex-1 rounded-md border-gray-300 text-sm">
            <select name="status" aria-label="Szállítmány állapota" class="rounded-md border-gray-300 text-sm"><option value="">Minden állapot</option>@foreach(\App\Models\Shipment::STATUSES as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select>
            <button class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Keresés</button>@if(($filters['search'] ?? '') || ($filters['status'] ?? ''))<a href="{{ route('shipments.index') }}" class="self-center text-sm text-gray-500 underline">Visszaállítás</a>@endif
        </form>
        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">@forelse($shipments as $shipment)
            <a href="{{ route('shipments.show', $shipment) }}" class="rounded-lg border border-gray-200 bg-white p-5 space-y-3 hover:border-indigo-300 hover:shadow-sm">
                <div class="flex justify-between items-start gap-2"><span class="font-mono text-xs text-indigo-600">{{ $shipment->shipment_number }}</span><span class="rounded-md bg-gray-100 px-2 py-1 text-xs">{{ \App\Models\Shipment::STATUSES[$shipment->status] }}</span></div>
                <h3 class="font-semibold text-gray-900 break-words">{{ $shipment->reference }}</h3><p class="text-sm text-gray-500">{{ $shipment->origin->warehouse_name }}</p>
                <p class="text-sm text-gray-600">{{ $shipment->stops_count }} megálló · {{ $shipment->vehicle_label ?? 'Jármű nincs megadva' }}</p>
                <p class="text-xs text-gray-500">{{ $shipment->stops->pluck('warehouse.warehouse_name')->join(' → ') }}</p>
            </a>
        @empty<p role="status" class="md:col-span-2 xl:col-span-3 rounded-lg border bg-white p-10 text-center text-sm text-gray-500">Nincs a feltételeknek megfelelő szállítmány.</p>@endforelse</div>
        {{ $shipments->links('pagination.inventory') }}
    </div>
</x-app-layout>
