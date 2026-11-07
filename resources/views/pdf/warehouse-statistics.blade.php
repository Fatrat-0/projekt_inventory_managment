<!doctype html>
<html lang="hu"><head><meta charset="UTF-8"><title>Raktári statisztika</title>
<style>
    @page { margin: 36px 36px 40px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #253047; }
    h1 { margin: 0 0 7px; font-size: 23px; }
    h2 { margin: 0 0 7px; font-size: 13px; }
    p { margin: 0 0 7px; }
    .header { margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #4f46e5; }
    .scope { font-size: 13px; font-weight: bold; overflow-wrap: break-word; }
    .muted { color: #526079; }
    .grid { width: 100%; border-collapse: separate; border-spacing: 6px; table-layout: fixed; margin: 0 0 12px; }
    .card { padding: 12px; border: 1px solid #dce2ec; vertical-align: top; }
    .card h2 { min-height: 32px; }
    .ring-wrap { text-align: center; margin: 9px 0 4px; }
    .ring { width: 104px; height: 104px; vertical-align: middle; }
    .metric { text-align: center; margin-bottom: 12px; }
    .metric strong { display: block; font-size: 17px; }
    .legend { width: 100%; border-collapse: collapse; font-size: 10px; }
    .legend td { padding: 4px 0; vertical-align: top; }
    .legend td.amount { width: 42%; padding-left: 4px; text-align: right; font-weight: bold; }
    .legend-total td { border-top: 1px solid #dce2ec; }
    .swatch { display: inline-block; width: 7px; height: 7px; margin-right: 4px; }
    .counters { page-break-inside: avoid; }
    .counter { background: #f4f6fa; padding: 9px; vertical-align: top; }
    .counter strong { display: block; font-size: 16px; }
    .note { margin: 10px 6px 0; font-size: 10px; }
    .footer { position: fixed; left: 0; right: 0; bottom: -24px; font-size: 9px; color: #526079; }
    .page { float: right; }
    .page:before { content: counter(page); }
</style></head><body>
    <div class="footer">Készletkezelő <span class="page">. oldal</span></div>
    <div class="header">
        <h1>Raktári statisztika</h1>
        <p class="scope">{{ $scope }}</p>
        <p class="muted">Lekérdezve: {{ $exportedAt->format('Y.m.d H:i') }}</p>
    </div>

    @php($charts = array_filter($report->charts, fn ($key) => $options['show_'.$key], ARRAY_FILTER_USE_KEY))
    @if($charts)
        <table class="grid"><tr>
            @foreach($charts as $key => $chart)
                <td class="card">
                    <h2>{{ $chart['title'] }}</h2>
                    @if($options['show_notes'])<p class="muted">{{ $chart['subtitle'] }}</p>@endif
                    <div class="ring-wrap"><img class="ring" src="data:image/svg+xml;base64,{{ base64_encode(view('warehouses.statistics-ring', $chart)->render()) }}" alt=""></div>
                    @if($chart['total'] > 0)
                        <div class="metric"><strong>{{ $chart['value'] }}</strong>{{ $chart['valueLabel'] }}</div>
                        <table class="legend">
                            @foreach($chart['parts'] as $i => $amount)
                                <tr><td><span class="swatch" style="background-color: {{ $chart['colors'][$i] }}"></span>{{ $chart['legend'][$i] }}</td>
                                    <td class="amount">{{ \App\Support\WarehouseStatisticsReport::format($amount, $chart['unit']) }}</td></tr>
                            @endforeach
                            @if($key !== 'stock')
                                <tr class="legend-total"><td>Teljes kapacitás</td><td class="amount">{{ \App\Support\WarehouseStatisticsReport::format($chart['total'], $chart['unit']) }}</td></tr>
                            @endif
                        </table>
                    @else
                        <p class="muted">{{ $chart['empty'] }}</p>
                    @endif
                </td>
            @endforeach
        </tr></table>
    @endif

    @if($options['show_counters'])
        <section class="counters">
            <h2>Tárhelyek és átmozgatások</h2>
            <table class="grid"><tr>
                @foreach($report->counters as $counter)
                    <td class="counter">{{ $counter['label'] }}<strong>{{ $counter['value'] }}</strong></td>
                @endforeach
            </tr></table>
        </section>
    @endif
    @if($options['show_notes'] && ($options['show_weight'] || $options['show_pallets']))
        <p class="note muted">{{ \App\Support\WarehouseStatisticsReport::CAPACITY_NOTE }}</p>
    @endif
    @if(!$charts && !$options['show_counters'])<p class="muted">A jelentéshez nincs kijelölt diagram vagy számláló.</p>@endif
</body></html>
