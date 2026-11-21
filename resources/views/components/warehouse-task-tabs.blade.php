@inject('warehouseTaskCounts', \App\Services\WarehouseOpenTaskCounts::class)
@php
    $counts = $warehouseTaskCounts->forUser(request()->user());
    $taskTabsConfig = ['counts' => $counts, 'url' => route('warehouse-task-counts')];
@endphp
<nav aria-label="Raktári feladatok munkatípusai" data-warehouse-task-tabs
     x-data="warehouseTaskTabs(@js($taskTabsConfig))" @warehouse-tasks:changed.window="refresh()"
     @pageshow.window="if ($event.persisted) refresh()"
     class="flex flex-wrap items-center gap-1">
    @foreach (\App\Support\AppNavigation::WAREHOUSE_TASK_TABS as $type => [$section, $label, $icon])
        @continue(!array_key_exists($type, $counts))
        @php
            $count = $counts[$type];
            $workActive = request()->routeIs($section.'.*');
            $description = 'warehouse-task-count-'.$type;
        @endphp
        <a href="{{ route($section.'.index') }}" @if ($workActive) aria-current="page" @endif aria-label="{{ $label }}"
           aria-describedby="{{ $description }}" x-show="counts[@js($type)] !== undefined"
           class="inline-flex min-h-11 items-center gap-1.5 whitespace-nowrap rounded-md px-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 md:px-3 {{ $workActive ? 'bg-indigo-50 text-indigo-700' : 'text-gray-700 hover:bg-gray-100' }}">
            <x-icon :name="$icon" class="hidden lg:block" />
            <span>{{ $label }}</span>
            <span aria-hidden="true" data-task-count="{{ $type }}" class="rounded-full px-1.5 text-xs font-medium tabular-nums {{ $workActive ? 'bg-indigo-100 text-indigo-800' : 'bg-gray-100 text-gray-600' }}" x-text="counts[@js($type)]">{{ $count }}</span>
            <span id="{{ $description }}" class="sr-only" x-text="counts[@js($type)] + ' nyitott feladat'">{{ $count }} nyitott feladat</span>
        </a>
    @endforeach
</nav>
