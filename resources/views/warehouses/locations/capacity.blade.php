@php($kg = \App\Models\WarehouseLocation::formatWeight(...))
<dl class="grid grid-cols-3 gap-2 text-sm">
    <div><dt class="text-xs text-gray-500">Tárhelyen</dt><dd class="font-semibold">{{ $kg((int) $location->occupied_weight) }} kg</dd></div>
    <div><dt class="text-xs text-gray-500">Lefoglalva</dt><dd class="font-semibold">{{ $kg((int) $location->reserved_weight) }} kg</dd></div>
    <div><dt class="text-xs text-gray-500">Szabad</dt><dd class="font-semibold">{{ $kg($location->active ? ($sharedFreeWeight[$location->id] ?? $location->freeWeight()) : 0) }} kg</dd></div>
</dl>
<p class="text-xs text-gray-500">Terhelési határ {{ $kg($location->max_weight_grams) }} kg.</p>
@if($location->system_id)
    <a href="{{ route('warehouses.floor-plan.show', $params + ['system' => $location->system_id]) }}" class="text-xs font-medium text-indigo-700 hover:underline">Tárolórendszer a tervrajzon</a>
@endif
@if($location->max_pallet_slots !== null)
    <p class="text-sm text-gray-700"><strong>{{ $location->freeSlots() }} / {{ $location->max_pallet_slots }} szabad raklaphely</strong> · {{ (int) $location->occupied_slots }} használt · {{ (int) $location->reserved_slots }} lefoglalt</p>
@endif
