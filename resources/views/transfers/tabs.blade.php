<nav aria-label="Átmozgatások modul" class="flex flex-wrap gap-x-5 gap-y-1 border-b border-gray-200 text-sm">
    @php
        $tabs = ['transfer-intent-pages.index' => 'Igények'];
        $canViewPlans = \Illuminate\Support\Facades\Gate::allows('viewAnyPlan', \App\Models\Shipment::class);
        if ($canViewPlans) {
            $tabs['shipments.index'] = 'Fuvarok és szállítmányok';
        }
        $canPickTasks = \Illuminate\Support\Facades\Gate::allows('viewPickingTaskList', \App\Models\TransferIntent::class);
        $canReceiveTasks = \Illuminate\Support\Facades\Gate::allows('viewAnyReceiving', \App\Models\Shipment::class);
        $warehouseTasks = $canPickTasks ? 'warehouse-picking-pages.index' : 'warehouse-receiving-pages.index';
        if ($canPickTasks || $canReceiveTasks) {
            $tabs[$warehouseTasks] = 'Raktári feladatok';
        }
        if ($canViewPlans) {
            $tabs['vehicle-pages.index'] = 'Járművek';
        }
    @endphp
    @foreach($tabs as $route => $label)
        @php($active = $route === $warehouseTasks
            ? request()->routeIs('warehouse-picking-pages.*', 'warehouse-receiving-pages.*', 'warehouse-putaway-pages.*')
            : request()->routeIs(explode('.', $route)[0].'.*'))
        <a href="{{ route($route) }}" @if($active) aria-current="page" @endif
            class="inline-flex min-h-11 items-center border-b-2 px-1 font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 {{ $active ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-900' }}">{{ $label }}</a>
    @endforeach
</nav>
