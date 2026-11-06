@props(['items', 'kind', 'asOf', 'canManage' => false, 'warehousePeak' => 1])
@php
    $preview = \App\Queries\DashboardOverview::LIST_PREVIEW_LIMIT;
    $rows = $items->getCollection();
    [$label, $itemName, $empty] = match ($kind) {
        'product' => ['Termékhiányok', 'product', 'Nincs beállított minimum alatti termék.'],
        'category' => ['Kategóriahiányok', 'category', 'Nincs kategóriaminimum alatti készlet.'],
        'transfer' => ['Átvételre váró átmozgatások', 'transfer', 'Nincs átvételre váró átmozgatás.'],
        'movement' => ['Legutóbbi készletmozgások', 'movement', 'Még nem történt mozgás a rendszerben.'],
        'warehouse' => ['Raktárankénti készlethelyzet', 'warehouse', 'Még nincs raktár a rendszerben.'],
        'replenishment' => ['Utánpótlási javaslatok', 'replenishment', 'Nincs további pótlást igénylő készletsor a beállított helyi minimumok alapján.'],
    };
@endphp
<div x-data="dashboardList" @resize.window.debounce.100ms="measure()" data-dashboard-list="{{ $kind }}" class="dashboard-list">
    <div class="dashboard-list-preview" role="region" aria-label="{{ $label }} listája">
        <ul>
            @forelse($rows->take($preview) as $item)
                @include('dashboard.'.$kind.'-row', [$itemName => $item])
            @empty
                <li class="p-5 text-sm text-gray-600">{{ $empty }}</li>
            @endforelse
        </ul>
    </div>
    @if($rows->count() > $preview)
        <details x-ref="more" class="dashboard-list-details group" @toggle="$nextTick(() => measure())" @if(request()->has($items->getPageName())) open @endif>
            <summary class="cursor-pointer border-t border-gray-200 bg-gray-50 px-4 py-3 text-sm font-semibold text-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                <span class="group-open:hidden">Több megjelenítése</span><span class="hidden group-open:inline">Kevesebb megjelenítése</span>
            </summary>
            <div x-ref="scroller" tabindex="0" role="region" aria-label="{{ $label }} listája" class="dashboard-list-scroll" :style="maxHeight === null ? {} : { maxHeight: maxHeight + 'px' }">
                <ul>@foreach($rows as $item) @include('dashboard.'.$kind.'-row', [$itemName => $item]) @endforeach</ul>
            </div>
        </details>
    @endif
    @if($items->hasPages())
        <nav aria-label="{{ $label }} lapozása" class="space-y-2 border-t border-gray-100 p-4">
            <p class="text-xs text-gray-500">{{ number_format($items->total(), 0, ',', ' ') }} tétel · {{ $items->currentPage() }}/{{ $items->lastPage() }}. oldal</p>
            <div class="flex flex-wrap justify-between gap-2 text-sm font-medium text-indigo-700">
                @if($items->previousPageUrl())<a rel="prev" href="{{ $items->previousPageUrl() }}" class="rounded-md border border-gray-300 px-3 py-2 hover:bg-gray-50">← Előző oldal</a>@endif
                @if($items->nextPageUrl())<a rel="next" href="{{ $items->nextPageUrl() }}" class="rounded-md border border-gray-300 px-3 py-2 hover:bg-gray-50">Következő oldal →</a>@endif
            </div>
        </nav>
    @endif
</div>
