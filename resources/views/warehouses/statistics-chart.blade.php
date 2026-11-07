<section aria-labelledby="warehouse-statistics-{{ $key }}" class="flex min-w-0 flex-col rounded-lg border border-gray-200 bg-white p-4" data-statistics-chart>
    <header class="min-w-0">
        <h2 id="warehouse-statistics-{{ $key }}" class="font-semibold text-gray-900">{{ $title }}</h2>
        <p class="mt-1 text-xs text-gray-600">{{ $subtitle }}</p>
    </header>

    <div class="relative mx-auto my-4 aspect-square w-44 max-w-full" data-statistics-ring>
        @include('warehouses.statistics-ring')
        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-1 text-center">
            @if($total === 0)
                <span class="text-sm text-gray-500">Nincs adat</span>
            @else
                <strong class="max-w-[7rem] break-words tabular-nums text-gray-900 {{ $key === 'stock' ? 'text-xl' : 'text-2xl' }}">{{ $value }}</strong>
                <span class="max-w-[7rem] text-xs text-gray-600">{{ $valueLabel }}</span>
            @endif
        </div>
    </div>

    @if($total > 0)
        <dl class="mt-auto space-y-2 text-sm text-gray-700">
            @foreach($parts as $i => $amount)
                <div class="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                    <dt class="flex min-w-0 items-center gap-2"><span aria-hidden="true" class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $colors[$i] }}" data-statistics-swatch></span>{{ $legend[$i] }}</dt>
                    <dd class="font-semibold tabular-nums text-gray-900">{{ \App\Support\WarehouseStatisticsReport::format($amount, $unit) }}</dd>
                </div>
            @endforeach
            @if($key !== 'stock')
                <div class="flex flex-wrap justify-between gap-2 border-t border-gray-100 pt-2"><dt>Teljes kapacitás</dt><dd class="font-semibold tabular-nums text-gray-900">{{ \App\Support\WarehouseStatisticsReport::format($total, $unit) }}</dd></div>
            @endif
        </dl>
    @else
        <p class="mt-auto text-sm text-gray-600">{{ $empty }}</p>
    @endif
</section>
