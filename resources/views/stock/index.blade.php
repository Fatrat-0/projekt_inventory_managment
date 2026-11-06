<x-app-layout>
    @php($canManage = in_array(auth()->user()->role, ['admin', 'manager'], true))
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-center gap-2 text-2xl font-bold text-gray-900"><x-icon name="layers" class="h-6 w-6 text-indigo-600" /> Készlet <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-600">{{ number_format($stocks->total(), 0, ',', ' ') }} készletsor</span></h1>
                <p class="mt-1 text-sm text-gray-500">Termékek raktárankénti készlete és helyi minimuma.</p>
            </div>
            <a href="{{ route('movements.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Új készletmozgás</a>
        </header>
        <x-operation-feedback />
        <section aria-label="Szűrt készletösszesítők" class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))] gap-3">
            @foreach(['physical' => ['Fizikai készlet', null, 'db'], 'available' => ['Kiadható készlet', 'positive', 'db'], 'quarantined' => ['Elkülönített készlet', 'quarantined', 'db'], 'low_count' => ['Helyi minimum alatt', 'low', 'készletsor']] as $key => [$label, $status, $unit])
                <a href="{{ route('stock.index', array_replace($filters, ['stock_status' => $status ?? 'all'])) }}" class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 hover:border-indigo-400 focus-visible:ring-2 focus-visible:ring-indigo-500" @if($status !== null && $filters['stock_status'] === $status) aria-current="page" @endif>
                    <p class="text-xs text-gray-500">{{ $label }}</p><p data-stock-summary="{{ $key }}" class="dashboard-text mt-2 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($summary->$key, 0, ',', ' ') }} <span class="text-xs font-normal text-gray-500">{{ $unit }}</span></p>
                </a>
            @endforeach
        </section>
        <p class="text-xs text-gray-500">Az összesítők minden szűrt készletsorra vonatkoznak, nem csak az aktuális oldalra. {{ number_format($summary->products_count, 0, ',', ' ') }} termék · {{ number_format($summary->warehouses_count, 0, ',', ' ') }} raktár.</p>
        <form id="stock-filters" method="GET" action="{{ route('stock.index') }}" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            <input type="hidden" name="sort_by" value="{{ $filters['sort_by'] }}"><input type="hidden" name="sort_dir" value="{{ $filters['sort_dir'] }}"><input type="hidden" name="product_id" value="{{ $filters['product_id'] }}">
            <div title="Több szóval is kereshetsz terméknév, cikkszám, kategória, raktárnév vagy cím alapján. Minden szónak egyeznie kell. A javaslatokból pontos termék választható.">
                <x-listing-search :items="$suggestions" :query="$filters['search']" id="stock-search" label="Készlet keresése" placeholder="Termék, cikkszám, kategória vagy raktár…" filter-name="product_id" :clear-filter-on-edit="true" results-label="Készleten nyilvántartott termékek" />
            </div>
            <details @if($filters['warehouse_id'] || $filters['category_id'] || $filters['stock_status'] !== 'all') open @endif>
                <summary class="cursor-pointer py-1 text-sm font-semibold text-gray-700">Szűrők</summary>
                <div class="mt-3 grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="min-w-0"><label for="stock-warehouse" class="mb-1 block text-xs font-medium text-gray-600">Raktár</label><select id="stock-warehouse" name="warehouse_id" onchange="this.form.requestSubmit()" class="w-full min-w-0 rounded-md border-gray-300 text-sm"><option value="">Összes raktár</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->warehouse_id }}" @selected((string)$filters['warehouse_id'] === (string)$warehouse->warehouse_id)>{{ $warehouse->warehouse_name }}</option>@endforeach</select></div>
                    <div class="min-w-0"><label for="stock-category" class="mb-1 block text-xs font-medium text-gray-600">Kategória és alkategóriái</label><select id="stock-category" name="category_id" onchange="this.form.requestSubmit()" class="w-full min-w-0 rounded-md border-gray-300 text-sm"><option value="">Összes kategória</option>@foreach($categoryOptions as $category)<option value="{{ $category['id'] }}" @selected((string)$filters['category_id'] === (string)$category['id'])>{{ $category['label'] }}</option>@endforeach</select></div>
                    <div class="min-w-0"><label for="stock-state" class="mb-1 block text-xs font-medium text-gray-600">Készletállapot</label><select id="stock-state" name="stock_status" onchange="this.form.requestSubmit()" class="w-full min-w-0 rounded-md border-gray-300 text-sm">@foreach(\App\Queries\StockListing::STATUSES as $value => $label)<option value="{{ $value }}" @selected($filters['stock_status'] === $value)>{{ $label }}</option>@endforeach</select></div>
                    <button type="submit" class="min-h-10 justify-self-start rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Szűrés alkalmazása</button>
                </div>
            </details>
        </form>
        @if($filterLabels)
            <div class="flex flex-wrap gap-2" aria-label="Aktív készletszűrők">
                @foreach($filterLabels as $key => $label)<a href="{{ route('stock.index', array_replace($filters, [$key => null])) }}" aria-label="{{ $label }} szűrő eltávolítása" class="dashboard-text max-w-full rounded-md border border-indigo-100 bg-indigo-50 px-2 py-1 text-xs text-indigo-800">{{ $label }} ×</a>@endforeach
                <a href="{{ route('stock.index', array_intersect_key($filters, array_flip(['sort_by', 'sort_dir', 'per_page']))) }}" class="py-1 text-xs text-gray-600 underline">Összes szűrő törlése</a>
            </div>
        @endif
        <details class="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
            <summary class="cursor-pointer font-medium">Mit jelentenek a készletértékek?</summary>
            <p class="mt-2">Fizikai készlet = kiadható + elkülönített. Az úton lévő áru még nincs a célraktárban. A lista a nulla mennyiségű készletsorokat is tartalmazza, a készletsor nélküli termékeket nem.</p>
            <p class="mt-2">A helyi minimum raktáranként és termékenként érvényes, alapból 5 db. Az utánpótlási javaslatokat vezérli; a globális termék- és kategóriaminimumoktól független. A 0 kikapcsolja az adott készletsor helyi pótlási igényét.</p>
        </details>
        <div class="md:hidden">
            <details class="rounded-lg border border-gray-200 bg-white p-3">
                <summary class="cursor-pointer text-sm font-medium text-gray-700">Rendezés: {{ \App\Queries\StockListing::SORTS[$filters['sort_by']] }} {{ $filters['sort_dir'] === 'asc' ? '↑' : '↓' }}</summary>
                <div class="mt-2 flex flex-wrap gap-2">@foreach(\App\Queries\StockListing::SORTS as $key => $label) @include('stock.sort-link', ['mobile' => true]) @endforeach</div>
            </details>
        </div>
        @if($stocks->isEmpty())
            <div role="status" class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-gray-600">Nincs a feltételeknek megfelelő készletsor.@if($filterLabels) <a href="{{ route('stock.index') }}" class="ml-1 font-medium text-indigo-700 underline">Szűrők törlése</a>@endif</div>
        @else
            @include('stock.table')
            @include('stock.cards')
        @endif
        <footer class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
            <div class="flex flex-wrap items-center gap-3"><span>{{ $stocks->firstItem() ?? 0 }}–{{ $stocks->lastItem() ?? 0 }} / {{ number_format($stocks->total(), 0, ',', ' ') }} készletsor</span><label for="stock-per-page" class="sr-only">Készletsorok oldalanként</label><select id="stock-per-page" name="per_page" form="stock-filters" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} / oldal</option>@endforeach</select><noscript><button form="stock-filters" class="rounded-md border border-gray-300 px-3 py-2">Oldalméret alkalmazása</button></noscript></div>
            {{ $stocks->links('pagination.inventory') }}
        </footer>
    </div>
</x-app-layout>
