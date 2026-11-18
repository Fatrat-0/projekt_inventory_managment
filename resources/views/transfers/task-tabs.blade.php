@inject('warehouseTaskCounts', \App\Services\WarehouseOpenTaskCounts::class)
@php
    $counts = $warehouseTaskCounts->forUser(request()->user());
    $workTabs = [
        'picking' => ['warehouse-picking-pages', 'Kitárolás'],
        'receiving' => ['warehouse-receiving-pages', 'Átvétel'],
        'putaway' => ['warehouse-putaway-pages', 'Betárolás'],
    ];
    $taskTabsConfig = ['counts' => $counts, 'url' => route('warehouse-task-counts')];
@endphp
<nav aria-label="Raktári feladatok munkatípusai" data-warehouse-task-tabs
     x-data="warehouseTaskTabs(@js($taskTabsConfig))" @warehouse-tasks:changed.window="refresh()"
     @pageshow.window="if ($event.persisted) refresh()"
     class="flex flex-wrap gap-1.5 rounded-lg border border-gray-200 bg-gray-50 p-1.5">
    @foreach ($counts as $type => $count)
        @php
            [$section, $label] = $workTabs[$type];
            $workActive = request()->routeIs($section.'.*');
            $description = 'warehouse-task-count-'.$type;
        @endphp
        <a href="{{ route($section.'.index') }}" @if ($workActive) aria-current="page" @endif aria-label="{{ $label }}" aria-describedby="{{ $description }}"
           x-show="counts[@js($type)] !== undefined"
           class="inline-flex min-h-10 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2 py-1.5 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 sm:px-3 {{ $workActive ? 'bg-white text-indigo-800 shadow-sm ring-1 ring-gray-200' : 'text-gray-600 hover:bg-white hover:text-gray-900' }}">
            <span>{{ $label }}</span>
            <span aria-hidden="true" data-task-count="{{ $type }}" class="text-xs font-medium tabular-nums text-gray-600" x-text="counts[@js($type)]">{{ $count }}</span>
            <span id="{{ $description }}" class="sr-only" x-text="counts[@js($type)] + ' nyitott feladat'">{{ $count }} nyitott feladat</span>
        </a>
    @endforeach
</nav>
