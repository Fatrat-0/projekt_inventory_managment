<dl class="space-y-1 text-xs text-gray-700">
    <div class="flex flex-wrap justify-between gap-x-2"><dt>Tárolt tömeg</dt><dd x-text="`${kilograms({{ $item }}.occupied)} kg`"></dd></div>
    <div class="flex flex-wrap justify-between gap-x-2"><dt>Lefoglalt teherbírás</dt><dd x-text="`${kilograms({{ $item }}.reserved)} kg`"></dd></div>
    <div class="flex flex-wrap justify-between gap-x-2"><dt>Szabad teherbírás / határ</dt><dd x-text="`${kilograms({{ $item }}.free)} / ${kilograms({{ $item }}.limit)} kg`"></dd></div>
    <div x-show="{{ $item }}.maxSlots !== null" class="flex flex-wrap justify-between gap-x-2"><dt>Raklaphely (tárolt / foglalt / szabad)</dt><dd x-text="[{{ $item }}.occupiedSlots, {{ $item }}.reservedSlots, {{ $item }}.freeSlots].join(' / ')"></dd></div>
</dl>
<p x-show="capacityReason({{ $item }})" x-text="capacityReason({{ $item }})" class="text-xs font-medium text-gray-700"></p>
