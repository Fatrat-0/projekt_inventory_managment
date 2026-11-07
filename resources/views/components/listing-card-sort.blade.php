@props(['sorts', 'filters', 'form'])
<details data-listing-card-sort="block" class="rounded-lg border border-gray-200 bg-white p-3 text-sm">
    <summary class="cursor-pointer font-medium text-gray-700">Rendezés: {{ $sorts[$filters['sort_by']] }} {{ $filters['sort_dir'] === 'asc' ? '↑' : '↓' }}</summary>
    <div class="mt-2 flex flex-wrap gap-2">
        @foreach($sorts as $key => $label)
            @php($direction = $filters['sort_by'] === $key && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc')
            <a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => $key, 'sort_dir' => $direction]) }}" @if($filters['sort_by'] === $key) aria-current="true" @endif
               @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey) { $event.preventDefault(); const form = document.getElementById('{{ $form }}'); form.elements.namedItem('sort_by').value = '{{ $key }}'; form.elements.namedItem('sort_dir').value = '{{ $direction }}'; form.requestSubmit(); }"
               class="rounded-md border px-2 py-2 {{ $filters['sort_by'] === $key ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50' }}">{{ $label }} {{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? '↑' : '↓') : '↕' }}</a>
        @endforeach
    </div>
</details>
