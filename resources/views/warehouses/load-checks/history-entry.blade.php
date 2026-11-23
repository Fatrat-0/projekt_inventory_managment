<dl class="mt-3 grid min-w-0 gap-3 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
    <div><dt class="text-xs text-gray-600">Raktár és akkori rekeszkód</dt><dd>{{ $event['warehouse_name'] }} · {{ $event['location_code'] }}</dd></div>
    <div><dt class="text-xs text-gray-600">Termék</dt><dd>{{ $event['product_name'] }} · {{ $event['sku'] }} · {{ $event['quantity'] }} db</dd></div>
    <div><dt class="text-xs text-gray-600">Végrehajtó</dt><dd>{{ $event['actor_name'] }}</dd></div>
    <div><dt class="text-xs text-gray-600">Bruttó tömeg (korábbi → mért)</dt><dd>{{ $kg($event['previous_weight_grams']) }} → {{ $kg($event['measured_weight_grams']) }}</dd></div>
    <div><dt class="text-xs text-gray-600">Raklaphelyigény (korábbi → mért)</dt><dd>{{ $slots($event['previous_pallet_slots']) }} → {{ $slots($event['measured_pallet_slots']) }}</dd></div>
    @if($event['note'] || $event['reason'])
        <div><dt class="text-xs text-gray-600">Megjegyzés</dt><dd>{{ implode(' · ', array_filter([$event['reason'], $event['note']], fn ($text) => $text !== null && $text !== '')) }}</dd></div>
    @endif
    @if($event['capacity_violations'])
        <div><dt class="text-xs text-gray-600">Érintett korlátok</dt><dd>{{ implode(' · ', array_intersect_key($ruleNames, array_flip($event['capacity_violations']))) }}</dd></div>
        <div><dt class="text-xs text-gray-600">Rendezés</dt><dd>
            @if($event['resolution'])
                {{ $event['resolution']['method'] === 'confirmed_empty' ? 'Üres tétel igazolva' : 'Újraméréssel lezárva' }} ·
                {{ $date($event['resolution']['measured_at']) }} · {{ $event['resolution']['actor_name'] }}
                · {{ $kg($event['resolution']['measured_weight_grams']) }} · {{ $slots($event['resolution']['measured_pallet_slots']) }}
            @else
                Rendezésre vár
            @endif
        </dd></div>
    @endif
</dl>
@if($event['errors'])
    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-900">
        @foreach(collect($event['errors'])->flatten() as $message)<li>{{ $message }}</li>@endforeach
    </ul>
@endif
