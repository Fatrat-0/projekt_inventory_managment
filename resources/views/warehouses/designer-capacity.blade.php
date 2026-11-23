<dl class="space-y-1 text-xs text-gray-700">
    <div class="flex flex-wrap justify-between gap-x-2"><dt x-text="{{ $item }}.loadDiscrepancy ? 'Figyelembe vett tömeg' : 'Tárolt tömeg'"></dt><dd x-text="`${kilograms({{ $item }}.occupied)} kg`"></dd></div>
    <div class="flex flex-wrap justify-between gap-x-2"><dt>Lefoglalt teherbírás</dt><dd x-text="`${kilograms({{ $item }}.reserved)} kg`"></dd></div>
    <div class="flex flex-wrap justify-between gap-x-2"><dt x-text="{{ $item }}.loadDiscrepancy ? 'Új elhelyezésre használható / határ' : 'Szabad teherbírás / határ'"></dt><dd x-text="`${kilograms({{ $item }}.free)} / ${kilograms({{ $item }}.limit)} kg`"></dd></div>
    <div x-show="{{ $item }}.loadDiscrepancy" class="flex flex-wrap justify-between gap-x-2">
        <dt>Számított kapacitásmaradék</dt><dd x-text="kilograms({{ $item }}.remaining) + ' kg'"></dd>
    </div>
    <div x-show="{{ $item }}.maxSlots !== null" class="flex flex-wrap justify-between gap-x-2"><dt x-text="{{ $item }}.loadDiscrepancy ? 'Raklaphely (tárolt / foglalt / használható)' : 'Raklaphely (tárolt / foglalt / szabad)'"></dt><dd x-text="[{{ $item }}.occupiedSlots, {{ $item }}.reservedSlots, {{ $item }}.freeSlots].join(' / ')"></dd></div>
</dl>
<p x-show="capacityReason({{ $item }})" class="flex items-start gap-1 text-xs font-medium"
   :class="{{ $item }}.loadCritical ? 'text-rose-900' : ({{ $item }}.loadDiscrepancy ? 'text-amber-950' : 'text-gray-700')">
    <x-icon name="warning" x-show="{{ $item }}.loadDiscrepancy" />
    <span x-text="capacityReason({{ $item }})"></span>
</p>
