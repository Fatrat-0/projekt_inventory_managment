@php
    $states = ['active' => 'Intézkedést igényel', 'pending' => 'Ellenőrzésre vár',
        'discrepancy_open' => 'Terhelési eltérés', 'history' => 'Előzmények'];
    $kg = static fn (?int $grams) => $grams === null ? 'Nincs adat' : \App\Models\WarehouseLocation::formatWeight($grams).' kg';
    $slots = static fn (?int $value) => $value === null ? 'Nincs adat' : $value.' raklaphely';
    $ruleNames = ['location_weight' => 'Rekesz teherbírása', 'level_weight' => 'Polcszint teherbírása',
        'system_weight' => 'Állvány teherbírása', 'pallet_space' => 'Raklapférőhely', 'pallet_kind' => 'Raklapos tárolás feltétele'];
    $date = static fn ($value) => \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone'))->format('Y. m. d. H:i');
    $context = array_diff_key($params, ['warehouse' => true]);
@endphp

<x-warehouse-layout :$warehouse :context="$params['context'] ?? ''" :detail-context="$params['detail_context'] ?? ''">
    <div class="app-page min-w-0 space-y-4 py-4 [overflow-wrap:anywhere]">
        <x-page-toolbar title="Terhelésellenőrzések" :description="$warehouse->warehouse_name" />
        <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-700">
            <span>{{ $counts['pending'] }} ellenőrzésre vár</span>
            <span @class(['font-semibold text-amber-900' => $counts['discrepancy_open'] > 0])>{{ $counts['discrepancy_open'] }} nyitott eltérés</span>
        </div>
        <form action="{{ route('warehouses.load-checks.index', $warehouse) }}" method="GET"
              class="grid min-w-0 gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.25fr)_auto]">
            @foreach($context as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            @foreach(['location_id', 'placement_id'] as $key)
                @if(isset($filters[$key]))<input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">@endif
            @endforeach
            <label class="min-w-0 text-sm font-medium text-gray-700">Raktár
                <select name="warehouse_id" class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
                    @foreach($warehouses as $option)
                        <option value="{{ $option->getKey() }}" @selected($warehouseId === (int) $option->getKey())>{{ $option->warehouse_name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="min-w-0 text-sm font-medium text-gray-700">Állapot
                <select name="state" class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
                    @foreach($states as $value => $label)<option value="{{ $value }}" @selected($filters['state'] === $value)>{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="min-w-0 text-sm font-medium text-gray-700">Termék vagy rekeszkód
                <input type="search" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="Terméknév, cikkszám, rekeszkód…"
                       class="mt-1 block min-h-11 w-full rounded-md border-gray-300 text-sm">
            </label>
            <button class="min-h-11 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 xl:self-end">Keresés</button>
        </form>
        @if($location || isset($filters['placement_id']))
            <a href="{{ route('warehouses.load-checks.index', $params + ['state' => $filters['state'], 'q' => $filters['q']]) }}"
               class="inline-flex min-h-10 items-center text-sm font-medium text-indigo-700 underline">
                {{ $location ? 'Rekeszszűrés törlése ('.$location->code.')' : 'Tételszűrés törlése' }}
            </a>
        @endif
        @if($items->isEmpty())
            <p class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-700">
                @if($history)
                    Nincs megjeleníthető ellenőrzési előzmény.
                @elseif($filters['q'] !== '' || $location || $filters['state'] !== 'active')
                    A megadott feltételekkel nincs ellenőrizendő rekesztétel.
                @else
                    Ebben a raktárban jelenleg nincs ellenőrzésre váró rekeszterhelés.
                @endif
            </p>
        @elseif($history)
            <ul class="divide-y divide-gray-200 rounded-lg border border-gray-200 bg-white">
                @foreach($items as $event)
                    <li class="min-w-0 px-4 py-3" data-load-history>
                        <details class="min-w-0">
                            <summary class="cursor-pointer rounded text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                                <span class="font-semibold">{{ $event['product_name'] }}</span>
                                <span class="text-gray-700">· {{ $event['location_code'] }} · {{ $date($event['measured_at']) }}</span>
                                <span class="ml-2 inline-block rounded px-2 py-0.5 text-xs font-semibold {{ $event['result'] === 'verified' ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-900' }}">
                                    {{ $event['result'] === 'verified' ? 'Ellenőrzött' : ($event['capacity_violations'] ? 'Dokumentált terhelési eltérés' : 'Elutasított mérés') }}
                                </span>
                            </summary>
                            @include('warehouses.load-checks.history-entry')
                        </details>
                    </li>
                @endforeach
            </ul>
        @else
            @php
                $columns = 'lg:grid-cols-[minmax(0,1.38fr)_minmax(0,1.1fr)_minmax(0,.58fr)_minmax(0,1fr)_minmax(0,1.38fr)_minmax(0,.95fr)]';
                $mobileLabel = 'mb-1 block text-xs font-medium text-gray-600 lg:hidden';
            @endphp
            <div role="table" aria-label="Ellenőrzendő rekesztételek" class="rounded-lg border border-gray-200 bg-white">
                <div role="row" class="sr-only text-xs font-semibold text-gray-600 lg:not-sr-only lg:grid lg:gap-3 lg:rounded-t-lg lg:border-b lg:border-gray-200 lg:bg-gray-50 lg:px-4 lg:py-3 {{ $columns }}">
                    @foreach(['Termék', 'Tárolóhely', 'Darabszám', 'Nyilvántartott terhelés', 'Állapot', 'Művelet'] as $label)
                        <div role="columnheader">{{ $label }}</div>
                    @endforeach
                </div>
                <div role="rowgroup" class="divide-y divide-gray-200">
                    @foreach($items as $row)
                        @php
                            $open = $row['status'] === 'discrepancy_open';
                            $critical = $row['severity'] === 'critical';
                            $rules = ['location_weight' => 'Rekeszterhelés', 'level_weight' => 'Polcszint terhelése',
                                'system_weight' => 'Tárolórendszer terhelése', 'pallet_space' => 'Raklapférőhely', 'pallet_kind' => 'Raklapos tárolás feltétele'];
                            $id = $row['placement_id'];
                            $worksheet = route('warehouses.load-checks.show', $params + ['placement' => $id]);
                        @endphp
                        <div role="row" data-load-row @class(['grid min-w-0 grid-cols-2 items-start gap-x-3 gap-y-3 px-4 py-3 text-sm', $columns, 'bg-rose-50/60' => $critical, 'bg-amber-50/40' => $open && !$critical])>
                            <div role="cell" class="col-span-2 min-w-0 lg:col-span-1">
                                <span class="{{ $mobileLabel }}" aria-hidden="true">Termék</span>
                                <a id="load-product-{{ $id }}" href="{{ $worksheet }}"
                                   class="font-semibold text-indigo-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">{{ $row['product']['name'] }}</a>
                                <p class="text-xs text-gray-600">{{ $row['product']['sku'] }}</p>
                            </div>
                            <div role="cell" class="min-w-0">
                                <span class="{{ $mobileLabel }}" aria-hidden="true">Tárolóhely</span>
                                <p id="load-location-{{ $id }}" class="font-semibold text-gray-900">{{ $row['location']['code'] }}</p>
                                <p class="text-xs text-gray-600">{{ implode(' · ', array_filter($row['location']['position'])) ?: $row['location']['name'] }}</p>
                            </div>
                            <div role="cell" class="min-w-0">
                                <span class="{{ $mobileLabel }}" aria-hidden="true">Darabszám</span>
                                <p class="font-medium tabular-nums">{{ $row['quantity'] }} db</p>
                            </div>
                            <div role="cell" class="min-w-0">
                                <span class="{{ $mobileLabel }}" aria-hidden="true">Nyilvántartott terhelés</span>
                                <p class="font-medium tabular-nums">{{ $kg($row['recorded_weight_grams']) }}</p>
                                <p class="text-xs text-gray-600">{{ $slots($row['recorded_pallet_slots']) }}</p>
                            </div>
                            <div role="cell" class="min-w-0">
                                <span class="{{ $mobileLabel }}" aria-hidden="true">Állapot</span>
                                <span @class(['inline-flex items-start gap-1 rounded px-2 py-0.5 text-xs font-semibold',
                                    'bg-rose-100 text-rose-950' => $critical, 'bg-amber-50 text-amber-950' => !$critical])>
                                    <x-icon name="warning" />
                                    <span>{{ $critical ? 'Kritikus terhelési eltérés' : ($open ? 'Nyitott terhelési eltérés' : 'Ellenőrzésre vár') }}</span>
                                </span>
                                @if($critical)
                                    <ul class="mt-1 space-y-1 text-xs font-medium text-rose-950">
                                        @foreach($row['discrepancy']['violations'] as $key)
                                            @php($excess = $row['discrepancy']['limits'][$key]['excess'] ?? null)
                                            <li>{{ $rules[$key] }} túllépve{{ $excess === null ? '' : ': +'.($key === 'pallet_space' ? $slots($excess) : $kg($excess)) }}</li>
                                        @endforeach
                                    </ul>
                                @elseif($open)
                                    <p class="mt-1 text-xs text-amber-950">Korábbi mérés. A további elhelyezés korlátozott.</p>
                                @else
                                    <p class="mt-1 text-xs text-gray-600">Mérd le a teljes megmaradt tételt.</p>
                                @endif
                            </div>
                            <div role="cell" class="col-span-2 min-w-0 lg:col-span-1">
                                <a href="{{ $worksheet }}" aria-describedby="load-product-{{ $id }} load-location-{{ $id }}"
                                   class="inline-flex min-h-10 items-center rounded-md border border-indigo-200 bg-white px-2.5 py-1.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                                    {{ $open ? 'Részletek' : 'Ellenőrzés megnyitása' }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @if($items->hasPages()){{ $items->links('pagination.inventory') }}@endif
    </div>
</x-warehouse-layout>
