@props(['name'])
@php
$paths = [
    'box' => 'M21 8l-9-5-9 5v9l9 5 9-5V8z M3 8l9 5 9-5 M12 13v9 M7.5 5.5l9 5',
    'search' => 'M21 21l-5-5 M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
    'filter' => 'M4 6h16 M7 12h10 M10 18h4',
    'document' => 'M14 2H5v20h14V7l-5-5z M14 2v5h5 M8 12h8 M8 16h8',
    'list' => 'M4 6h16 M4 12h16 M4 18h16',
    'grid' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
    'columns' => 'M3 4h18v16H3z M9 4v16 M15 4v16',
    'folder' => 'M3 5h6l2 3h10v12H3z',
];
@endphp
<svg {{ $attributes->merge(['class' => 'w-4 h-4 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['box'] }}" /></svg>
