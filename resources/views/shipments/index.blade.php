<x-app-layout>
    @php
        $field = 'mt-1 min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500';
        $primary = 'inline-flex min-h-11 items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';
        $statuses = \App\Models\Shipment::STATUSES;
        $sorts = \App\Services\ShipmentPlanReader::SORTS;
        $desktopColumns = 'xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.05fr)_minmax(0,0.85fr)_minmax(0,1fr)]';

        $filtered = filled($filters['search'] ?? null) || filled($filters['origin_warehouse_id'] ?? null) || filled($filters['status'] ?? null);
    @endphp
    <div class="app-page min-w-0 space-y-4 py-4" data-shipment-plan-list>
        <x-page-toolbar title="Fuvarok és szállítmányok" :count="$plans->total()" unit="fuvar">
            @if ($canCreate && ($plans->isNotEmpty() || $filtered))
                <a href="{{ route('shipments.create') }}" class="{{ $primary }}">Új fuvar tervezése</a>
            @endif
        </x-page-toolbar>
        @include('transfers.tabs')

        @if ($filterErrors)
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                @foreach ($filterErrors as $message)<p>{{ $message }}</p>@endforeach
                <a href="{{ route('shipments.index') }}" class="font-semibold underline">Alaphelyzet visszaállítása</a>
            </div>
        @endif

        <form id="shipment-filters" method="get" action="{{ route('shipments.index') }}" aria-label="Fuvarlista szűrése" class="relative rounded-lg border border-gray-200 bg-white p-4">

                @if (isset($filters['per_page']))<input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">@endif
                <div class="grid min-w-0 items-end gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_repeat(2,minmax(0,1fr))_auto]">
                    <div class="min-w-0 sm:col-span-2 xl:col-span-1">
                        <label for="plan-search" class="block text-sm font-medium text-gray-700">Fuvarok keresése</label>
                        <x-listing-search :endpoint="route('shipments.index')" :query="$filters['search'] ?? ''"
                            id="plan-search" label="Fuvarok keresése" placeholder="Azonosító, hivatkozás, raktár…"
                            selection-hint="Enter: a kijelölt javaslatra keresés" input-class="min-h-11" class="mt-1" />
                    </div>
                    <div class="min-w-0">
                        <label for="plan-origin" class="block text-sm font-medium text-gray-700">Induló raktár</label>
                        <select id="plan-origin" name="origin_warehouse_id" class="{{ $field }} truncate">
                            <option value="">Minden induló raktár</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->warehouse_id }}" @selected((string) ($filters['origin_warehouse_id'] ?? '') === (string) $warehouse->warehouse_id)>{{ $warehouse->warehouse_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0">
                        <label for="plan-status" class="block text-sm font-medium text-gray-700">Állapot</label>
                        <select id="plan-status" name="status" class="{{ $field }}">
                            <option value="">Minden állapot</option>
                            @foreach ($statuses as $key => $label)
                                <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 sm:col-span-2 xl:col-span-1">
                        <button type="submit" class="{{ $primary }}">Szűrés</button>
                    <x-listing-sort :sorts="$sorts" :filters="$filters" form="shipment-filters" />
                        @if ($filtered)<a href="{{ route('shipments.index') }}" class="text-sm font-semibold text-indigo-700 underline underline-offset-2">Szűrők törlése</a>@endif
                    </div>
                </div>
        </form>

        @if ($plans->isEmpty())
            <section aria-labelledby="plans-empty-title" class="rounded-lg border border-gray-200 bg-white px-4 py-9 text-center">
                <h2 id="plans-empty-title" class="font-semibold text-gray-900">
                    @if ($filtered) A szűrésnek megfelelő fuvar nem található.
                    @elseif ($plans->total()) Ezen az oldalon nincs fuvar.
                    @else Még nincs fuvarterv.
                    @endif
                </h2>
                @if ($filtered)
                    <a href="{{ route('shipments.index') }}" class="mt-3 inline-flex min-h-11 items-center text-sm font-semibold text-indigo-700 hover:underline">Összes fuvar megjelenítése</a>
                @elseif ($plans->total())
                    <a href="{{ $plans->url(1) }}" class="mt-3 inline-flex min-h-11 items-center text-sm font-semibold text-indigo-700 hover:underline">Ugrás az első oldalra</a>
                @elseif ($canCreate)
                    <p class="mt-1 text-sm text-gray-600">A tervezethez jóváhagyott átmozgatási igényeket rendelhetsz.</p>
                    <a href="{{ route('shipments.create') }}" class="{{ $primary }} mt-4">Új fuvar tervezése</a>
                @endif
            </section>
        @else
            <section aria-label="Fuvarlista" class="min-w-0">

                <div role="table" aria-label="Fuvarok" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white text-sm">
                    <div role="row" class="hidden {{ $desktopColumns }} gap-3 bg-gray-50 px-4 py-3 text-xs font-semibold text-gray-600 xl:grid">
                    <span role="columnheader" class="min-w-0 py-1">Fuvar és hivatkozás</span>
                    <span role="columnheader" class="min-w-0 py-1">Induló raktár</span>
                    <span role="columnheader" class="min-w-0 py-1">Időpontok</span>
                    <span role="columnheader" class="min-w-0 py-1">Jármű és állapot</span>
                    <span role="columnheader" class="min-w-0 py-1">Előkészítés</span>
                </div>
                <div role="rowgroup" class="divide-y divide-gray-200 xl:border-t xl:border-gray-200">
                    @foreach ($plans as $plan)
                        <div role="row" data-plan-id="{{ $plan['id'] }}" class="grid min-w-0 items-start gap-3 p-4 text-sm sm:grid-cols-2 {{ $desktopColumns }}">
                            <div role="cell" class="min-w-0 [overflow-wrap:anywhere]">
                                <a href="{{ route('shipments.show', $plan['id']) }}" class="block rounded text-indigo-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                                    <span class="block font-mono text-xs font-semibold">{{ $plan['shipment_number'] }}</span>
                                    <span class="mt-0.5 block font-semibold">{{ $plan['reference'] }}</span>
                                </a>
                            </div>
                            <div role="cell" class="min-w-0 [overflow-wrap:anywhere]">
                                <span class="text-xs font-medium text-gray-600">Induló raktár</span>
                                <p class="font-medium text-gray-900">{{ $plan['origin_warehouse_name'] }}</p>
                                <p class="mt-1 text-xs text-gray-700">{{ $plan['stop_count'] }} megálló · {{ $plan['assignment_count'] }} igény</p>
                            </div>
                            <dl role="cell" class="min-w-0 [overflow-wrap:anywhere]">
                                <div><dt class="text-xs font-medium text-gray-600">Tervezett indulás</dt><dd>
                                    @if ($plan['planned_departure_at'])
                                        <time datetime="{{ $plan['planned_departure_at'] }}">{{ \Illuminate\Support\Carbon::parse($plan['planned_departure_at'])->timezone('Europe/Budapest')->format('Y. m. d. H:i') }}</time>
                                    @else — @endif
                                </dd></div>
                                <div class="mt-1.5"><dt class="text-xs font-medium text-gray-600">Létrehozva</dt><dd>
                                    @if ($plan['created_at'])
                                        <time datetime="{{ $plan['created_at'] }}">{{ \Illuminate\Support\Carbon::parse($plan['created_at'])->timezone('Europe/Budapest')->format('Y. m. d. H:i') }}</time>
                                    @else — @endif
                                </dd></div>
                            </dl>
                            <div role="cell" class="min-w-0 [overflow-wrap:anywhere]">
                                <p class="text-xs font-medium text-gray-600 xl:hidden">Jármű és állapot</p>
                                <p>{{ $plan['vehicle_label'] ?? '—' }}</p>
                                <span class="mt-1.5 inline-block rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-800">{{ $statuses[$plan['status']] }}</span>
                            </div>
                            <dl role="cell" class="min-w-0 text-xs">
                                <div>
                                    <dt class="font-medium text-gray-600">Készletoldali előkészítés</dt>
                                    <dd class="mt-0.5 font-semibold {{ $plan['stock_ready'] ? 'text-emerald-800' : 'text-amber-800' }}">
                                        @if($plan['stock_status'] === 'dispatched') Feladva
                                        @elseif($plan['assignment_count'] === 0) Nincs hozzárendelt igény
                                        @else {{ $plan['stock_ready_count'].' / '.$plan['assignment_count'].' összekészítve' }}
                                        @endif
                                    </dd>
                                </div>
                                <div class="mt-2">
                                    <dt class="font-medium text-gray-600">Fuvaradatok</dt>
                                    <dd class="mt-0.5 font-medium text-gray-800">{{ $plan['transport_status'] === 'dispatched' || $plan['transport_ready'] ? 'Ellenőrizve' : 'Ellenőrzésre vár' }}</dd>
                                </div>
                            </dl>
                        </div>
                    @endforeach
                </div>
                </div>
            </section>
        @endif

        {{ $plans->links('pagination.inventory') }}
    </div>
</x-app-layout>
