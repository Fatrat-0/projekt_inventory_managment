@props(['warehouse' => null, 'context' => '', 'detailContext' => ''])

<x-app-layout>
    @if($warehouse)
        <x-slot:pageActions>
            @php
                $params = array_filter(['warehouse' => $warehouse, 'context' => $context, 'detail_context' => $detailContext]);
                $canManage = in_array(auth()->user()->role, ['admin', 'manager'], true);
                $overview = $canManage
                    ? \App\Support\WarehouseStockContext::url($warehouse, $detailContext, $context, jumpToStock: false)
                    : route('stock.index', ['warehouse_id' => $warehouse->warehouse_id]);
            @endphp
            <nav aria-label="Raktári nézetek" class="flex flex-wrap items-center gap-1">
                <x-topbar-action :href="$overview" :label="$canManage ? 'Raktári áttekintés' : 'Raktári készlet'" :short-label="$canManage ? 'Áttekintés' : 'Készlet'" icon="warehouse" :current="request()->routeIs('warehouses.show')" />
                <x-topbar-action :href="route('warehouses.locations.index', $params)" label="Tárhelyek és kapacitás" short-label="Tárhelyek" icon="layers" :current="request()->routeIs('warehouses.locations.*')" />
                @if($canManage)
                    <x-topbar-action :href="route('warehouses.inventory-preview', $params)" label="PDF-leltárív" short-label="PDF" icon="document" :current="request()->routeIs('warehouses.inventory-preview')" />
                @endif
            </nav>
        </x-slot:pageActions>
    @elseif(request()->routeIs('warehouses.index', 'warehouses.statistics'))
        <x-slot:pageActions>
            <nav aria-label="Raktármodul nézetei" class="flex flex-wrap items-center gap-1">
                <x-topbar-action :href="\App\Support\WarehouseListContext::url($context)" label="Raktárlista" short-label="Raktárlista" icon="list" :current="request()->routeIs('warehouses.index')" />
                <x-topbar-action :href="route('warehouses.statistics', $context !== '' ? ['context' => $context] : [])" label="Statisztika" short-label="Statisztika" icon="chart" :current="request()->routeIs('warehouses.statistics')" />
            </nav>
        </x-slot:pageActions>
    @endif
    {{ $slot }}
</x-app-layout>
