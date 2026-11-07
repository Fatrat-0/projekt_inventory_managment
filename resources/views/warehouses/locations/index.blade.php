<x-warehouse-layout :$warehouse :context="$params['context'] ?? ''" :detail-context="$params['detail_context'] ?? ''">
    <div class="app-page space-y-4 py-4">
        <x-page-toolbar title="Tárhelyek és kapacitás" :description="$warehouse->warehouse_name" :count="$locations->total()" unit="tárhely" />
        <x-operation-feedback />
        @if($unassignedIncoming && $locations->total())
            <p class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                {{ $unassignedIncoming }} korábban indított átmozgatás érkezik tárhelyfoglalás nélkül. Átvétel után rendeld őket tárhelyhez.
            </p>
        @endif
        @if($canManage)
            <details @if(!$locations->total() || $errors->any()) open @endif class="rounded-lg border border-gray-200 bg-white p-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-900">Új tárhely</summary>
                <div class="mt-4">@include('warehouses.locations.form', ['location' => null, 'action' => route('warehouses.locations.store', $params)])</div>
            </details>
        @endif
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse($locations as $location)
                <article class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0"><h2 class="dashboard-text font-semibold text-gray-900"><a href="{{ route('warehouses.locations.show', $params + ['warehouseLocation' => $location]) }}" class="hover:text-indigo-700 hover:underline">{{ $location->name }}</a></h2><p class="dashboard-text text-xs text-gray-500">{{ $location->code }} · {{ \App\Models\WarehouseLocation::KINDS[$location->kind] }}</p></div>
                        @if(!$location->active)<span class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600">Kikapcsolt</span>@endif
                    </div>
                    @include('warehouses.locations.capacity')
                </article>
            @empty
                <p class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600 sm:col-span-2 xl:col-span-3">Ebben a raktárban még nincs beállított tárhely.</p>
            @endforelse
        </div>
        {{ $locations->links('pagination.inventory') }}
    </div>
</x-warehouse-layout>
