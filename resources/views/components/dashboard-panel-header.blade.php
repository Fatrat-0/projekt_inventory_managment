@props(['id', 'title', 'href', 'linkLabel', 'description', 'count' => null])
<header data-panel-header="{{ $id }}" class="relative border-b border-gray-200 bg-gray-50 p-5 transition-colors hover:bg-gray-100 md:hover:bg-gray-50">
    <div class="flex items-start justify-between gap-3">
        <h2 id="{{ $id }}" tabindex="-1" class="min-w-0 flex-1 text-lg font-bold text-gray-900">
            <a href="{{ $href }}" title="{{ $linkLabel }}" data-panel-title-link class="flex min-h-9 items-center justify-between gap-3 rounded-sm hover:text-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 after:absolute after:inset-0 after:content-[''] md:justify-start md:after:hidden">
                <span>{{ $title }}@if($count !== null) <span class="inline-flex rounded-full border border-gray-200 bg-white px-2 py-0.5 align-middle text-xs font-semibold tabular-nums text-gray-600">({{ number_format($count, 0, ',', ' ') }})</span>@endif</span>
                <span aria-hidden="true" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-gray-300 bg-white text-base font-semibold text-indigo-700 md:hidden">→</span>
            </a>
        </h2>
        <a href="{{ $href }}" data-panel-page-link class="hidden shrink-0 rounded-sm py-2 text-sm font-medium text-indigo-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 md:inline-flex md:items-center md:gap-1">{{ $linkLabel }} <span aria-hidden="true">→</span></a>
    </div>
    <p class="mt-1 text-xs text-gray-500">{{ $description }}</p>
</header>
