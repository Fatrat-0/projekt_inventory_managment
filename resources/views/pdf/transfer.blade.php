<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Raktárközi Szállítólevél</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; line-height: 1.4; }
        .header-table { width: 100%; border-bottom: 2px solid #2d3748; padding-bottom: 10px; margin-bottom: 20px; }
        .title { font-size: 20px; font-weight: bold; color: #1a202c; }
        .doc-number { text-align: right; font-size: 14px; color: #4a5568; }
        .warehouses-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .warehouse-box { width: 48%; background-color: #f7fafc; border: 1px solid #cbd5e0; padding: 12px; vertical-align: top; }
        .warehouse-title { font-size: 10px; text-transform: uppercase; color: #718096; font-weight: bold; margin-bottom: 5px; }
        .warehouse-name { font-size: 15px; font-weight: bold; color: #2d3748; }
        .status-banner { padding: 8px 12px; margin-bottom: 20px; border: 1px solid #cbd5e0; background-color: #edf2f7; font-weight: bold; text-align: center; }
        .items-table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 30px; }
        .items-table th { background-color: #2d3748; color: #ffffff; padding: 8px; text-align: left; border: 1px solid #2d3748; }
        .items-table td { padding: 10px 8px; border: 1px solid #cbd5e0; }
        .text-right { text-align: right; }
        .signatures { margin-top: 70px; width: 100%; border-collapse: collapse; }
        .signatures td { width: 33%; text-align: center; padding-top: 40px; font-size: 11px; }
        .note { font-size: 10px; color: #718096; border-top: 1px dashed #cbd5e0; padding-top: 10px; margin-top: 40px; }
    </style>
</head>
<body>

    <table class="header-table">
        <tr>
            <td>
                <div class="title">RAKTÁRKÖZI SZÁLLÍTÓLEVÉL</div>
                <div>Belső készletátmozgatási és átadás-átvételi bizonylat</div>
            </td>
            <td class="doc-number">
                <strong>Bizonylatszám:</strong> #TR-{{ str_pad($transfer->getKey(), 5, '0', STR_PAD_LEFT) }}<br>
                <strong>Indítás dátuma:</strong> {{ $transfer->created_at ? $transfer->created_at->format('Y. m. d. H:i') : date('Y. m. d.') }}
            </td>
        </tr>
    </table>

    <div class="status-banner">
        Szállítmány státusza a nyomtatáskor:
        @if($transfer->status === 'pending')
            🚚 ÚTON / SZÁLLÍTÁS ALATT (Átvételre vár)
        @elseif($transfer->status === 'completed')
            ✅ TELJESÍTVE (A célraktárban átvéve és készletre véve)
        @elseif($transfer->status === 'cancelled')
            ❌ VISSZAVONVA (A készlet visszavezetve a forrásraktárba)
        @endif
    </div>

    <table class="warehouses-table">
        <tr>
            <td class="warehouse-box">
                <div class="warehouse-title">📤 KIADÓ (FORRÁS) RAKTÁR</div>
                <div class="warehouse-name">{{ optional($transfer->fromWarehouse)->warehouse_name ?? '-' }}</div>
                <div>Cím / Helyszín: {{ optional($transfer->fromWarehouse)->location ?? 'Nincs megadva' }}</div>
            </td>
            <td style="width: 4%; text-align: center; vertical-align: middle; font-size: 20px;">
                ➔
            </td>
            <td class="warehouse-box">
                <div class="warehouse-title">📥 ÁTVEVŐ (CÉL) RAKTÁR</div>
                <div class="warehouse-name">{{ optional($transfer->toWarehouse)->warehouse_name ?? '-' }}</div>
                <div>Cím / Helyszín: {{ optional($transfer->toWarehouse)->location ?? 'Nincs megadva' }}</div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Cikkszám (SKU)</th>
                <th>Termék megnevezése</th>
                <th>Kategória</th>
                <th class="text-right">Szállított mennyiség</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>{{ optional($transfer->product)->sku ?? '-' }}</strong></td>
                <td>{{ optional($transfer->product)->product_name ?? 'Törölt termék' }}</td>
                <td>{{ optional(optional($transfer->product)->category)->category_name ?? '-' }}</td>
                <td class="text-right" style="font-size: 14px;"><strong>{{ $transfer->quantity }} db</strong></td>
            </tr>
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td>
                ___________________________<br>
                <strong>Kiadta</strong><br>
                ({{ optional($transfer->fromWarehouse)->warehouse_name }})
            </td>
            <td>
                ___________________________<br>
                <strong>Szállító / Fuvarozó</strong><br>
                (Árut szállításra átvette)
            </td>
            <td>
                ___________________________<br>
                <strong>Átvette</strong><br>
                ({{ optional($transfer->toWarehouse)->warehouse_name }})
            </td>
        </tr>
    </table>

    <div class="note">
        Megjegyzés: A célraktárban történő mennyiségi és minőségi átvételt követően a szállítmányt az informatikai rendszerben is „Teljesítve” státuszra kell állítani.
    </div>

</body>
</html>