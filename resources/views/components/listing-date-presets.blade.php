@props(['presets'])
<div class="flex flex-wrap items-center gap-2" role="group" aria-label="Gyors dátumszűrők"><span class="text-xs font-medium text-gray-600">Időszak:</span>
    @foreach($presets as $preset)<a href="{{ $preset['url'] }}" @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey) { $event.preventDefault(); $refs.dateFrom.value = '{{ $preset['from'] }}'; $refs.dateTo.value = '{{ $preset['to'] }}'; $refs.filtersForm.requestSubmit(); }" @if($preset['active']) aria-current="true" @endif class="rounded-md border px-3 py-1.5 text-xs font-medium {{ $preset['active'] ? 'border-indigo-200 bg-indigo-50 text-indigo-800' : 'border-gray-200 bg-white text-gray-600 hover:bg-gray-50' }}">{{ $preset['label'] }}</a>@endforeach
</div>
