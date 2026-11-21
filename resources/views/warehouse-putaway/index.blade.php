<x-warehouse-task-layout>
    @php
        $sorts = \App\Services\PutawayTaskListing::SORTS;

        $columns = 'xl:grid-cols-[minmax(0,1.3fr)_minmax(0,0.9fr)_minmax(0,1.15fr)_minmax(0,0.8fr)]';
        $state = $filters['status'] ?? 'open';
        $states = ['open' => 'Nyitott feladatok', 'pending' => 'Betárolásra vár', 'partial' => 'Részben betárolva', 'completed' => 'Teljesen betárolva', 'all' => 'Összes állapot'];
        $field = 'min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500';
        $link = 'inline-flex min-h-11 items-center justify-center rounded-md px-3 py-2 text-center text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';
        $receiptFilter = array_filter(\Illuminate\Support\Arr::only($filters, ['shipment_id', 'receipt_id']));
    @endphp
    <div class="app-page space-y-4 py-4" data-putaway-list>
        <x-page-toolbar title="Betárolási feladatok" />

        <form id="putaway-filters" x-data method="GET" action="{{ route('warehouse-putaway-pages.index') }}"
              class="relative grid min-w-0 gap-3 rounded-lg border border-gray-200 bg-white p-3 sm:grid-cols-2 sm:p-4 xl:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto]" aria-label="Betárolási feladatok szűrése">

            @if (isset($filters['per_page']))<input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">@endif
            @foreach ($receiptFilter as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            <div class="min-w-0">
                <label for="putaway-task-search" class="mb-1 block text-sm font-medium text-gray-800">Termék, cikkszám, igény vagy fuvar</label>
                <x-listing-search :endpoint="route('warehouse-putaway-pages.index')" :query="$filters['search'] ?? ''"
                    id="putaway-task-search" label="Termék, cikkszám, igény vagy fuvar" placeholder="Terméknév, cikkszám vagy MOZ-azonosító…"
                    selection-hint="Enter: a kijelölt javaslatra keresés" input-class="min-h-11" :max-length="100" />
            </div>
            <div class="min-w-0">
                <label for="putaway-task-warehouse" class="mb-1 block text-sm font-medium text-gray-800">Célraktár</label>
                <select id="putaway-task-warehouse" name="warehouse_id" class="{{ $field }}">
                    <option value="">Minden elérhető raktár</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->getKey() }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->getKey())>{{ $warehouse->warehouse_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-0">
                <label for="putaway-task-status" class="mb-1 block text-sm font-medium text-gray-800">Állapot</label>
                <select id="putaway-task-status" name="status" class="{{ $field }}">
                    @foreach ($states as $value => $label)
                        <option value="{{ $value }}" @selected($state === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-0">
                <label for="putaway-task-origin" class="mb-1 block text-sm font-medium text-gray-800">Eredet</label>
                <select id="putaway-task-origin" name="origin" class="{{ $field }}">
                    <option value="">Minden eredet</option>
                    <option value="shipment" @selected(($filters['origin'] ?? '') === 'shipment')>Fuvarátvétel</option>
                    <option value="manual" @selected(($filters['origin'] ?? '') === 'manual')>Kézi bevételezés</option>
                </select>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <button class="{{ $link }} bg-indigo-600 text-white hover:bg-indigo-700">Szűrés</button>
                    <x-listing-sort :sorts="$sorts" :filters="$filters" form="putaway-filters" />
                @if ($receiptFilter || $state !== 'open' || !empty($filters['warehouse_id']) || !empty($filters['search']) || !empty($filters['origin']))
                    <a href="{{ route('warehouse-putaway-pages.index') }}" class="{{ $link }} border border-gray-300 text-gray-800 hover:bg-gray-50">Alaphelyzet</a>
                @endif
            </div>
            @if ($filterErrors->any())
                <p role="alert" class="text-sm text-red-800 sm:col-span-2 xl:col-span-5">{{ $filterErrors->first() }}</p>
            @endif
        </form>

        @if ($receiptFilter)
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-950">
                @if (isset($receiptFilter['receipt_id']))
                    <p>Az <strong>#{{ $receiptFilter['receipt_id'] }}</strong> átvételi bizonylat tételei.</p>
                @else
                    <p>A kiválasztott fuvarhoz tartozó betárolások.</p>
                @endif
                <a href="{{ route('warehouse-putaway-pages.index', \Illuminate\Support\Arr::except($filters, ['shipment_id', 'receipt_id', 'page'])) }}" class="font-semibold underline underline-offset-2">Kapcsolt szűrés törlése</a>
            </div>
        @endif

        <section aria-label="Betárolási feladatok listája">
            @if ($items->isEmpty())
                <p class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700">
                    {{ $state === 'open' && empty($filters['search']) ? 'A kiválasztott raktárakban jelenleg nincs betárolásra váró átvett készlet.' : 'A szűrésnek megfelelő átvételi tétel nem található.' }}
                    @if ($state !== 'completed')
                        <a href="{{ route('warehouse-putaway-pages.index', array_merge($receiptFilter, ['status' => 'completed'])) }}" class="font-semibold text-indigo-700 underline underline-offset-2">Betárolt tételek megtekintése</a>
                    @endif
                </p>
            @else

                <div role="table" aria-label="Betárolási feladatok" class="min-w-0 rounded-lg border border-gray-200 bg-white">
                    <div role="row" class="hidden {{ $columns }} gap-4 rounded-t-lg border-b border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-700 xl:grid">
                    <span role="columnheader" class="min-w-0 py-1">Termék és eredet</span>
                    <span role="columnheader" class="min-w-0 py-1">Célraktár és időpont</span>
                    <span role="columnheader" class="min-w-0 py-1">Mennyiségek</span>
                    <span role="columnheader" class="min-w-0 py-1">Állapot</span>
                </div>
                <ul role="rowgroup" class="divide-y divide-gray-100">
                        @foreach ($items as $row)
                            <li role="row" data-putaway-task-row data-origin="{{ $row['origin'] }}" class="grid min-w-0 gap-3 p-3 text-sm sm:grid-cols-2 sm:p-4 {{ $columns }} xl:items-start [overflow-wrap:anywhere]">
                                <div role="cell" class="min-w-0">
                                    <a href="{{ $row['url'] }}"
                                       class="inline-flex min-h-10 max-w-full items-center rounded font-semibold text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $row['product_name'] }}</a>
                                    <p class="mt-0.5 text-xs text-gray-600">{{ $row['sku'] }}@if($row['intent_reference']) · {{ $row['intent_reference'] }}@endif</p>
                                    <p class="mt-0.5 text-xs text-gray-600">{{ $row['origin_label'] }} ·
                                        @if($row['origin'] === 'manual')
                                            <a href="{{ $row['reference_url'] }}" class="font-medium text-indigo-700 underline">{{ $row['reference_number'] }}</a>
                                        @else {{ $row['reference_number'] }} @endif
                                    </p>
                                </div>
                                <div role="cell" class="min-w-0">
                                    <p class="font-medium text-gray-900">{{ $row['warehouse_name'] }}</p>
                                    @if($row['receipt_id'])<p class="mt-0.5 text-xs text-gray-600">Átvétel #{{ $row['receipt_id'] }} · Tétel #{{ $row['id'] }}</p>@endif
                                    <p class="mt-0.5 text-xs text-gray-600"><span class="xl:sr-only">Átvétel ideje </span>{{ \Illuminate\Support\Carbon::parse($row['received_at'])->timezone('Europe/Budapest')->format('Y. m. d. H:i') }}</p>
                                </div>
                                <dl role="cell" class="grid min-w-0 grid-cols-3 gap-x-2 text-sm">
                                    <div><dt class="text-xs text-gray-600">Átvett</dt><dd class="font-medium tabular-nums">{{ number_format($row['received_quantity'], 0, ',', ' ') }} db</dd></div>
                                    <div><dt class="text-xs text-gray-600">Betárolt</dt><dd class="font-medium tabular-nums">{{ number_format($row['placed_quantity'], 0, ',', ' ') }} db</dd></div>
                                    <div><dt class="text-xs text-gray-600">Még vár</dt><dd class="font-semibold tabular-nums text-indigo-800">{{ number_format($row['pending_putaway_quantity'], 0, ',', ' ') }} db</dd></div>
                                </dl>
                                <div role="cell" class="min-w-0">
                                    <span class="inline-flex rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-700">{{ $states[$row['status']] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @if ($items->hasPages())
                    <div class="mt-3">{{ $items->links('pagination.inventory') }}</div>
                @endif
            @endif
        </section>
    </div>
</x-warehouse-task-layout>
