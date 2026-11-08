<x-warehouse-layout :$warehouse :context="$params['context'] ?? ''" :detail-context="$params['detail_context'] ?? ''">
    @php
        $kg = \App\Models\WarehouseLocation::formatWeight(...);
        $tones = ['available' => 'bg-emerald-100 text-emerald-900', 'near' => 'bg-amber-100 text-amber-900',
            'full' => 'bg-rose-100 text-rose-900', 'blocked' => 'bg-slate-100 text-slate-800'];
    @endphp
    <div class="app-page space-y-4 py-4">
        <x-page-toolbar title="Tárhelyek és kapacitás" :description="$warehouse->warehouse_name" :count="$systems->total()" unit="tárolórendszer">
            @if($canManage)
                <a href="{{ route('warehouses.floor-plan.show', $params + ['create' => 1]) }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">Új tárolórendszer a tervrajzon</a>
            @endif
        </x-page-toolbar>
        <x-operation-feedback />
        @if($unassignedIncoming && ($locations->total() || $systems->total()))
            <p class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                {{ $unassignedIncoming }} korábban indított átmozgatás érkezik tárhelyfoglalás nélkül. Átvétel után rendeld őket tárhelyhez.
            </p>
        @endif
        <div class="space-y-3">
            @foreach($systems->getCollection()->groupBy('zone_id') as $zoneSystems)
                <details open class="rounded-lg border border-gray-200 bg-white">
                    <summary class="cursor-pointer px-4 py-3 font-semibold">Zóna · {{ $zoneSystems->first()->zone->name }}</summary>
                    <div class="space-y-2 border-t border-gray-200 bg-gray-50 p-3">
                        @foreach($zoneSystems as $system)
                            @php
                                $systemParams = $params + ['system' => $system->id];
                                $capacity = $summaries[$system->id];
                            @endphp
                            <article data-storage-summary class="flex min-w-0 flex-wrap items-start rounded-md border border-gray-200 bg-white">
                                <details x-data="warehouseCapacityTree" @toggle.self="if ($el.open && !failed) load()" data-capacity-url="{{ route('warehouses.storage-systems.capacity', $systemParams) }}" class="min-w-0 flex-1 basis-full sm:basis-0">
                                    <summary class="cursor-pointer break-words p-3 text-sm">
                                        <strong>{{ $system->displayCode() }} · {{ $system->name }}</strong>
                                        <span class="ml-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $tones[$capacity['tone']] }}">{{ \App\Support\WarehouseStorageOverview::STATES[$capacity['tone']] }}</span>
                                        <span class="mt-1 block text-xs text-gray-600">{{ $capacity['kindLabel'] }} · @if($system->levels->isNotEmpty()){{ $system->levels->count() }} szint · @endif{{ $system->locations_count }} tárhely</span>
                                        <span class="mt-1 block text-xs text-gray-800">
                                            Kihasználtság foglalásokkal · {{ round($capacity['utilization']['weight'] * 100) }}% teherbírás
                                            @if($capacity['maxSlots'] !== null) · {{ round($capacity['utilization']['slots'] * 100) }}% raklaphely @endif
                                        </span>
                                        <span class="block text-xs font-medium text-gray-800">{{ $kg($capacity['free']) }} kg szabad teherbírás
                                            @if($capacity['maxSlots'] !== null) · {{ $capacity['freeSlots'] }} szabad raklaphely @endif
                                        </span>
                                        <span class="mt-1 inline-block text-xs font-medium text-indigo-700">Részletek</span>
                                    </summary>
                                    <div class="space-y-2 border-t border-gray-200 p-3">
                                        <p x-cloak x-show="loading" role="status" class="text-sm text-gray-600">Kapacitás betöltése…</p>
                                        <div x-cloak x-show="failed" class="text-sm text-rose-700">
                                            <p role="alert">A kapacitás betöltése sikertelen.</p>
                                            <button type="button" @click="load()" class="min-h-10 font-medium underline">Újrapróbálás</button>
                                        </div>
                                        <div x-ref="capacity"></div>
                                    </div>
                                </details>
                                <div class="flex flex-wrap gap-1 px-3 pb-2 sm:py-2">
                                    <a href="{{ route('warehouses.storage-systems.show', $systemParams) }}" class="inline-flex min-h-10 items-center rounded-md bg-indigo-50 px-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">Megnyitás</a>
                                    <a href="{{ route('warehouses.floor-plan.show', $systemParams) }}" class="inline-flex min-h-10 items-center rounded-md px-2 text-xs font-medium text-indigo-700 hover:bg-indigo-50">Megjelenítés a tervrajzon</a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </details>
            @endforeach
            {{ $systems->links('pagination.inventory') }}

            @if($locations->total())
                <details @if(!$systems->total() || request()->has('location_page')) open @endif class="rounded-lg border border-gray-200 bg-white">
                    <summary class="cursor-pointer px-4 py-3 font-semibold">Tárolórendszerhez nem rendelt tárhelyek · {{ $locations->total() }}</summary>
                    <div class="space-y-2 border-t border-gray-200 p-3">
                        @foreach($locations as $location)
                            <details class="rounded border border-gray-200">
                                <summary class="cursor-pointer break-words p-3 text-sm"><strong>{{ $location->code }} · {{ $location->name }}</strong>@if(!$location->active) · Kikapcsolt @endif</summary>
                                <div class="space-y-2 border-t border-gray-200 p-3">
                                    @include('warehouses.locations.capacity')
                                    @include('warehouses.locations.stored-products', ['stored' => $products->get($location->id, collect())])
                                    <a href="{{ route('warehouses.locations.show', $params + ['warehouseLocation' => $location]) }}" class="inline-flex min-h-10 items-center text-sm font-medium text-indigo-700 hover:underline">Tárhely adatlapja</a>
                                </div>
                            </details>
                        @endforeach
                        {{ $locations->links('pagination.inventory') }}
                    </div>
                </details>
            @endif
            @if(!$systems->total() && !$locations->total())
                <p class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600">Ebben a raktárban még nincs beállított tárhely.</p>
            @endif
        </div>
    </div>
</x-warehouse-layout>
