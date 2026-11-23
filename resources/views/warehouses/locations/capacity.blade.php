@php
    $kg = \App\Models\WarehouseLocation::formatWeight(...);
    $severity = $capacity['discrepancy_severity'] ?? null;
@endphp
<dl class="grid grid-cols-2 gap-2 sm:grid-cols-3 text-sm">
    <div><dt class="text-xs text-gray-600">{{ $severity ? 'Figyelembe vett terhelés' : 'Tárhelyen' }}</dt><dd class="font-semibold">{{ $kg($location->effectiveOccupiedWeight()) }} kg</dd></div>
    <div><dt class="text-xs text-gray-500">Lefoglalva</dt><dd class="font-semibold">{{ $kg((int) $location->reserved_weight) }} kg</dd></div>
    <div><dt class="text-xs text-gray-600">{{ $severity ? 'Új elhelyezésre használható' : 'Szabad' }}</dt><dd class="font-semibold">{{ $kg($location->active ? ($sharedFreeWeight[$location->id] ?? max(0, $location->freeWeight())) : 0) }} kg</dd></div>
</dl>
@if($severity)
    <p @class(['flex items-start gap-2 text-sm font-medium', 'text-rose-900' => $severity === 'critical', 'text-amber-950' => $severity === 'warning'])>
        <x-icon name="warning" />
        <span>{{ $severity === 'critical' ? 'Kritikus terhelési eltérés. ' : '' }}További elhelyezésre nem használható – nyitott terhelési eltérés miatt.</span>
    </p>
    <p class="text-xs text-gray-700">Számított kapacitásmaradék a rekeszben {{ $kg(max(0, $location->remainingWeight())) }} kg.</p>
@endif
<p class="text-xs text-gray-500">Terhelési határ {{ $kg($location->max_weight_grams) }} kg.</p>
@if($location->system_id)
    <a href="{{ route('warehouses.floor-plan.show', $params + ['system' => $location->system_id]) }}" class="text-xs font-medium text-indigo-700 hover:underline">Tárolórendszer a tervrajzon</a>
@endif
@if($location->max_pallet_slots !== null)
    <p class="text-sm text-gray-700"><strong>{{ $capacity['slots'] ?? max(0, $location->freeSlots()) }} / {{ $location->max_pallet_slots }} {{ $severity ? 'új elhelyezésre használható' : 'szabad' }} raklaphely</strong> · {{ $location->effectiveOccupiedSlots() }} használt · {{ (int) $location->reserved_slots }} lefoglalt</p>
@endif
