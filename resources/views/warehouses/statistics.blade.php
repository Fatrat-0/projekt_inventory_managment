<x-warehouse-layout :$context>
    @php($selected = $options->firstWhere('warehouse_id', $warehouseId))
    <div class="app-page space-y-4 py-4" data-warehouse-statistics>
        <x-page-toolbar title="Raktári statisztika" :description="$selected?->warehouse_name ?? 'Összes raktár'">
            @if($selected)
                <a href="{{ route('warehouses.show', ['warehouse' => $selected] + ($context !== '' ? ['context' => $context] : [])) }}" data-statistics-controls class="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-indigo-700 hover:bg-indigo-50">Raktár adatlapja</a>
            @endif
            @if($rows->isNotEmpty())
                <x-pdf-preview id="warehouse-statistics-pdf" data-statistics-controls
                    :url="route('warehouses.statistics-pdf', $warehouseId !== null ? ['warehouse_id' => $warehouseId] : [])"
                    title="Raktári statisztika PDF-előnézete" button-label="Statisztika PDF"
                    :options="\App\Support\WarehouseStatisticsPdfOptions::defaults()"
                    :checkboxes="\App\Support\WarehouseStatisticsPdfOptions::SECTIONS"
                    :description="'A PDF a kiválasztott nézet adatait tartalmazza. Raktár: '.($selected?->warehouse_name ?? 'Összes raktár').'.'" />
            @endif
        </x-page-toolbar>
        <x-operation-feedback />
        @if($options->isNotEmpty())
            <form method="GET" action="{{ route('warehouses.statistics') }}" data-statistics-controls class="flex flex-wrap items-end gap-2 rounded-lg border border-gray-200 bg-white p-3">
                @if($context !== '')<input type="hidden" name="context" value="{{ $context }}">@endif
                <label class="w-full min-w-0 text-sm font-medium text-gray-700 sm:w-auto">Raktár
                    <select name="warehouse_id" onchange="this.form.requestSubmit()" class="mt-1 block w-full max-w-full rounded-md border-gray-300 text-sm sm:w-72">
                        <option value="">Összes raktár</option>
                        @foreach($options as $option)
                            <option value="{{ $option->warehouse_id }}" @selected($option->warehouse_id === $warehouseId)>{{ $option->warehouse_name }}</option>
                        @endforeach
                    </select>
                </label>
                <noscript><button type="submit" class="min-h-10 rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700">Megjelenítés</button></noscript>
            </form>
        @endif

        @if($rows->isEmpty())
            <p class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600">Jelenleg nincs megjeleníthető raktár.</p>
        @else
            <div class="grid min-w-0 gap-4 md:grid-cols-2 xl:grid-cols-3" data-statistics-charts>
                @foreach($report->charts as $key => $chart)
                    @include('warehouses.statistics-chart', $chart + ['key' => $key])
                @endforeach
            </div>
            <p class="text-sm text-gray-600">{{ \App\Support\WarehouseStatisticsReport::CAPACITY_NOTE }}</p>
            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach($report->counters as $key => $counter)
                    <div class="rounded-lg border border-gray-200 bg-white p-4"><dt class="text-sm text-gray-600">{{ $counter['label'] }}</dt><dd class="mt-1 text-2xl font-semibold tabular-nums" data-statistic="{{ $key }}">{{ $counter['value'] }}</dd></div>
                @endforeach
            </dl>
        @endif
    </div>
</x-warehouse-layout>
