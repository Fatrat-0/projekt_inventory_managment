<!doctype html>
<html lang="hu"><head><meta charset="UTF-8"><title>Raktári leltárív</title>
<style>
    @page { margin: 28px 28px 48px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #253047; }
    h1 { font-size: 19px; margin: 0 0 8px; }
    p { margin: 5px 0; }
    .warehouse { font-size: 13px; font-weight: bold; }
    .address { white-space: pre-line; color: #596579; }
    .scope { margin: 12px 0; padding: 8px; border: 1px solid #cbd5e1; background: #f4f6fa; }
    .filters { font-size: 9px; color: #596579; margin-bottom: 12px; }
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th, td { border: 1px solid #cbd5e1; padding: 6px; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
    th { background: #eef1f8; text-align: left; font-size: 9px; }
    td { height: 22px; }
    .number { text-align: center; }
    .quantity { text-align: right; }
    .totals { margin-top: 10px; font-size: 10px; }
    .signatures { page-break-inside: avoid; margin-top: 24px; }
    .signatures td { border: 0; width: 50%; text-align: center; padding-top: 28px; }
    .signature-line { border-top: 1px solid #596579; padding-top: 5px; }
    .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8px; color: #596579; }
    .page { float: right; }
    .page:before { content: counter(page); }
    @if($options['orientation'] === 'landscape')
    body { font-size: 9px; } h1 { font-size: 17px; } th, td { padding: 5px; }
    @endif
</style></head><body>
    <div class="footer">Raktár #{{ $warehouse->warehouse_id }} · {{ $exportedAt->format('Y.m.d H:i') }}<span class="page">. oldal</span></div>
    <h1>Raktári leltárív</h1>
    <p class="warehouse">{{ $warehouse->warehouse_name }}</p>
    @if($warehouse->location)<p class="address">{{ $warehouse->location }}</p>@endif
    <p>Kiállítva: {{ $exportedAt->format('Y.m.d H:i') }} · Tételek száma: {{ $stocks->count() }}</p>
    <div class="scope"><strong>Leltár köre:</strong> {{ $partial ? 'Szűrt raktári készletsor-lista (részleges leltár).' : 'Teljes raktári készletsor-lista.' }}<br>A nyomtatvány kizárólag ennek a raktárnak a készletsorait tartalmazza.</div>
    @if($options['blind_mode'])<p><strong>VAKLELTÁR: a rendszer szerinti mennyiségek rejtve vannak.</strong></p>@endif
    @if($options['show_filters'])<div class="filters">{{ implode(' | ', $filterInfo) }}</div>@endif
    @php($columns = 2 + (int)$options['show_sku'] + (int)$options['show_category'] + (int)!$options['blind_mode'] + (int)$options['show_count_column'] + (int)$options['show_note_column'])
    @php($productWidth = 100 - 4 - ($options['show_sku'] ? 16 : 0) - ($options['show_category'] ? 14 : 0) - (!$options['blind_mode'] ? 12 : 0) - ($options['show_count_column'] ? 14 : 0) - ($options['show_note_column'] ? 16 : 0))
    <table><thead><tr><th class="number" style="width: 4%">#</th>@if($options['show_sku'])<th class="sku" style="width: 16%">Cikkszám</th>@endif<th style="width: {{ $productWidth }}%">Termék megnevezése</th>@if($options['show_category'])<th class="category" style="width: 14%">Kategória</th>@endif @if(!$options['blind_mode'])<th class="quantity" style="width: 12%">Helyi készlet (db)</th>@endif @if($options['show_count_column'])<th class="counted" style="width: 14%">Számolt mennyiség (db)</th>@endif @if($options['show_note_column'])<th class="note" style="width: 16%">Megjegyzés</th>@endif</tr></thead>
        <tbody>@forelse($stocks as $stock)<tr><td class="number">{{ $loop->iteration }}</td>@if($options['show_sku'])<td>{{ $stock->product->sku }}</td>@endif<td>{{ $stock->product->product_name }}</td>@if($options['show_category'])<td>{{ $stock->product->category?->category_name ?? '-' }}</td>@endif @if(!$options['blind_mode'])<td class="quantity">{{ number_format($stock->quantity, 0, ',', ' ') }}</td>@endif @if($options['show_count_column'])<td></td>@endif @if($options['show_note_column'])<td></td>@endif</tr>@empty<tr><td colspan="{{ $columns }}">Nincs a szűrésnek megfelelő helyi készletsor.</td></tr>@endforelse</tbody>
    </table>
    @if(!$options['blind_mode'])<p class="totals"><strong>Összes helyi mennyiség: {{ number_format($stocks->sum('quantity'), 0, ',', ' ') }} db</strong></p>@endif
    @if($options['show_signatures'])<table class="signatures"><tr><td><div class="signature-line">Leltározó neve és aláírása</div></td><td><div class="signature-line">Ellenőrző neve és aláírása</div></td></tr></table>@endif
</body></html>
