<div data-listing-table class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
    <table class="w-full text-left text-sm text-gray-600">
        <caption class="sr-only">Szállítmányok, útvonalak és átvételi állapotok</caption>
        <thead class="border-b border-gray-200 bg-gray-50 text-xs"><tr>
            @foreach(\App\Queries\ShipmentListing::SORTS as $key => $label)
                @php($direction = $filters['sort_by'] === $key && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc')
                <th data-listing-column="{{ $key }}" x-show="columnVisible('{{ $key }}')" scope="col" class="whitespace-nowrap px-4 py-3" aria-sort="{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}"><div class="flex items-center justify-between gap-2"><a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => $key, 'sort_dir' => $direction]) }}" @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey) { $event.preventDefault(); const form = document.getElementById('shipment-filters'); form.elements.namedItem('sort_by').value = '{{ $key }}'; form.elements.namedItem('sort_dir').value = '{{ $direction }}'; form.requestSubmit(); }" class="hover:text-indigo-600">{{ $label }} {{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? '↑' : '↓') : '↕' }}</a>@if($key === 'reference')<x-listing-columns />@endif</div></th>
            @endforeach
            <th data-listing-column="route" x-show="columnVisible('route')" scope="col" class="px-4 py-3">Útvonal</th><th data-listing-column="vehicle" x-show="columnVisible('vehicle')" scope="col" class="px-4 py-3">Jármű</th><th data-listing-column="progress" x-show="columnVisible('progress')" scope="col" class="px-4 py-3">Átvett megállók</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($shipments as $shipment)
                <tr data-shipment-id="{{ $shipment->id }}" class="align-top hover:bg-gray-50">
                    <td data-listing-column="created_at" x-show="columnVisible('created_at')" class="whitespace-nowrap px-4 py-3">{{ $shipment->created_at->format('Y.m.d H:i') }}</td>
                    <td data-listing-column="reference" x-show="columnVisible('reference')" class="min-w-[180px] px-4 py-3"><a href="{{ route('shipments.show', ['shipment' => $shipment, 'context' => $context]) }}" class="font-semibold text-gray-900 hover:text-indigo-600 hover:underline">{{ $shipment->reference }}</a><p class="mt-1 font-mono text-xs text-indigo-600">{{ $shipment->shipment_number }}</p></td>
                    <td data-listing-column="status" x-show="columnVisible('status')" class="px-4 py-3"><span class="inline-block rounded-md px-2 py-1 text-xs {{ match($shipment->status) { 'in_transit' => 'bg-amber-50 text-amber-800', 'completed' => 'bg-green-50 text-green-800', 'draft' => 'bg-indigo-50 text-indigo-800', default => 'bg-gray-100 text-gray-600' } }}">{{ \App\Models\Shipment::STATUSES[$shipment->status] }}</span></td>
                    <td data-listing-column="route" x-show="columnVisible('route')" class="min-w-[210px] max-w-sm break-words px-4 py-3"><p class="font-medium">{{ $shipment->origin->warehouse_name }}</p><p class="mt-1 text-xs text-gray-500">{{ $shipment->stops->pluck('warehouse.warehouse_name')->join(' → ') }}</p>@if($shipment->status === 'in_transit' && ($next = $shipment->stops->firstWhere('received_at', null)))<p class="mt-1 text-xs font-medium text-amber-800">Következő: {{ $next->warehouse->warehouse_name }}</p>@endif</td>
                    <td data-listing-column="vehicle" x-show="columnVisible('vehicle')" class="px-4 py-3">{{ $shipment->vehicle_label ?? 'Jármű nincs megadva' }}</td>
                    <td data-listing-column="progress" x-show="columnVisible('progress')" class="px-4 py-3 tabular-nums">{{ $shipment->received_stops_count }} / {{ $shipment->stops_count }}</td>
                </tr>
            @empty
                <tr><td colspan="6" :colspan="visibleColumnCount()" role="status" class="p-8 text-center text-gray-500">Nincs a feltételeknek megfelelő szállítmány.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
