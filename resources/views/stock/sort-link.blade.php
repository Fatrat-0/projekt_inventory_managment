@php($nextDirection = $filters['sort_by'] === $key && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc')
<a href="{{ route('stock.index', array_replace($filters, ['sort_by' => $key, 'sort_dir' => $nextDirection])) }}"
    onclick="if (!event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) { event.preventDefault(); const form = document.getElementById('stock-filters'); form.elements.namedItem('sort_by').value = '{{ $key }}'; form.elements.namedItem('sort_dir').value = '{{ $nextDirection }}'; form.requestSubmit(); }"
    title="{{ $nextDirection === 'asc' ? 'Növekvő rendezés' : 'Csökkenő rendezés' }}" class="inline-flex min-h-10 items-center gap-1 rounded px-2 py-1 hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500 {{ ($mobile ?? false) ? 'border border-gray-200 text-sm' : 'whitespace-nowrap' }}">
    {{ $label }} <span aria-hidden="true">{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? '↑' : '↓') : '↕' }}</span>
</a>
