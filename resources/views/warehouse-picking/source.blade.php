<div class="flex flex-wrap justify-between gap-x-3 gap-y-1">
    <div class="min-w-0 [overflow-wrap:anywhere]">
        <p class="text-sm font-semibold text-gray-900" x-text="sourceName(source)"></p>
        <p x-show="source.position_label || (source.code && source.name)" class="text-xs text-gray-600" x-text="source.position_label || source.name"></p>
    </div>
    <span class="self-start rounded-md px-2 py-1 text-xs font-medium" :class="source.active && source.available_quantity > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-800'"
          x-text="source.active ? (source.available_quantity > 0 ? 'Használható' : 'Nincs kiadható készlet') : 'Nem használható'"></span>
</div>
<p class="mt-1 text-xs text-gray-700">Tárolva <span class="font-semibold" x-text="quantity(source.stored_quantity)"></span><span aria-hidden="true"> · </span> Kivehető <span class="font-semibold" x-text="quantity(source.available_quantity)"></span></p>
<p x-show="source.load_requires_verification" class="mt-1 text-xs font-medium text-amber-900">A rekesz terhelése ellenőrzésre vár.</p>
<a x-show="source.plan_url" :href="source.plan_url" target="_blank" rel="noopener" class="{{ $link }} mt-1 inline-flex min-h-10 items-center">Megtekintés az alaprajzon<span class="sr-only"> (új lapon)</span></a>
