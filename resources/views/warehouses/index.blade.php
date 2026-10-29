<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-xl font-bold text-gray-900">Raktárak <span class="text-sm font-normal text-gray-500">{{ $warehouses->total() }} találat</span></h2><p class="mt-1 text-sm text-gray-500">Helyszínek, raktárankénti készletek és kapcsolódó mozgások.</p></div>
            <a href="{{ route('warehouses.create', ['context' => $context]) }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ Új raktár</a>
        </div>
        <x-operation-feedback />
        <form id="warehouse-filters" method="GET" action="{{ route('warehouses.index') }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4">
            <input type="hidden" name="sort_by" value="{{ $filters['sort_by'] }}">
            <input type="hidden" name="sort_dir" value="{{ $filters['sort_dir'] }}">
            <input type="hidden" name="warehouse_id" value="{{ $filters['warehouse_id'] }}">
            <div class="min-w-0 flex-1 basis-64"><label for="warehouse-search" class="mb-1 block text-xs font-medium text-gray-600">Raktárak keresése</label><x-listing-search :items="$suggestions" :query="$filters['search']" id="warehouse-search" label="Raktárak keresése" placeholder="Raktár neve vagy címe…" filter-name="warehouse_id" :clear-filter-on-edit="true" results-label="Kereső raktárjavaslatai" count-label="raktárjavaslat" selection-hint="Enter: a kijelölt raktár megjelenítése" empty-hint="Nincs egyező raktárjavaslat. A megadott szövegre továbbra is kereshetsz." /></div>
            <div><label for="warehouse-stock" class="mb-1 block text-xs font-medium text-gray-600">Készlet</label><select id="warehouse-stock" name="stock" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm"><option value="">Összes raktár</option><option value="stocked" @selected($filters['stock'] === 'stocked')>Van készleten termék</option><option value="empty" @selected($filters['stock'] === 'empty')>Nincs készleten termék</option></select></div>
            <div class="flex w-full flex-wrap gap-2 md:hidden">
                <div class="min-w-0 flex-1"><label for="warehouse-mobile-sort" class="mb-1 block text-xs font-medium text-gray-600">Rendezés</label><select id="warehouse-mobile-sort" onchange="this.form.elements.namedItem('sort_by').value=this.value; this.form.requestSubmit()" class="w-full rounded-md border-gray-300 text-sm">@foreach(\App\Queries\WarehouseListing::SORTS as $key => $label)<option value="{{ $key }}" @selected($filters['sort_by'] === $key)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="warehouse-mobile-direction" class="mb-1 block text-xs font-medium text-gray-600">Irány</label><select id="warehouse-mobile-direction" onchange="this.form.elements.namedItem('sort_dir').value=this.value; this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm"><option value="asc" @selected($filters['sort_dir'] === 'asc')>Növekvő</option><option value="desc" @selected($filters['sort_dir'] === 'desc')>Csökkenő</option></select></div>
            </div>
            @if($filters['search'] !== '' || $filters['stock'] !== '' || $filters['warehouse_id'] !== '')<a href="{{ route('warehouses.index', ['sort_by' => $filters['sort_by'], 'sort_dir' => $filters['sort_dir'], 'per_page' => $filters['per_page']]) }}" class="px-2 py-2 text-sm text-gray-600 underline">Szűrők törlése</a>@endif
        </form>
        @if($selectedWarehouse)<div class="flex flex-wrap gap-2 text-xs"><a href="{{ request()->fullUrlWithQuery(['warehouse_id' => null, 'page' => null]) }}" aria-label="Kiválasztott raktár szűrésének törlése" class="rounded-md border border-indigo-100 bg-indigo-50 px-2 py-1 text-indigo-800">Raktár: {{ $selectedWarehouse->warehouse_name }} ×</a></div>@endif
        <div class="hidden overflow-x-auto rounded-lg border border-gray-200 bg-white md:block">
            <table class="w-full text-left text-sm text-gray-600">
                <caption class="sr-only">Raktárak és készletösszesítések</caption>
                <thead class="border-b border-gray-200 bg-gray-50 text-xs text-gray-600">
                    <tr>
                        @foreach(\App\Queries\WarehouseListing::SORTS as $column => $label)
                            @php($active = $filters['sort_by'] === $column)
                            <th scope="col" class="px-4 py-3" aria-sort="{{ $active ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}"><a href="{{ route('warehouses.index', array_replace($filters, ['sort_by' => $column, 'sort_dir' => $active && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc'])) }}" class="inline-flex items-center gap-1 whitespace-nowrap font-semibold">{{ $label }}@if($active)<span aria-hidden="true">{{ $filters['sort_dir'] === 'asc' ? '↑' : '↓' }}</span>@endif</a></th>
                        @endforeach
                        <th scope="col" class="px-4 py-3 text-right">Műveletek</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($warehouses as $warehouse)
                        <tr class="align-top hover:bg-gray-50" data-warehouse-id="{{ $warehouse->warehouse_id }}">
                            <td class="px-4 py-4"><a href="{{ route('warehouses.show', ['warehouse' => $warehouse, 'context' => $context]) }}" class="font-semibold text-gray-900 hover:text-indigo-600">{{ $warehouse->warehouse_name }}</a></td>
                            <td class="max-w-xs whitespace-pre-line break-words px-4 py-4">{{ $warehouse->location ?: 'Nincs megadva' }}</td>
                            <td class="px-4 py-4 tabular-nums">@include('warehouses.product-count')</td>
                            <td class="whitespace-nowrap px-4 py-4 tabular-nums">{{ number_format($warehouse->total_quantity, 0, ',', ' ') }} db</td>
                            <td class="w-16 px-4 py-2">@include('warehouses.actions', ['variant' => 'table'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" role="status" class="px-4 py-12 text-center text-gray-500">Nincs a feltételeknek megfelelő raktár.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="space-y-3 md:hidden">
            @forelse($warehouses as $warehouse)
                <article data-warehouse-id="{{ $warehouse->warehouse_id }}" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="break-words font-semibold text-gray-900"><a href="{{ route('warehouses.show', ['warehouse' => $warehouse, 'context' => $context]) }}">{{ $warehouse->warehouse_name }}</a></h3><p class="mt-1 whitespace-pre-line break-words text-sm text-gray-500">{{ $warehouse->location ?: 'Nincs megadva' }}</p></div>@include('warehouses.actions', ['variant' => 'card'])</div>
                    <dl class="grid grid-cols-2 gap-3 rounded-md bg-gray-50 p-3 text-sm"><div><dt class="text-xs text-gray-500">Készleten lévő termékfajták</dt><dd class="mt-1 font-medium tabular-nums">@include('warehouses.product-count')</dd></div><div><dt class="text-xs text-gray-500">Összkészlet</dt><dd class="mt-1 font-medium tabular-nums">{{ number_format($warehouse->total_quantity, 0, ',', ' ') }} db</dd></div></dl>
                </article>
            @empty
                <p role="status" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">Nincs a feltételeknek megfelelő raktár.</p>
            @endforelse
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
            <div class="flex flex-wrap items-center gap-3"><span>{{ $warehouses->firstItem() ?? 0 }}–{{ $warehouses->lastItem() ?? 0 }} / {{ $warehouses->total() }} találat</span><label for="warehouse-per-page" class="sr-only">Találatok oldalanként</label><select id="warehouse-per-page" name="per_page" form="warehouse-filters" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm">@foreach([15,25,50,100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} / oldal</option>@endforeach</select></div>
            {{ $warehouses->links('pagination.inventory') }}
        </div>
        <p class="text-xs text-gray-500">A termékfajták száma a pozitív készletű termékeket jelenti. Az összkészlet csak az adott raktárban található mennyiség, az úton lévő áru nélkül. Kapcsolódó készletadat vagy bizonylat esetén a raktár nem törölhető.</p>
    </div>
</x-app-layout>
