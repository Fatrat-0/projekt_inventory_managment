<x-warehouse-task-layout>
    @php
        $statuses = ['pending' => 'Várakozik', 'in_progress' => 'Folyamatban', 'picked' => 'Kitárolva', 'cancelled' => 'Visszavonva'];
        $styles = ['pending' => 'bg-gray-100 text-gray-800', 'in_progress' => 'bg-indigo-50 text-indigo-800', 'picked' => 'bg-emerald-50 text-emerald-800', 'cancelled' => 'bg-amber-50 text-amber-900'];
        $priorities = \App\Support\TransferIntentDisplay::PRIORITY;
        $filtered = filled($filters['search'] ?? null) || filled($filters['warehouse_id'] ?? null) || ($filters['status'] ?? 'open') !== 'open';
        $field = 'mt-1 min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500';
        $link = 'inline-flex min-h-11 items-center rounded-md px-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600';
        $sorts = \App\Http\Controllers\WarehousePickingPageController::SORTS;

        $columns = 'xl:grid-cols-[minmax(0,1.25fr)_minmax(0,1.25fr)_minmax(0,1.15fr)_minmax(0,0.75fr)]';
    @endphp
    <div class="app-page space-y-4 py-4" data-picking-task-list>
        <x-page-toolbar title="Kitárolási feladatok" :count="$tasks->total()" unit="kitárolási feladat" />

        <form id="picking-filters" x-data method="get" action="{{ route('warehouse-picking-pages.index') }}" aria-label="Raktári feladatok szűrése"
              class="relative rounded-lg border border-gray-200 bg-white p-4">

            @if (isset($filters['per_page']))<input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">@endif
            <div class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_repeat(2,minmax(0,1fr))_auto] xl:items-end">
                <div class="min-w-0 sm:col-span-2 xl:col-span-1">
                    <label for="task-search" class="block text-xs font-medium text-gray-700">Keresés</label>
                    <x-listing-search :endpoint="route('warehouse-picking-pages.index')" :query="$filters['search'] ?? ''"
                        id="task-search" label="Keresés" placeholder="Termék, SKU, igényazonosító…"
                        selection-hint="Enter: a kijelölt javaslatra keresés" input-class="min-h-11" class="mt-1" />
                </div>
                <div class="min-w-0">
                    <label for="task-status" class="block text-xs font-medium text-gray-700">Állapot</label>
                    <select id="task-status" name="status" class="{{ $field }}">
                        <option value="open" @selected(($filters['status'] ?? 'open') === 'open')>Nyitott feladatok</option>
                        <option value="all" @selected(($filters['status'] ?? null) === 'all')>Minden állapot</option>
                        @foreach ($statuses as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['status'] ?? null) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-0">
                    <label for="task-warehouse" class="block text-xs font-medium text-gray-700">Forrásraktár</label>
                    <select id="task-warehouse" name="warehouse_id" class="{{ $field }} truncate">
                        <option value="">Minden jogosult raktár</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->warehouse_id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->warehouse_id)>{{ $warehouse->warehouse_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex flex-wrap items-center gap-2 sm:col-span-2 xl:col-span-1">
                    <x-primary-button class="min-h-11">Szűrés</x-primary-button>
                    <x-listing-sort :sorts="$sorts" :filters="$filters" form="picking-filters" />
                    @if ($filtered || $filterErrors->any())
                        <a href="{{ route('warehouse-picking-pages.index') }}" class="{{ $link }}">Szűrők törlése</a>
                    @endif
                </div>
            </div>
            @if ($filterErrors->any())
                <ul role="alert" class="mt-3 list-inside list-disc text-sm text-red-700">
                    @foreach ($filterErrors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            @endif
        </form>

        @if ($tasks->isEmpty())
            <div class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-700" role="status">
                {{ $filtered ? 'Nincs a szűrésnek megfelelő kitárolási feladat.' : 'Nincs nyitott kitárolási feladat a raktáraidban.' }}
                @if ($tasks->total())<a href="{{ $tasks->url(1) }}" class="{{ $link }}">Első oldal</a>@endif
            </div>
        @else

            <div role="table" aria-label="Kitárolási feladatok" class="min-w-0 rounded-lg border border-gray-200 bg-white text-sm">
                <div role="row" class="hidden {{ $columns }} gap-4 rounded-t-lg border-b border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-700 xl:grid">
                    <span role="columnheader" class="min-w-0 py-1">Igény és termék</span>
                    <span role="columnheader" class="min-w-0 py-1">Útvonal</span>
                    <span role="columnheader" class="min-w-0 py-1">Mennyiségek</span>
                    <span role="columnheader" class="min-w-0 py-1">Állapot és prioritás</span>
                </div>
                <div role="rowgroup" class="divide-y divide-gray-200">
                    @foreach ($tasks as $task)
                        @php($intent = $task->intent)
                        <div role="row" class="grid min-w-0 gap-3 px-4 py-3 sm:grid-cols-2 {{ $columns }} xl:items-start xl:gap-4">
                            <div role="cell" class="min-w-0 [overflow-wrap:anywhere]">
                                <a href="{{ route('warehouse-picking-pages.show', ['transferIntent' => $intent, 'task' => $task->id]) }}"
                                   class="inline-flex min-h-10 max-w-full items-center rounded font-mono text-xs font-semibold text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $intent->reference }}</a>
                                <p class="font-semibold text-gray-900">{{ $intent->product->product_name }}</p>
                                <p class="text-xs text-gray-600">{{ $intent->product->sku }}</p>
                            </div>
                            <div role="cell" class="min-w-0 text-gray-800 [overflow-wrap:anywhere]">
                                <p><span class="text-xs text-gray-600">Innen</span> {{ $intent->fromWarehouse->warehouse_name }}</p>
                                <p><span class="text-xs text-gray-600">Ide</span> {{ $intent->toWarehouse->warehouse_name }}</p>
                            </div>
                            <div role="cell" class="min-w-0 text-xs leading-5 text-gray-700">
                                <p>Kért <span class="font-medium text-gray-900">{{ number_format($task->expected_quantity, 0, ',', ' ') }} db</span></p>
                                <p>Kitárolva <span class="font-medium text-gray-900">{{ number_format($task->staged_quantity, 0, ',', ' ') }} db</span></p>
                                <p>Hátralévő <span class="font-semibold text-gray-900">{{ number_format(max(0, $task->expected_quantity - $task->staged_quantity), 0, ',', ' ') }} db</span></p>
                            </div>
                            <div role="cell" class="min-w-0">
                                <span class="inline-flex rounded-md px-2 py-1 text-xs font-semibold {{ $styles[$task->status] }}">{{ $statuses[$task->status] }}</span>
                                <p class="mt-1 text-xs text-gray-700">{{ $priorities[$intent->priority] }} prioritás</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($tasks->hasPages()){{ $tasks->links('pagination.inventory') }}@endif
    </div>
</x-warehouse-task-layout>
