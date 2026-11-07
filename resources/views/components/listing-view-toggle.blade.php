@props(['label' => 'Lista megjelenítése'])
<div x-cloak data-listing-toggle role="group" aria-label="{{ $label }}" class="hidden shrink-0 divide-x divide-gray-200 overflow-hidden rounded-md border border-gray-300 bg-white md:inline-flex">
    @foreach(['table' => ['Táblázatos nézet', 'columns'], 'cards' => ['Kártyás nézet', 'grid']] as $mode => [$title, $icon])
        <button type="button" @click="setView('{{ $mode }}')" :aria-pressed="viewMode === '{{ $mode }}'" :class="viewMode === '{{ $mode }}' ? 'bg-indigo-50 text-indigo-700' : 'text-gray-500 hover:bg-gray-50'" aria-label="{{ $title }}" title="{{ $title }}" class="inline-flex h-11 w-11 items-center justify-center focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500"><x-icon :name="$icon" class="h-5 w-5" /></button>
    @endforeach
</div>
