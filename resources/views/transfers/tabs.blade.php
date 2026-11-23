<nav aria-label="Átmozgatások modul" class="flex flex-wrap items-center gap-1">
    <x-topbar-action :href="route('transfer-intent-pages.index')" label="Igények" short-label="Igények"
        icon="list" :current="request()->routeIs('transfer-intent-pages.*', 'transfers.*')" />
    @can('viewAnyPlan', \App\Models\Shipment::class)
        <x-topbar-action :href="route('shipments.index')" label="Fuvarok és szállítmányok" short-label="Fuvarok"
            icon="document" :current="request()->routeIs('shipments.*')" />
        <x-topbar-action :href="route('vehicle-pages.index')" label="Járművek" short-label="Járművek"
            icon="truck" :current="request()->routeIs('vehicle-pages.*')" />
    @endcan
</nav>
