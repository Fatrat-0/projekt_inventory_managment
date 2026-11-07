@props(['title', 'description' => null, 'count' => null, 'unit' => 'találat'])
<header data-page-toolbar class="flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
        <h1 class="dashboard-text flex flex-wrap items-center gap-2 text-xl font-bold text-gray-900">{{ $title }}@if($count !== null) <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium tabular-nums text-gray-600">{{ number_format($count, 0, ',', ' ') }} {{ $unit }}</span>@endif</h1>
        @if($description)<p class="dashboard-text mt-1 text-sm text-gray-500">{{ $description }}</p>@endif
    </div>
    @if(!$slot->isEmpty())<div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>@endif
</header>
