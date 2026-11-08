<div class="space-y-3">
    @include('warehouses.locations.capacity-summary', ['data' => $overview])
    @foreach($overview['levels'] as $level)
        <details class="rounded border border-gray-200 bg-white">
            <summary class="cursor-pointer p-3 text-sm font-medium">{{ $level['name'] }} · {{ $level['bins']->count() }} tárhely · {{ $level['units'] }} db tárolt áru</summary>
            <div class="space-y-2 border-t border-gray-200 p-3">
                @include('warehouses.locations.capacity-summary', ['data' => $level])
                @foreach($level['bins'] as $cell)
                    <details class="rounded border border-gray-200">
                        <summary class="cursor-pointer p-3 text-sm">
                            <strong>{{ $cell['code'] }}</strong> · {{ $cell['name'] }} · {{ \App\Support\WarehouseStorageOverview::STATES[\App\Support\WarehouseStorageOverview::tone($cell)] }}
                        </summary>
                        <div class="space-y-2 border-t border-gray-200 p-3">
                            @include('warehouses.locations.capacity-summary', ['data' => $cell])
                            @include('warehouses.locations.stored-products', ['stored' => $products->get($cell['id'], collect())])
                            <a href="{{ route('warehouses.locations.show', $params + ['warehouseLocation' => $cell['id']]) }}" class="inline-flex min-h-10 items-center text-sm font-medium text-indigo-700 hover:underline">Tárhely adatlapja</a>
                        </div>
                    </details>
                @endforeach
            </div>
        </details>
    @endforeach
</div>
