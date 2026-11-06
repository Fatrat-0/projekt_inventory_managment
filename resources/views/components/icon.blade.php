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
    'layers' => 'm12 3 10 5-10 5L2 8l10-5z M2 12l10 5 10-5 M2 16l10 5 10-5',
    'activity' => 'M4 7h16m-4-4 4 4-4 4 M20 17H4m4-4-4 4 4 4',
    'truck' => 'M3 5h12v12H3z M15 10h4l3 4v3h-7 M7 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4 M18 20a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
    'warehouse' => 'M3 21V8l9-5 9 5v13H3z M7 21V11h10v10 M7 15h10 M7 18h10',
    'contacts' => 'M4 4h16v17H4z M8 2v4 M16 2v4 M12 13a2 2 0 1 0 0-4 2 2 0 0 0 0 4 M8 18a4 4 0 0 1 8 0',
    'users' => 'M9 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M2 21v-2a7 7 0 0 1 14 0v2 M17 4a4 4 0 0 1 0 8 M22 21v-2a7 7 0 0 0-3-5',
    'menu' => 'M4 6h16 M4 12h16 M4 18h16',
    'sidebar-close' => 'M3 4h18v16H3z M9 4v16 m7-5-3-3 3-3',
    'close' => 'm6 6 12 12 M18 6 6 18',
    'chevron-down' => 'm6 9 6 6 6-6',
    'chevron-right' => 'm9 5 7 7-7 7',
    'user' => 'M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M4 22v-1a8 8 0 0 1 16 0v1',
];
@endphp
<svg {{ $attributes->merge(['class' => 'w-4 h-4 shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['box'] }}" /></svg>
