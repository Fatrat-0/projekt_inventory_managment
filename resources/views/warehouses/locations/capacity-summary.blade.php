@php
    $kg = \App\Models\WarehouseLocation::formatWeight(...);
    $held = $data['loadDiscrepancy'] ?? false;
@endphp
@if($held)
    <p @class(['flex items-start gap-2 text-sm font-medium', 'text-rose-900' => $data['loadCritical'], 'text-amber-950' => !$data['loadCritical']])>
        <x-icon name="warning" />
        <span>{{ $data['loadCritical'] ? 'Kritikus terhelési eltérés. ' : '' }}További elhelyezésre nem használható – nyitott terhelési eltérés miatt.</span>
    </p>
@endif
<dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm sm:grid-cols-4">
    <div><dt class="text-xs text-gray-600">{{ $held ? 'Tárolt áru · figyelembe vett tömeg' : 'Tárolt áru' }}</dt><dd class="font-semibold">{{ $data['units'] }} db · {{ $kg($data['occupied']) }} kg</dd></div>
    <div><dt class="text-xs text-gray-600">Lefoglalt teherbírás</dt><dd class="font-semibold">{{ $kg($data['reserved']) }} kg</dd></div>
    <div><dt class="text-xs text-gray-600">{{ $held ? 'Új elhelyezésre használható' : 'Szabad teherbírás' }}</dt><dd class="font-semibold">{{ $kg($data['free']) }} kg</dd>
        @if($held)<dd class="text-xs text-gray-700">Számított kapacitásmaradék {{ $kg($data['remaining']) }} kg</dd>@endif
    </div>
    <div><dt class="text-xs text-gray-600">Terhelési határ</dt><dd class="font-semibold">{{ $kg($data['limit']) }} kg</dd></div>
</dl>
@if($data['maxSlots'] !== null)
    <p class="text-xs text-gray-700">Raklaphelyek · {{ $data['occupiedSlots'] }} használt · {{ $data['reservedSlots'] }} lefoglalt · {{ $data['freeSlots'] }} {{ $held ? 'új elhelyezésre használható' : 'szabad' }} / {{ $data['maxSlots'] }} hely</p>
@endif
