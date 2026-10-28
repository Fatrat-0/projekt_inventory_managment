<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Készletmozgási Napló</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #333; }
        h1 { text-align: center; color: #1a202c; border-bottom: 2px solid #cbd5e0; padding-bottom: 8px; margin-bottom: 5px; }
        .date { text-align: right; margin-bottom: 15px; font-style: italic; color: #4a5568; }
        .filter-box { background-color: #f7fafc; border: 1px dashed #cbd5e0; padding: 8px; margin-bottom: 15px; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #edf2f7; font-weight: bold; text-align: left; padding: 6px; border: 1px solid #cbd5e0; }
        td { padding: 6px; border: 1px solid #cbd5e0; vertical-align: middle; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .badge-in { color: #22543d; font-weight: bold; }
        .badge-out { color: #742a2a; font-weight: bold; }
        .summary-box { margin-top: 20px; width: 50%; margin-left: auto; }
        .signatures { margin-top: 50px; width: 100%; }
        .signatures td { border: none; text-align: center; padding-top: 40px; }
    </style>
</head>
<body>

    <h1>Raktári Készletmozgási Napló</h1>
    <div class="date">Kiállítva: {{ date('Y. m. d. H:i') }}</div>

    <div class="filter-box">
        <strong>Szűrés típusa:</strong>
        @if($options['type_filter'] === 'in')
            Csak Bevételezések (IN)
        @elseif($options['type_filter'] === 'out')
            Csak Kiadások (OUT)
        @else
            Minden készletmozgás (Bevételezés és Kiadás)
        @endif
    </div>

    @if(!empty($filterInfo))
        <div class="filter-box"><strong>Alkalmazott szűrők:</strong> {{ implode(' | ', $filterInfo) }}</div>
    @endif

    <table>
        <thead>
            <tr>
                <th>Dátum</th>
                <th>Típus</th>
                <th>Termék (Cikkszám)</th>
                @if($options['show_warehouse']) <th>Raktár</th> @endif
                <th class="text-right">Mennyiség</th>
                @if($options['show_reference']) <th>Hivatkozás / Megjegyzés</th> @endif
                @if($options['show_user']) <th>Rögzítette</th> @endif
            </tr>
        </thead>
        <tbody>
            @php
                $totalIn = 0;
                $totalOut = 0;
            @endphp

            @forelse($movements as $movement)
                @php
                    if ($movement->type === 'in') $totalIn += $movement->quantity;
                    if ($movement->type === 'out') $totalOut += $movement->quantity;
                @endphp
                <tr>
                    <td>{{ $movement->created_at ? $movement->created_at->format('Y.m.d H:i') : '-' }}</td>
                    <td class="text-center">
                        @if($movement->type === 'in')
                            <span class="badge-in">▲ BEVÉTEL</span>
                        @else
                            <span class="badge-out">▼ KIADÁS</span>
                        @endif
                    </td>
                    <td>
                        <strong>{{ optional($movement->product)->product_name ?? 'Törölt termék' }}</strong><br>
                        <small style="color: #718096;">{{ optional($movement->product)->sku }}</small>
                    </td>
                    @if($options['show_warehouse'])
                        <td>{{ optional($movement->warehouse)->warehouse_name ?? '-' }}</td>
                    @endif
                    <td class="text-right">
                        <strong>{{ $movement->type === 'in' ? '+' : '-' }}{{ $movement->quantity }} db</strong>
                    </td>
                    @if($options['show_reference'])
                        <td>{{ $movement->reference ?? '-' }}</td>
                    @endif
                    @if($options['show_user'])
                        <td>{{ optional($movement->user)->name ?? 'Rendszer' }}</td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center">Nincs megjeleníthető készletmozgás.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if($options['show_summary'])
        <table class="summary-box">
            <tr>
                <th>Összes bevételezés (+):</th>
                <td class="text-right badge-in">+{{ $totalIn }} db</td>
            </tr>
            <tr>
                <th>Összes kiadás (-):</th>
                <td class="text-right badge-out">-{{ $totalOut }} db</td>
            </tr>
            <tr>
                <th>Forgalmi egyenleg:</th>
                <td class="text-right"><strong>{{ $totalIn - $totalOut }} db</strong></td>
            </tr>
        </table>
    @endif

    @if($options['show_signatures'])
        <table class="signatures">
            <tr>
                <td>___________________________<br>Kimutatást készítette</td>
                <td>___________________________<br>Raktárvezető jóváhagyása</td>
            </tr>
        </table>
    @endif

</body>
</html>