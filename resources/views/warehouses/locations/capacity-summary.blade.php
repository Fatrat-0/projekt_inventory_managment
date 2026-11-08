@php($kg = \App\Models\WarehouseLocation::formatWeight(...))
<dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm sm:grid-cols-4">
    <div><dt class="text-xs text-gray-600">Tárolt áru</dt><dd class="font-semibold">{{ $data['units'] }} db · {{ $kg($data['occupied']) }} kg</dd></div>
    <div><dt class="text-xs text-gray-600">Lefoglalt teherbírás</dt><dd class="font-semibold">{{ $kg($data['reserved']) }} kg</dd></div>
    <div><dt class="text-xs text-gray-600">Szabad teherbírás</dt><dd class="font-semibold">{{ $kg($data['free']) }} kg</dd></div>
    <div><dt class="text-xs text-gray-600">Terhelési határ</dt><dd class="font-semibold">{{ $kg($data['limit']) }} kg</dd></div>
</dl>
@if($data['maxSlots'] !== null)
    <p class="text-xs text-gray-700">Raklaphelyek · {{ $data['occupiedSlots'] }} használt · {{ $data['reservedSlots'] }} lefoglalt · {{ $data['freeSlots'] }} szabad / {{ $data['maxSlots'] }} hely</p>
@endif
