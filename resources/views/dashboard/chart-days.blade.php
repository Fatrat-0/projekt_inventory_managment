<div class="absolute inset-0 z-10 grid" style="grid-template-columns: repeat({{ $dailyActivity->count() }}, minmax(0, 1fr))" x-ref="targets">
    @foreach($dailyActivity as $index => $day)
        @php
            $description = $day['date'].': Bejövő: '.number_format($day['in_count'], 0, ',', ' ').' művelet, '.number_format($day['in_quantity'], 0, ',', ' ').' db. Kimenő: '.number_format($day['out_count'], 0, ',', ' ').' művelet, '.number_format($day['out_quantity'], 0, ',', ' ').' db. Napi mozgásnapló megnyitása.';
        @endphp
        <a href="{{ route('movements.index', ['date_from' => $day['date'], 'date_to' => $day['date']]) }}" data-activity-day="{{ $day['date'] }}" aria-label="{{ $description }}" title="{{ $description }}" :title="null"
            :tabindex="selectedIndex === {{ $index }} ? 0 : -1" :aria-describedby="activeIndex === {{ $index }} ? 'activity-day-tooltip' : null"
            @pointerenter="hover({{ $index }}, $event)" @focus="focus({{ $index }})" @blur="focusedIndex = null"
            @keydown.arrow-right.prevent="focusDay({{ $index + 1 }})" @keydown.arrow-left.prevent="focusDay({{ $index - 1 }})"
            @keydown.home.prevent="focusDay(0)" @keydown.end.prevent="focusDay({{ $dailyActivity->count() - 1 }})"
            class="block min-w-0 rounded-sm outline-none hover:bg-indigo-500/10 focus-visible:bg-indigo-500/10 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-600"></a>
    @endforeach
</div>
<div id="activity-day-tooltip" x-ref="tooltip" role="tooltip" x-show="activeIndex !== null" style="display: none;" :style="{ left: left + 'px', top: top + 'px' }" class="absolute z-40 w-64 max-w-full py-2">
    <div class="space-y-2 rounded-lg bg-gray-900 p-3 text-xs leading-relaxed text-white shadow-lg">
        <p class="font-semibold" x-text="activeDay ? activeDay.date.replaceAll('-', '.') + '.' : ''"></p>
        <p class="break-words"><span class="font-medium text-emerald-300">Bejövő:</span> <span x-text="format(activeDay?.in_count) + ' művelet · ' + format(activeDay?.in_quantity) + ' db'"></span></p>
        <p class="break-words"><span class="font-medium text-indigo-300">Kimenő:</span> <span x-text="format(activeDay?.out_count) + ' művelet · ' + format(activeDay?.out_quantity) + ' db'"></span></p>
        <p class="border-t border-gray-700 pt-2 text-gray-300">Kattints a napra a mozgásnaplóhoz.</p>
    </div>
</div>
