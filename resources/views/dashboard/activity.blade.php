<section aria-labelledby="activity-title" class="min-w-0 rounded-lg border border-gray-200 bg-white p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><h2 id="activity-title" tabindex="-1" class="font-semibold text-gray-900">Rögzített készletmozgások</h2><p class="mt-1 text-xs text-gray-500">Az időszak a mozgásösszesítőt szűri; a készlet és a nyitott feladatok mindig az aktuális állapotot mutatják.</p></div>
        <form method="GET" action="{{ route('dashboard') }}" @submit.prevent="updatePeriod($el)" :aria-busy="loading ? 'true' : 'false'" class="flex flex-wrap items-center gap-2">
            @foreach($listPageParams as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <label for="dashboard-days" class="text-sm text-gray-600">Időszak</label>
            <select id="dashboard-days" name="days" class="rounded-md border-gray-300 text-sm" @change="$el.form.requestSubmit()"><option value="7" @selected($days === 7)>Utolsó 7 nap</option><option value="30" @selected($days === 30)>Utolsó 30 nap</option></select>
            <fieldset class="inline-flex min-w-0 shrink-0 gap-0.5 rounded-md border border-gray-300 bg-white p-0.5" @change="setChart($event.target.value)">
                <legend class="sr-only">Diagram típusa</legend>
                @foreach(['bar' => 'Oszlopdiagram', 'line' => 'Vonaldiagram'] as $type => $label)
                    <label title="{{ $label }}" class="relative inline-flex cursor-pointer">
                        <input type="radio" name="chart" value="{{ $type }}" @checked($chartType === $type) :checked="chartType === '{{ $type }}'" class="peer absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0">
                        <span aria-hidden="true" class="pointer-events-none flex h-9 w-9 items-center justify-center rounded text-gray-500 peer-hover:bg-gray-100 peer-checked:bg-indigo-100 peer-checked:text-indigo-700 peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-600 peer-focus-visible:ring-offset-1">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                <path d="M3 3v18h18" />
                                @if($type === 'bar')<path d="M8 17v-5m5 5V7m5 10V4" />@else<path d="m6 15 4-5 4 3 6-7" />@endif
                            </svg>
                        </span>
                        <span class="sr-only">{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
            <noscript><button type="submit" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Mutat</button></noscript>
        </form>
    </div>
    <p role="alert" x-show="error" x-text="error" style="display:none" class="mt-3 text-sm text-red-700"></p>
    <div data-activity-body :class="{ 'opacity-60': loading }">
    <dl class="mt-5 grid gap-3 sm:grid-cols-3">
        <div><dt class="text-xs text-gray-500">Rögzített műveletek</dt><dd class="text-xl font-semibold tabular-nums text-gray-900" data-activity-total>{{ number_format($activitySummary['count'], 0, ',', ' ') }}</dd></div>
        <div><dt class="text-xs text-gray-500">Bejövő mennyiség</dt><dd class="text-xl font-semibold tabular-nums text-emerald-700">{{ number_format($activitySummary['in_quantity'], 0, ',', ' ') }} db</dd></div>
        <div><dt class="text-xs text-gray-500">Kimenő mennyiség</dt><dd class="text-xl font-semibold tabular-nums text-indigo-700">{{ number_format($activitySummary['out_quantity'], 0, ',', ' ') }} db</dd></div>
    </dl>
    <figure class="mt-5" aria-label="Napi készletmozgások száma">
        <div class="relative" x-data="activityChart({{ \Illuminate\Support\Js::from($dailyActivity->values()->all()) }})" data-activity-interactive
            @pointerleave="hoveredIndex = null" @keydown.escape.window="if (activeIndex !== null) dismissed = true"
            @resize.window.debounce.75ms="position()" @scroll.window.throttle.50ms="position()">
        <div aria-hidden="true">
        <div x-show="chartType === 'line'" @if($chartType !== 'line') style="display:none" @endif>
            @include('dashboard.chart-line')
        </div>
        <div x-show="chartType === 'bar'" @if($chartType !== 'bar') style="display:none" @endif>
        <div class="flex h-40 items-end gap-1 border-b border-gray-200" data-activity-chart="bar">
            @foreach($dailyActivity as $day)
                <div class="flex h-full min-w-0 flex-1 flex-col justify-end">
                    <div class="rounded-t-sm bg-indigo-500" style="height: {{ $day['out_count'] / $activityPeak * 100 }}%" aria-hidden="true"></div>
                    <div class="bg-emerald-500" style="height: {{ $day['in_count'] / $activityPeak * 100 }}%" aria-hidden="true"></div>
                </div>
            @endforeach
        </div>
        </div>
        <div class="mt-1 flex h-4 gap-1 text-xs leading-4 text-gray-500 {{ $days === 7 ? 'text-center' : 'justify-between' }}" aria-hidden="true">
            @if($days === 7)
                @foreach($dailyActivity as $day)<span class="min-w-0 flex-1">{{ $day['label'] }}</span>@endforeach
            @else
                <span>{{ $dailyActivity->first()['label'] }}</span><span>{{ $dailyActivity[intdiv($dailyActivity->count() - 1, 2)]['label'] }}</span><span>{{ $dailyActivity->last()['label'] }}</span>
            @endif
        </div>
        </div>
        @include('dashboard.chart-days')
        </div>
        <figcaption class="mt-2 flex flex-wrap justify-between gap-2 text-xs text-gray-500"><span>{{ $periodStart->format('Y.m.d.') }} – {{ $asOf->format('Y.m.d.') }}</span><span><span class="inline-block h-2 w-2 rounded-sm bg-emerald-500" aria-hidden="true"></span> Bejövő · <span class="inline-block h-2 w-2 rounded-sm bg-indigo-500" aria-hidden="true"></span> Kimenő műveletek száma<span x-show="chartType === 'line'" @if($chartType !== 'line') style="display:none" @endif> (szaggatott vonal)</span></span></figcaption>
    </figure>
    <p class="mt-3 text-xs text-gray-500">A napokra kattintva megnyílik a napi mozgásnapló. Billentyűzettel a nyilak is használhatók; a pontos értékek a Napi részletekben is láthatók.</p>
    @if(!$activitySummary['count'])<p class="mt-3 text-sm text-gray-500">Ebben az időszakban nem történt készletmozgás.</p>@endif
    <p class="mt-3 text-xs text-gray-500">A visszáruk és selejtezések is szerepelnek a mozgásnaplóban. A raktárközi átmozgatásokat külön mutatjuk.</p>
    <details class="mt-4 text-sm" data-daily-details><summary class="cursor-pointer font-medium text-gray-700">Napi részletek</summary>
        <div class="relative mt-2 max-h-64 overflow-auto"><table class="w-full text-left text-xs"><caption class="sr-only">Készletmozgások napi bontása</caption><thead><tr class="border-b"><th scope="col" class="p-2">Dátum</th><th scope="col" class="p-2">Bejövő</th><th scope="col" class="p-2">Kimenő</th></tr></thead><tbody>@foreach($dailyActivity as $day)<tr class="border-b border-gray-100"><th scope="row" class="whitespace-nowrap p-2 font-normal"><a href="{{ route('movements.index', ['date_from' => $day['date'], 'date_to' => $day['date']]) }}" class="inline-flex min-h-9 items-center font-medium text-indigo-700 hover:underline">{{ $day['date'] }}</a></th><td class="p-2">{{ $day['in_count'] }} művelet · {{ number_format($day['in_quantity'], 0, ',', ' ') }} db</td><td class="p-2">{{ $day['out_count'] }} művelet · {{ number_format($day['out_quantity'], 0, ',', ' ') }} db</td></tr>@endforeach</tbody></table></div>
    </details>
    <a href="{{ route('movements.index', ['date_from' => $periodStart->toDateString(), 'date_to' => $asOf->toDateString()]) }}" class="mt-4 inline-block text-sm font-semibold text-indigo-700 hover:underline">Időszak mozgásnaplója →</a>
    </div>
</section>
