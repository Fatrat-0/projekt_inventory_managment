<x-app-layout>
    <div class="app-page py-4 space-y-4" data-listing-page="transfers" :data-listing-view="viewMode" @resize.window.debounce.100ms="closeMenus()" x-data="listingView('transfers', { filtersOpen: {{ count($labels) ? 'true' : 'false' }} })">
        <x-page-toolbar title="Átmozgatások" :count="$transfers->total()" unit="tétel">
            <a href="{{ route('shipments.create') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium">Rakodási terv készítése</a>
            <a href="{{ route('transfers.create', ['context' => $context]) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">+ Új átmozgatás</a>
            <x-listing-view-toggle />
        </x-page-toolbar>
        @include('transfers.tabs')
        <x-operation-feedback />
        <x-operation-status-filters :statuses="\App\Models\InventoryTransfer::STATUSES" :counts="$statusCounts" :filters="$filters" route="transfers.index" />
        <form id="transfer-filters" x-ref="filtersForm" method="GET" action="{{ route('transfers.index') }}" class="rounded-lg border border-gray-200 bg-white p-4 space-y-4">
            <input type="hidden" name="sort_by" value="{{ $filters['sort_by'] }}"><input type="hidden" name="sort_dir" value="{{ $filters['sort_dir'] }}">
            <div class="flex gap-2"><x-movement-search :products="$products" :query="$filters['search']" id="transfer-search" label="Átmozgatások keresése" placeholder="Termék, SKU, raktár vagy ATM-/SZALL-azonosító…" selection-hint="Enter: a kijelölt termék átmozgatásai" /><button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" aria-controls="transfer-filter-panel" class="rounded-md border border-gray-300 px-3 py-2 text-sm">Szűrők</button></div>
            <div id="transfer-filter-panel" x-show="filtersOpen" @if(!count($labels)) style="display:none;" @endif class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="sm:col-span-2 lg:col-span-4"><x-listing-date-presets :presets="$datePresets" /></div>
                <div><label for="transfer-status" class="block text-xs text-gray-600 mb-1">Állapot</label><select id="transfer-status" x-ref="status" name="status" class="w-full rounded-md border-gray-300 text-sm"><option value="">Összes</option>@foreach(\App\Models\InventoryTransfer::STATUSES as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="transfer-product" class="block text-xs text-gray-600 mb-1">Termék</label><x-product-picker :products="$products" id="transfer-product" :value="$filters['product_id'] ?? ''" /></div>
                @foreach(['from_warehouse_id' => 'Forrásraktár', 'to_warehouse_id' => 'Célraktár'] as $key => $label)<div><label for="transfer-{{ $key }}" class="block text-xs text-gray-600 mb-1">{{ $label }}</label><select id="transfer-{{ $key }}" name="{{ $key }}" class="w-full rounded-md border-gray-300 text-sm"><option value="">Összes</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->warehouse_id }}" @selected((string)$filters[$key] === (string)$warehouse->warehouse_id)>{{ $warehouse->warehouse_name }}</option>@endforeach</select></div>@endforeach
                <div><label for="transfer-date-from" class="block text-xs text-gray-600 mb-1">Dátumtól</label><input id="transfer-date-from" x-ref="dateFrom" type="date" name="date_from" value="{{ $filters['date_from'] }}" class="w-full rounded-md border-gray-300 text-sm"></div>
                <div><label for="transfer-date-to" class="block text-xs text-gray-600 mb-1">Dátumig (beleértve)</label><input id="transfer-date-to" x-ref="dateTo" type="date" name="date_to" value="{{ $filters['date_to'] }}" class="w-full rounded-md border-gray-300 text-sm"></div>
                <p class="sm:col-span-2 lg:col-span-4 text-xs text-gray-500">A dátumszűrés az átmozgatás indításának idejére vonatkozik.</p>
                <button class="justify-self-start rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white">Szűrés alkalmazása</button>
            </div>
        </form>
        @if($labels)<div class="flex flex-wrap gap-2 text-xs">@foreach($labels as $key => $label)<a class="rounded-md bg-indigo-50 border border-indigo-100 px-2 py-1 text-indigo-800" href="{{ request()->fullUrlWithQuery([$key => null, 'page' => null]) }}" aria-label="{{ $label }} szűrő eltávolítása">{{ $label }} ×</a>@endforeach<a href="{{ route('transfers.index') }}" class="p-1 underline text-gray-600">Összes visszaállítása</a></div>@endif
        <p class="text-sm text-gray-600">A szűrt tételekből úton: <strong class="text-amber-700">{{ number_format($pendingQuantity, 0, ',', ' ') }} db</strong></p>
        <x-listing-card-sort :sorts="\App\Queries\TransferListing::SORTS" :filters="$filters" form="transfer-filters" />
        <div data-listing-table class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            @if($transfers->isEmpty())<p role="status" class="p-10 text-center text-sm text-gray-500">Nincs a feltételeknek megfelelő átmozgatás.</p>@else
            <table class="w-full text-sm text-left text-gray-600"><thead class="bg-gray-50 text-xs uppercase"><tr>
                @foreach(\App\Queries\TransferListing::SORTS as $key => $label)<th data-listing-column="{{ $key }}" x-show="columnVisible('{{ $key }}')" scope="col" class="px-4 py-3" aria-sort="{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}"><a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => $key, 'sort_dir' => $filters['sort_by'] === $key && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc']) }}">{{ $label }} {{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? '↑' : '↓') : '↕' }}</a></th>@endforeach
                <th data-listing-column="route" x-show="columnVisible('route')" scope="col" class="px-4 py-3">Útvonal</th><th data-listing-column="actions" x-show="columnVisible('actions')" scope="col" class="px-4 py-3"><span class="sr-only">Műveletek</span><x-listing-columns /></th>
            </tr></thead><tbody class="divide-y divide-gray-100">@foreach($transfers as $transfer)<tr data-transfer-id="{{ $transfer->id }}" class="hover:bg-gray-50">
                <td data-listing-column="created_at" x-show="columnVisible('created_at')" class="px-4 py-3 whitespace-nowrap">{{ $transfer->created_at->format('Y.m.d H:i') }}</td>
                <td data-listing-column="product_name" x-show="columnVisible('product_name')" class="px-4 py-3 min-w-[180px]"><a href="{{ route('transfers.show', ['transfer' => $transfer, 'context' => $context]) }}" class="font-semibold text-gray-900 hover:text-indigo-600">{{ $transfer->product->product_name }}</a><p class="text-xs font-mono text-gray-500">{{ $transfer->product->sku }} · {{ $transfer->transfer_number }}</p></td>
                <td data-listing-column="quantity" x-show="columnVisible('quantity')" class="px-4 py-3 text-right whitespace-nowrap font-medium">{{ number_format($transfer->quantity, 0, ',', ' ') }} db</td>
                <td data-listing-column="status" x-show="columnVisible('status')" class="px-4 py-3"><span class="whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium {{ match($transfer->status) { 'pending' => 'bg-amber-50 text-amber-800', 'completed' => 'bg-green-50 text-green-800', default => 'bg-gray-100 text-gray-600' } }}">{{ \App\Models\InventoryTransfer::STATUSES[$transfer->status] }}</span></td>
                <td data-listing-column="route" x-show="columnVisible('route')" class="px-4 py-3 min-w-[190px]">{{ $transfer->fromWarehouse->warehouse_name }} <span class="text-indigo-400">→</span> {{ $transfer->toWarehouse->warehouse_name }}</td>
                <td data-listing-column="actions" x-show="columnVisible('actions')" class="px-4 py-3 min-w-[210px] space-y-2"><x-transfer-actions :transfer="$transfer" :context="$context" /><a href="{{ route('export.transfer', $transfer) }}" class="inline-block text-xs text-gray-500 underline">Szállítólevél (PDF)</a></td>
            </tr>@endforeach</tbody></table>@endif
        </div>
        @include('transfers.cards')
        <div class="flex flex-wrap gap-3 justify-between items-center text-sm text-gray-600"><div class="flex flex-wrap gap-3 items-center"><span>{{ $transfers->firstItem() ?? 0 }}–{{ $transfers->lastItem() ?? 0 }} / {{ $transfers->total() }} találat</span><label for="transfer-per-page" class="sr-only">Találatok oldalanként</label><select id="transfer-per-page" name="per_page" form="transfer-filters" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} / oldal</option>@endforeach</select></div>{{ $transfers->links('pagination.inventory') }}</div>
    </div>
</x-app-layout>
