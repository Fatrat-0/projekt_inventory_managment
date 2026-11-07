{{ $movement->reference !== null && $movement->reference !== '' ? $movement->reference : '—' }}
@if($movement->isReturn())
    <a href="{{ route('movements.show', ['movement' => $movement->return_of_id, 'context' => $listContext]) }}" class="mt-1 block text-xs font-medium text-amber-800 underline">Visszáru · {{ $movement->original_movement_number }}</a>
    <p class="mt-1 text-xs text-gray-500">{{ $movement->return_reason }}</p>
@endif
@if($movement->isScrap())
    <a href="{{ route('movements.show', ['movement' => $movement->scrap_of_id, 'context' => $listContext]) }}" class="mt-1 block text-xs font-medium text-red-800 underline">Selejtezés · Kapcsolódó visszáru</a>
    <p class="mt-1 text-xs text-gray-500">{{ $movement->qualityAction?->reason }}</p>
@endif
@if($movement->partner_id)<a href="{{ route('partners.edit', $movement->partner_id) }}" class="mt-1 block text-xs text-indigo-600 hover:underline">Partner: {{ $movement->partner_name }}@if($movement->partner?->isArchived()) · Archivált @endif</a>@endif
