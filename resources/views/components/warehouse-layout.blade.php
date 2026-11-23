@props(['warehouse' => null, 'context' => '', 'detailContext' => ''])
@inject('relocationPolicy', \App\Policies\WarehouseRelocationPolicy::class)

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
                <x-topbar-action :href="route('warehouses.locations.index', $params)" label="Tárhelyek és kapacitás" short-label="Tárhelyek" icon="layers" :current="request()->routeIs('warehouses.locations.*', 'warehouses.storage-systems.*')" />
                <x-topbar-action :href="route('warehouses.floor-plan.show', $params)" label="Raktári tervrajz" short-label="Tervrajz" icon="grid" :current="request()->routeIs('warehouses.floor-plan.*')" />
                @if($relocationPolicy->canAccessWarehouse(auth()->user(), (int) $warehouse->getKey()))
                    <x-topbar-action :href="route('warehouses.load-checks.index', $params)" label="Terhelésellenőrzések" short-label="Mérések" icon="document" :current="request()->routeIs('warehouses.load-checks.*')" />
                    <x-topbar-action :href="route('warehouses.relocations.index', $params)" label="Áthelyezési előzmények" short-label="Áthelyezés" icon="activity" :current="request()->routeIs('warehouses.relocations.*')" />
                @endif
                @if($canManage)
                    <x-topbar-action :href="route('warehouses.inventory-preview', $params)" label="PDF-leltárív" short-label="PDF" icon="document" :current="request()->routeIs('warehouses.inventory-preview')" />
                @endif
            </nav>
        </x-slot:pageActions>
    @elseif(isset($pageActions))
        <x-slot:pageActions>{{ $pageActions }}</x-slot:pageActions>
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
