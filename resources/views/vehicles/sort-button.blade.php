<button type="button" @click="setSort(@js($key))"
        @if ($mobile) :aria-pressed="sortBy === @js($key)" @endif
        :title="sortBy === @js($key) && sortDirection === 'ascending' ? 'Csökkenő rendezés' : 'Növekvő rendezés'"
        :class="{ 'text-indigo-700': sortBy === @js($key) }"
        @class(['inline-flex min-h-10 max-w-full items-center gap-1 rounded px-1 py-1 text-left font-medium hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500', 'border border-gray-200 px-2 text-sm' => $mobile])>
    <span class="min-w-0 [overflow-wrap:anywhere]">{{ $label }}</span>
    <span class="shrink-0" aria-hidden="true" x-text="sortArrow(@js($key))"></span>
</button>
