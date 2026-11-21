@if($stock->direct_issue_quantity === null)
    <span class="font-medium text-amber-800" title="Eltérő készletadatok">Nem számítható</span>
@else
    <strong data-direct-issue>{{ number_format($stock->direct_issue_quantity, 0, ',', ' ') }} db</strong>
    @if((int) $stock->direct_issue_quantity !== (int) $stock->pickable_quantity)
        <p class="mt-0.5 text-xs text-gray-600">Kitárolható összesen {{ number_format($stock->pickable_quantity, 0, ',', ' ') }} db</p>
    @endif
@endif
@php
    $protected = array_filter([
        'Betárolásra vár' => $stock->pending_putaway_quantity,
        'Foglalt' => $stock->source_reserved_quantity,
        'Összekészítve' => $stock->staged_quantity,
        'Elkülönítve' => $stock->quarantined_quantity,
        'Inaktív rekeszben' => $stock->inactive_bin_quantity,
        'Rekeszhez rendelve' => $stock->assigned_quantity,
    ], fn ($quantity) => $quantity > 0);
@endphp
@if($protected)
    <details class="mt-1 text-xs text-gray-600">
        <summary class="cursor-pointer font-medium text-indigo-700">Készletrészletek</summary>
        <dl class="mt-2 space-y-1">
            @foreach($protected as $label => $quantity)
                <div><dt class="inline">{{ $label }}</dt> <dd class="inline font-medium">{{ number_format($quantity, 0, ',', ' ') }} db</dd></div>
            @endforeach
        </dl>
    </details>
@endif
