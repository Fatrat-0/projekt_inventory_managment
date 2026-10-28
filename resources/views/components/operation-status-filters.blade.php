@props(['statuses', 'counts', 'filters', 'route'])
<nav aria-label="Állapotgyorsszűrők" class="grid grid-cols-2 gap-3 {{ count($statuses) === 4 ? 'lg:grid-cols-5' : 'lg:grid-cols-4' }}">
    @foreach(['' => 'Összes'] + $statuses as $key => $label)
        @php($active = ($filters['status'] ?? '') === $key || ($key === '' && empty($filters['status'])))
        <a href="{{ route($route, array_replace($filters, ['status' => $key ?: null])) }}" @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey) { $event.preventDefault(); $refs.status.value = '{{ $key }}'; $refs.filtersForm.requestSubmit(); }" @if($active) aria-current="true" @endif class="rounded-lg border p-4 {{ $active ? 'border-indigo-300 bg-indigo-50 text-indigo-900' : 'border-gray-200 bg-white text-gray-600 hover:border-indigo-200' }}">
            <span class="block text-xs font-medium">{{ $label }}</span><strong class="mt-1 block text-xl font-semibold">{{ number_format($key === '' ? array_sum($counts) : ($counts[$key] ?? 0), 0, ',', ' ') }}</strong>
        </a>
    @endforeach
</nav>
