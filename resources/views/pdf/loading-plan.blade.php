@php($options = array_replace(\App\Support\LoadingPlanPdfOptions::DEFAULTS, $options ?? []))
@php($loadingPlan ??= \App\Support\ShipmentLoadingPlanPdf::forShipment($shipment))
<!doctype html><html lang="hu"><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #253047; } h1 { font-size: 19px; } h2 { font-size: 13px; margin-top: 20px; } table { border-collapse: collapse; width: 100%; } td, th { text-align: left; padding: 7px; border: 1px solid #d7dce5; } th { background: #eef1f8; } .stop { page-break-inside: avoid; margin-bottom: 12px; } .note { color: #596579; } .footer { position: fixed; bottom: -10px; font-size: 9px; color: #596579; } .loading-check { width: 48px; text-align: center; } td.loading-check { height: 18px; } .signatures { page-break-inside: avoid; margin-top: 25px; } .signatures td { width: 50%; border: 0; padding-top: 35px; text-align: center; } .signature-line { border-top: 1px solid #596579; padding-top: 5px; }
@if($options['orientation'] === 'landscape')
body { font-size: 9px; } h1 { font-size: 17px; margin-bottom: 8px; } h2 { font-size: 11px; margin-top: 12px; margin-bottom: 6px; } p { margin: 6px 0; } td, th { padding: 5px; } .stop { margin-bottom: 6px; } .signatures { margin-top: 12px; } .signatures td { padding-top: 20px; }
@endif
</style></head><body>
<p class="footer">{{ $loadingPlan['shipment_number'] }} · Tervverzió: {{ $loadingPlan['version'] }} · Nyomtatva: {{ now()->format('Y.m.d H:i') }}</p>
<h1>Rakodási terv · {{ $loadingPlan['shipment_number'] }}</h1><p><strong>{{ $loadingPlan['reference'] }}</strong> · {{ \App\Models\Shipment::STATUSES[$loadingPlan['status']] }}</p>
<p>Induló raktár: {{ $loadingPlan['origin'] }}@if($options['show_addresses'] && $loadingPlan['location'])<br>{{ $loadingPlan['location'] }}@endif<br>Jármű: {{ $loadingPlan['vehicle'] ?? 'Nincs megadva' }}</p>
@if($loadingPlan['status'] === 'draft')<p><strong>TERVEZET — készletlevonás még nem történt.</strong></p>@endif
@if($options['show_route'])<h2>Lerakodási útvonal</h2><p>@foreach($loadingPlan['stops'] as $stop){{ $stop['position'] }}. {{ $stop['name'] }}{{ !$loop->last ? ' → ' : '' }}@endforeach</p>@endif
<h2>Felrakodás: az alábbi sorrendben</h2><p class="note">Elsőként a legbelső rakományt helyezd fel. Az első megálló áruja kerül utoljára, a hátsó ajtóhoz közel. Megállónkénti sorrendterv; nem méret- vagy tömegalapú térbeli elhelyezési terv.</p>
@foreach($loadingPlan['loading_stops'] as $stop)<div class="stop"><h2>{{ $stop['loading_position'] }}. felrakodás · {{ $stop['name'] }}</h2><p>{{ $stop['position'] }}. lerakodási megálló@if($options['show_addresses'] && $stop['location']) · {{ $stop['location'] }}@endif{{ $stop['received'] ? ' · Már átvéve' : '' }}</p><table><thead><tr><th>Termék</th>@if($options['show_sku'])<th>SKU</th>@endif<th>Mennyiség</th>@if($options['show_loading_column'])<th class="loading-check">Rakodva</th>@endif</tr></thead><tbody>@foreach($stop['items'] as $item)<tr><td>{{ $item['name'] }}@isset($item['intent_reference'])<br><small class="note">{{ $item['intent_reference'] }}</small>@endisset</td>@if($options['show_sku'])<td>{{ $item['sku'] }}</td>@endif<td>{{ number_format($item['quantity'], 0, ',', ' ') }} db</td>@if($options['show_loading_column'])<td class="loading-check"></td>@endif</tr>@endforeach</tbody></table>
@if($loop->last)
    @if($options['show_note'] && $loadingPlan['note'])<p style="white-space: pre-wrap;">Szállítmány megjegyzése: {{ $loadingPlan['note'] }}</p>@endif
    @if($options['show_signatures'])<table class="signatures"><tr><td><div class="signature-line">Rakodást végezte</div></td><td><div class="signature-line">Ellenőrizte</div></td></tr></table>@endif
@endif
</div>@endforeach
</body></html>
