@php($options = array_replace(\App\Support\LoadingPlanPdfOptions::DEFAULTS, $options ?? []))
<!doctype html><html lang="hu"><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #253047; } h1 { font-size: 19px; } h2 { font-size: 13px; margin-top: 20px; } table { border-collapse: collapse; width: 100%; } td, th { text-align: left; padding: 7px; border: 1px solid #d7dce5; } th { background: #eef1f8; } .stop { page-break-inside: avoid; margin-bottom: 12px; } .note { color: #596579; } .footer { position: fixed; bottom: -10px; font-size: 9px; color: #596579; } .loading-check { width: 48px; text-align: center; } td.loading-check { height: 18px; } .signatures { page-break-inside: avoid; margin-top: 25px; } .signatures td { width: 50%; border: 0; padding-top: 35px; text-align: center; } .signature-line { border-top: 1px solid #596579; padding-top: 5px; }
@if($options['orientation'] === 'landscape')
body { font-size: 9px; } h1 { font-size: 17px; margin-bottom: 8px; } h2 { font-size: 11px; margin-top: 12px; margin-bottom: 6px; } p { margin: 6px 0; } td, th { padding: 5px; } .stop { margin-bottom: 6px; } .signatures { margin-top: 12px; } .signatures td { padding-top: 20px; }
@endif
</style></head><body>
<p class="footer">{{ $shipment->shipment_number }} · Tervverzió: {{ $shipment->version }} · Nyomtatva: {{ now()->format('Y.m.d H:i') }}</p>
<h1>Rakodási terv · {{ $shipment->shipment_number }}</h1><p><strong>{{ $shipment->reference }}</strong> · {{ \App\Models\Shipment::STATUSES[$shipment->status] }}</p>
<p>Induló raktár: {{ $shipment->origin->warehouse_name }}@if($options['show_addresses'] && $shipment->origin->location)<br>{{ $shipment->origin->location }}@endif<br>Jármű: {{ $shipment->vehicle_label ?? 'Nincs megadva' }}</p>
@if($shipment->status === 'draft')<p><strong>TERVEZET — készletlevonás még nem történt.</strong></p>@endif
@if($options['show_route'])<h2>Lerakodási útvonal</h2><p>@foreach($shipment->stops as $stop){{ $stop->position }}. {{ $stop->warehouse->warehouse_name }}{{ !$loop->last ? ' → ' : '' }}@endforeach</p>@endif
<h2>Felrakodás: az alábbi sorrendben</h2><p class="note">Elsőként a legbelső rakományt helyezd fel. Az első megálló áruja kerül utoljára, a hátsó ajtóhoz közel. Megállónkénti sorrendterv; nem méret- vagy tömegalapú térbeli elhelyezési terv.</p>
@foreach($shipment->stops->reverse()->values() as $index => $stop)<div class="stop"><h2>{{ $index + 1 }}. felrakodás · {{ $stop->warehouse->warehouse_name }}</h2><p>{{ $stop->position }}. lerakodási megálló@if($options['show_addresses'] && $stop->warehouse->location) · {{ $stop->warehouse->location }}@endif{{ $stop->received_at ? ' · Már átvéve' : '' }}</p><table><thead><tr><th>Termék</th>@if($options['show_sku'])<th>SKU</th>@endif<th>Mennyiség</th>@if($options['show_loading_column'])<th class="loading-check">Rakodva</th>@endif</tr></thead><tbody>@foreach($stop->items as $item)<tr><td>{{ $item->product->product_name }}</td>@if($options['show_sku'])<td>{{ $item->product->sku }}</td>@endif<td>{{ number_format($item->quantity, 0, ',', ' ') }} db</td>@if($options['show_loading_column'])<td class="loading-check"></td>@endif</tr>@endforeach</tbody></table>
@if($loop->last)
    @if($options['show_note'] && $shipment->note)<p style="white-space: pre-wrap;">Szállítmány megjegyzése: {{ $shipment->note }}</p>@endif
    @if($options['show_signatures'])<table class="signatures"><tr><td><div class="signature-line">Rakodást végezte</div></td><td><div class="signature-line">Ellenőrizte</div></td></tr></table>@endif
@endif
</div>@endforeach
</body></html>
