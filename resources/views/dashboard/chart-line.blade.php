@php
    $points = $dailyActivity->values()->map(function ($day, $index) use ($dailyActivity, $activityPeak) {
        return $day + ['x' => ($index + 0.5) / $dailyActivity->count() * 700,
            'in_y' => 155 - $day['in_count'] / $activityPeak * 145,
            'out_y' => 155 - $day['out_count'] / $activityPeak * 145];
    });
@endphp
<div class="relative h-40 border-b border-gray-200" data-activity-chart="line">
    <svg viewBox="0 0 700 160" preserveAspectRatio="none" class="h-full w-full" role="img" aria-labelledby="line-chart-title line-chart-description">
        <title id="line-chart-title">Bejövő és kimenő készletmozgások napi alakulása</title>
        <desc id="line-chart-description">Két külön vonal, napi műveletszámmal, közös nulláról induló skálán. A zöld folytonos vonal a bejövő, az indigó szaggatott a kimenő műveleteket mutatja. A pontos értékek a Napi részletek táblázatban is megtalálhatók.</desc>
        <path d="M0 10H700 M0 82.5H700 M0 155H700" fill="none" stroke="#e5e7eb" vector-effect="non-scaling-stroke" />
        <polyline data-series="in" points="{{ $points->map(fn ($point) => $point['x'].','.$point['in_y'])->implode(' ') }}" fill="none" stroke="#10b981" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
        <polyline data-series="out" points="{{ $points->map(fn ($point) => $point['x'].','.$point['out_y'])->implode(' ') }}" fill="none" stroke="#6366f1" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
    </svg>
    @foreach($points as $point)
        @foreach(['in' => 'bg-emerald-500', 'out' => 'bg-indigo-500'] as $direction => $color)
            <span class="pointer-events-none absolute h-1.5 w-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full {{ $color }}" style="left: {{ $point['x'] / 700 * 100 }}%; top: {{ $point[$direction.'_y'] / 160 * 100 }}%" aria-hidden="true"></span>
        @endforeach
    @endforeach
</div>
