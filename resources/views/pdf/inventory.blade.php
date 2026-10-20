<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Hivatalos Leltárív</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #333; }
        h1 { text-align: center; color: #1a202c; border-bottom: 2px solid #cbd5e0; padding-bottom: 8px; margin-bottom: 5px; }
        .subtitle { text-align: center; color: #718096; font-size: 12px; margin-bottom: 10px; font-weight: bold; }
        .date { text-align: right; margin-bottom: 15px; font-style: italic; color: #4a5568; }
        .filter-box { background-color: #f7fafc; border: 1px dashed #cbd5e0; padding: 8px; margin-bottom: 15px; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #edf2f7; font-weight: bold; text-align: left; padding: 6px; border: 1px solid #cbd5e0; }
        td { padding: 6px; border: 1px solid #cbd5e0; vertical-align: top; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row { font-weight: bold; background-color: #edf2f7; }
        .count-box { border-bottom: 1px dotted #718096; display: inline-block; width: 70px; height: 14px; }
        .signatures { margin-top: 50px; width: 100%; }
        .signatures td { border: none; text-align: center; padding-top: 40px; }
    </style>
</head>
<body>

    <h1>Hivatalos Raktári Leltárív</h1>
    @if($options['blind_mode'])
        <div class="subtitle">🔒 VAKLELTÁR MÓD (Rendszerbeli mennyiségek elrejtve az ellenőrzéshez)</div>
    @endif
    <div class="date">Kiállítva: {{ date('Y. m. d. H:i') }}</div>

    @if($options['show_filters'])
        <div class="filter-box">
            @if(!empty($filterInfo))
                <strong>Alkalmazott szűrők (Részleges leltár):</strong> {{ implode(' | ', $filterInfo) }}
            @else
                <strong>Lekérdezés típusa:</strong> Teljes raktárkészlet (szűrés nélkül)
            @endif
        </div>
    @endif

    @php
        // Kiszámoljuk, hány alap oszlop van az "Összesen" sor colspan-jához
        $baseCols = 1; // A Termék megnevezése mindig látszik
        if($options['show_sku']) $baseCols++;
        if($options['show_category']) $baseCols++;
        if($options['show_warehouses']) $baseCols++;
    @endphp

    <table>
        <thead>
            <tr>
                @if($options['show_sku']) <th>Cikkszám</th> @endif
                <th>Termék megnevezése</th>
                @if($options['show_category']) <th>Kategória</th> @endif
                @if($options['show_warehouses']) <th>Raktári eloszlás</th> @endif
                @if(!$options['blind_mode'])
                    <th class="text-right">Rendszer szerint</th>
                @endif
                @if($options['show_count_col'] || $options['blind_mode'])
                    <th class="text-center" style="width: 110px;">Számolt (Tényleges)</th>
                @endif
                @if($options['show_prices'] && !$options['blind_mode'])
                    <th class="text-right">Egységár</th>
                    <th class="text-right">Összérték</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @php 
                $grandTotalQty = 0; 
                $grandTotalValue = 0;
            @endphp
            
            @foreach($products as $product)
                @php
                    $totalStock = $product->stocks->sum('quantity');
                    $totalValue = $totalStock * $product->price;
                    $grandTotalQty += $totalStock;
                    $grandTotalValue += $totalValue;
                @endphp
                
                <tr>
                    @if($options['show_sku']) <td>{{ $product->sku }}</td> @endif
                    <td><strong>{{ $product->product_name }}</strong></td>
                    @if($options['show_category']) <td>{{ optional($product->category)->category_name ?? '-' }}</td> @endif
                    
                    @if($options['show_warehouses'])
                        <td>
                            @forelse($product->stocks as $stock)
                                {{ $stock->warehouse->warehouse_name }}: 
                                {{ $options['blind_mode'] ? '___ db' : $stock->quantity . ' db' }}<br>
                            @empty
                                <em>Nincs készleten</em>
                            @endforelse
                        </td>
                    @endif

                    @if(!$options['blind_mode'])
                        <td class="text-right"><strong>{{ $totalStock }} db</strong></td>
                    @endif

                    @if($options['show_count_col'] || $options['blind_mode'])
                        <td class="text-center"><span class="count-box"></span> db</td>
                    @endif
                    
                    @if($options['show_prices'] && !$options['blind_mode'])
                        <td class="text-right">{{ number_format($product->price, 0, ',', ' ') }} Ft</td>
                        <td class="text-right"><strong>{{ number_format($totalValue, 0, ',', ' ') }} Ft</strong></td>
                    @endif
                </tr>
            @endforeach

            @if(!$options['blind_mode'])
                <tr class="total-row">
                    <td colspan="{{ $baseCols }}" class="text-right">Mindösszesen:</td>
                    <td class="text-right">{{ $grandTotalQty }} db</td>
                    @if($options['show_count_col']) <td></td> @endif
                    @if($options['show_prices'])
                        <td></td>
                        <td class="text-right">{{ number_format($grandTotalValue, 0, ',', ' ') }} Ft</td>
                    @endif
                </tr>
            @endif
        </tbody>
    </table>

    @if($options['show_signatures'])
        <table class="signatures">
            <tr>
                <td>___________________________<br>Leltározást végezte</td>
                <td>___________________________<br>Raktárvezető jóváhagyása</td>
            </tr>
        </table>
    @endif

</body>
</html>