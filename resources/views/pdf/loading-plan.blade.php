<!doctype html><html lang="hu"><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #253047; } h1 { font-size: 19px; } h2 { font-size: 13px; margin-top: 20px; } table { border-collapse: collapse; width: 100%; } td, th { text-align: left; padding: 7px; border: 1px solid #d7dce5; } th { background: #eef1f8; } .stop { page-break-inside: avoid; margin-bottom: 12px; } .note { color: #596579; } .footer { position: fixed; bottom: -10px; font-size: 9px; color: #596579; }
</style></head><body>
<h1>Rakodási terv · {{ $shipment->shipment_number }}</h1><p><strong>{{ $shipment->reference }}</strong> · {{ \App\Models\Shipment::STATUSES[$shipment->status] }}</p>
<p>Induló raktár: {{ $shipment->origin->warehouse_name }}<br>Jármű: {{ $shipment->vehicle_label ?? 'Nincs megadva' }}</p>
@if($shipment->status === 'draft')<p><strong>TERVEZET — készletlevonás még nem történt.</strong></p>@endif
<h2>Lerakodási útvonal</h2><p>@foreach($shipment->stops as $stop){{ $stop->position }}. {{ $stop->warehouse->warehouse_name }}{{ !$loop->last ? ' → ' : '' }}@endforeach</p>
<h2>Felrakodás: az alábbi sorrendben</h2><p class="note">Elsőként a legbelső rakományt helyezd fel. Az első megálló áruja kerül utoljára, a hátsó ajtóhoz közel. Megállónkénti sorrendterv; nem méret- vagy tömegalapú térbeli elhelyezési terv.</p>
@foreach($shipment->stops->reverse()->values() as $index => $stop)<div class="stop"><h2>{{ $index + 1 }}. felrakodás · {{ $stop->warehouse->warehouse_name }}</h2><p>{{ $stop->position }}. lerakodási megálló · {{ $stop->warehouse->location }}{{ $stop->received_at ? ' · Már átvéve' : '' }}</p><table><thead><tr><th>Termék</th><th>SKU</th><th>Mennyiség</th></tr></thead><tbody>@foreach($stop->items as $item)<tr><td>{{ $item->product->product_name }}</td><td>{{ $item->product->sku }}</td><td>{{ number_format($item->quantity, 0, ',', ' ') }} db</td></tr>@endforeach</tbody></table></div>@endforeach
@if($shipment->note)<p>Megjegyzés: {{ $shipment->note }}</p>@endif
<p class="footer">{{ $shipment->shipment_number }} · Tervverzió: {{ $shipment->version }} · Nyomtatva: {{ now()->format('Y.m.d H:i') }}</p>
</body></html>
