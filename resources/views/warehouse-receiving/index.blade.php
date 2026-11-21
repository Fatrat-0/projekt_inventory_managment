<x-warehouse-task-layout>
    @php
        $statuses = ['pending' => 'Átvételre vár', 'partial' => 'Részben átvéve', 'received' => 'Teljesen átvéve'];
        $badge = ['pending' => 'bg-indigo-50 text-indigo-800', 'partial' => 'bg-amber-50 text-amber-900', 'received' => 'bg-emerald-50 text-emerald-800'];
        $sorts = \App\Services\ShipmentReceivingReader::RECEIVING_SORTS;

        $columns = 'xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1.2fr)_minmax(0,0.9fr)_minmax(0,1fr)]';
        $current = $filters['status'] ?? 'open';
        $quantity = fn ($value) => number_format($value, 0, ',', ' ').' db';
        $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone('Europe/Budapest')->format('Y. m. d. H:i') : '—';
        $field = 'mt-1 min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500';
        $link = 'inline-flex min-h-11 items-center rounded-md px-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600';
    @endphp
    <div class="app-page min-w-0 space-y-4 py-4" data-testid="receiving-queue">
        <x-page-toolbar title="Átvételi feladatok" />

        <section aria-labelledby="receiving-queue-heading" class="space-y-3">
            <h2 id="receiving-queue-heading" class="text-base font-semibold text-gray-900">Érkező szállítmányok
                <span class="ml-1 rounded-full border border-gray-200 bg-white px-2 py-0.5 align-middle text-xs font-medium tabular-nums text-gray-600">{{ number_format($stops->total(), 0, ',', ' ') }} megálló</span>
            </h2>
            <form id="receiving-filters" x-data method="get" action="{{ route('warehouse-receiving-pages.index') }}" aria-label="Átvételi feladatok szűrése" class="relative rounded-lg border border-gray-200 bg-white p-3 sm:p-4">

            @if (isset($filters['per_page']))<input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">@endif
                <div class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.6fr)_repeat(2,minmax(0,1fr))_auto] xl:items-end">
                    <div class="min-w-0 sm:col-span-2 xl:col-span-1">
                        <label for="receiving-search" class="block text-xs font-medium text-gray-700">Keresés</label>
                        <x-listing-search :endpoint="route('warehouse-receiving-pages.index')" :query="$filters['search'] ?? ''"
                            id="receiving-search" label="Keresés" placeholder="Fuvarazonosító, termék, SKU…"
                            selection-hint="Enter: a kijelölt javaslatra keresés" input-class="min-h-11" class="mt-1" />
                    </div>
                    <label class="min-w-0">
                        <span class="block text-xs font-medium text-gray-700">Célraktár</span>
                        <select name="warehouse_id" class="{{ $field }} truncate">
                            <option value="">Minden jogosult raktár</option>
                            @foreach ($warehouses as $warehouse)
                                <option value="{{ $warehouse->warehouse_id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->warehouse_id)>{{ $warehouse->warehouse_name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="min-w-0">
                        <span class="block text-xs font-medium text-gray-700">Átvételi állapot</span>
                        <select name="status" class="{{ $field }}">
                            @foreach (['open' => 'Nyitott feladatok', ...$statuses, 'all' => 'Minden állapot'] as $value => $label)
                                <option value="{{ $value }}" @selected($current === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-primary-button class="min-h-11">Szűrés</x-primary-button>
                    <x-listing-sort :sorts="$sorts" :filters="$filters" form="receiving-filters" />
                        @if (filled($filters['search'] ?? null) || filled($filters['warehouse_id'] ?? null) || $current !== 'open' || $filterErrors->any())
                            <a href="{{ route('warehouse-receiving-pages.index') }}" class="{{ $link }}">Szűrők törlése</a>
                        @endif
                    </div>
                </div>
                @if ($filterErrors->any())
                    <ul role="alert" class="mt-3 list-inside list-disc text-sm text-red-700">
                        @foreach ($filterErrors->all() as $message)<li>{{ $message }}</li>@endforeach
                    </ul>
                @endif
            </form>

            @if ($stops->isEmpty())
                <div role="status" class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-700">
                    @if ($stops->total())
                        <p>Ezen az oldalon nincs találat.</p>
                        <a class="{{ $link }}" href="{{ $stops->url(1) }}">Ugrás az első oldalra</a>
                    @elseif ($current === 'open' && !filled($filters['search'] ?? null) && !filled($filters['warehouse_id'] ?? null))
                        <p>Jelenleg nincs nyitott átvételi feladat a jogosult raktárakban.</p>
                        <a class="{{ $link }}" href="{{ route('warehouse-receiving-pages.index', ['status' => 'received']) }}">Teljesen átvett megállók</a>
                    @else
                        <p>Nincs a szűrésnek megfelelő célmegálló.</p>
                    @endif
                </div>
            @else

                <div role="table" aria-label="Átvételi célmegállók" class="min-w-0 rounded-lg border border-gray-200 bg-white">
                    <div role="row" class="hidden {{ $columns }} gap-4 rounded-t-lg border-b border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-700 xl:grid">
                    <span role="columnheader" class="min-w-0 py-1">Fuvar és hivatkozás</span>
                    <span role="columnheader" class="min-w-0 py-1">Útvonal</span>
                    <span role="columnheader" class="min-w-0 py-1">Mennyiségek</span>
                    <span role="columnheader" class="min-w-0 py-1">Állapot és feladás</span>
                </div>
                <ul role="rowgroup" class="divide-y divide-gray-200">
                        @foreach ($stops as $entry)
                            @php
                                $shipment = $entry['shipment'];
                                $stop = $entry['stop'];
                                $status = $stop['receiving_status'];
                            @endphp
                            <li role="row" data-testid="receiving-stop-row" class="grid min-w-0 gap-x-4 gap-y-3 p-3 text-sm [overflow-wrap:anywhere] sm:grid-cols-2 sm:p-4 {{ $columns }} xl:items-start">
                                <div role="cell" class="min-w-0">
                                    <a href="{{ route('warehouse-receiving-pages.show', ['shipment' => $shipment['id'], 'stop' => $stop['id']]) }}"
                                       class="inline-flex min-h-10 max-w-full items-center rounded font-mono text-xs font-semibold text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $shipment['shipment_number'] }}</a>
                                    <p class="mt-0.5 font-semibold leading-5 text-gray-900">{{ $shipment['reference'] }}</p>
                                </div>
                                <dl role="cell" class="min-w-0 space-y-1">
                                    <div><dt class="text-xs text-gray-600">Forrásraktár</dt><dd class="font-medium text-gray-800">{{ $shipment['origin_warehouse_name'] }}</dd></div>
                                    <div><dt class="text-xs text-gray-600">{{ $stop['position'] }}. célmegálló</dt><dd class="font-semibold text-gray-900">{{ $stop['warehouse_name'] }}</dd></div>
                                </dl>
                                <div role="cell" class="min-w-0">
                                    <p class="font-semibold tabular-nums text-gray-900">{{ number_format($stop['item_count'], 0, ',', ' ') }} tétel</p>
                                    <dl class="text-xs leading-5 tabular-nums text-gray-700">
                                        <div><dt class="inline">Átvéve</dt> <dd class="inline font-medium">{{ $quantity($stop['received_quantity']) }}</dd></div>
                                        <div><dt class="inline">Még átvehető</dt> <dd class="inline font-semibold">{{ $quantity($stop['remaining_quantity']) }}</dd></div>
                                    </dl>
                                </div>
                                <div role="cell" class="min-w-0">
                                    <span class="inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $badge[$status] }}">{{ $statuses[$status] }}</span>
                                    <dl class="mt-1 space-y-0.5 text-xs leading-5 text-gray-600">
                                        <div><dt>Tervezett feladás</dt><dd class="text-gray-800">{{ $date($shipment['planned_departure_at'] ?? null) }}</dd></div>
                                        <div><dt>Tényleges feladás</dt><dd class="text-gray-800">{{ $date($entry['dispatch']['dispatched_at'] ?? null) }}</dd></div>
                                    </dl>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
                {{ $stops->links('pagination.inventory') }}
            @endif
        </section>
    </div>
</x-warehouse-task-layout>
