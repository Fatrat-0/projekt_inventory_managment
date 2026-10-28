<x-app-layout>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6 space-y-5" x-data="{ filtersOpen: {{ count($labels) ? 'true' : 'false' }} }">
        @include('transfers.tabs')
        <div class="flex flex-wrap items-center gap-3"><h2 class="text-xl font-bold text-gray-900">Átmozgatások <span class="text-xs text-gray-500 font-normal">{{ $transfers->total() }} tétel</span></h2>
            <div class="flex flex-wrap gap-2 sm:ml-auto"><a href="{{ route('shipments.create') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium">Rakodási terv készítése</a><a href="{{ route('transfers.create', ['context' => $context]) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">+ Új átmozgatás</a></div>
        </div>
        <x-operation-feedback />
        <form id="transfer-filters" method="GET" class="rounded-lg border border-gray-200 bg-white p-4 space-y-4">
            <input type="hidden" name="sort_by" value="{{ $filters['sort_by'] }}"><input type="hidden" name="sort_dir" value="{{ $filters['sort_dir'] }}">
            <div class="flex gap-2"><input type="search" name="search" value="{{ $filters['search'] }}" aria-label="Átmozgatások keresése" placeholder="Termék, SKU, raktár vagy szállítmányhivatkozás…" maxlength="200" class="min-w-0 flex-1 rounded-md border-gray-300 text-sm"><button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white">Keresés</button><button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" aria-controls="transfer-filter-panel" class="rounded-md border border-gray-300 px-3 py-2 text-sm">Szűrők</button></div>
            <div id="transfer-filter-panel" x-show="filtersOpen" @if(!count($labels)) style="display:none;" @endif class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div><label for="transfer-status" class="block text-xs text-gray-600 mb-1">Állapot</label><select id="transfer-status" name="status" class="w-full rounded-md border-gray-300 text-sm"><option value="">Összes</option>@foreach(\App\Models\InventoryTransfer::STATUSES as $key => $label)<option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>@endforeach</select></div>
                <div><label for="transfer-product" class="block text-xs text-gray-600 mb-1">Termék</label><x-product-picker :products="$products" id="transfer-product" :value="$filters['product_id'] ?? ''" /></div>
                @foreach(['from_warehouse_id' => 'Forrásraktár', 'to_warehouse_id' => 'Célraktár'] as $key => $label)<div><label for="transfer-{{ $key }}" class="block text-xs text-gray-600 mb-1">{{ $label }}</label><select id="transfer-{{ $key }}" name="{{ $key }}" class="w-full rounded-md border-gray-300 text-sm"><option value="">Összes</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->warehouse_id }}" @selected((string)$filters[$key] === (string)$warehouse->warehouse_id)>{{ $warehouse->warehouse_name }}</option>@endforeach</select></div>@endforeach
                <button class="justify-self-start rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white">Szűrés alkalmazása</button>
            </div>
        </form>
        @if($labels)<div class="flex flex-wrap gap-2 text-xs">@foreach($labels as $key => $label)<a class="rounded-md bg-indigo-50 border border-indigo-100 px-2 py-1 text-indigo-800" href="{{ request()->fullUrlWithQuery([$key => null, 'page' => null]) }}" aria-label="{{ $label }} szűrő eltávolítása">{{ $label }} ×</a>@endforeach<a href="{{ route('transfers.index') }}" class="p-1 underline text-gray-600">Összes visszaállítása</a></div>@endif
        <p class="text-sm text-gray-600">A szűrt tételekből úton: <strong class="text-amber-700">{{ number_format($pendingQuantity, 0, ',', ' ') }} db</strong></p>
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            @if($transfers->isEmpty())<p role="status" class="p-10 text-center text-sm text-gray-500">Nincs a feltételeknek megfelelő átmozgatás.</p>@else
            <table class="w-full text-sm text-left text-gray-600"><thead class="bg-gray-50 text-xs uppercase"><tr>
                @foreach(\App\Queries\TransferListing::SORTS as $key => $label)<th scope="col" class="px-4 py-3" aria-sort="{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}"><a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => $key, 'sort_dir' => $filters['sort_by'] === $key && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc']) }}">{{ $label }} {{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? '↑' : '↓') : '↕' }}</a></th>@endforeach
                <th scope="col" class="px-4 py-3">Útvonal</th><th scope="col" class="px-4 py-3">Műveletek</th>
            </tr></thead><tbody class="divide-y divide-gray-100">@foreach($transfers as $transfer)<tr class="hover:bg-gray-50">
                <td class="px-4 py-3 whitespace-nowrap">{{ $transfer->created_at->format('Y.m.d H:i') }}</td>
                <td class="px-4 py-3 min-w-[180px]"><a href="{{ route('transfers.show', ['transfer' => $transfer, 'context' => $context]) }}" class="font-semibold text-gray-900 hover:text-indigo-600">{{ $transfer->product->product_name }}</a><p class="text-xs font-mono text-gray-500">{{ $transfer->product->sku }} · {{ $transfer->transfer_number }}</p></td>
                <td class="px-4 py-3 text-right whitespace-nowrap font-medium">{{ number_format($transfer->quantity, 0, ',', ' ') }} db</td>
                <td class="px-4 py-3"><span class="whitespace-nowrap rounded-md px-2 py-1 text-xs font-medium {{ match($transfer->status) { 'pending' => 'bg-amber-50 text-amber-800', 'completed' => 'bg-green-50 text-green-800', default => 'bg-gray-100 text-gray-600' } }}">{{ \App\Models\InventoryTransfer::STATUSES[$transfer->status] }}</span></td>
                <td class="px-4 py-3 min-w-[190px]">{{ $transfer->fromWarehouse->warehouse_name }} <span class="text-indigo-400">→</span> {{ $transfer->toWarehouse->warehouse_name }}</td>
                <td class="px-4 py-3 min-w-[210px] space-y-2"><x-transfer-actions :transfer="$transfer" :context="$context" /><a href="{{ route('export.transfer', $transfer) }}" class="inline-block text-xs text-gray-500 underline">Szállítólevél (PDF)</a></td>
            </tr>@endforeach</tbody></table>@endif
        </div>
        <div class="flex flex-wrap gap-3 justify-between items-center text-sm text-gray-600"><div class="flex gap-3 items-center"><span>{{ $transfers->firstItem() ?? 0 }}–{{ $transfers->lastItem() ?? 0 }} / {{ $transfers->total() }} találat</span><label for="transfer-per-page" class="sr-only">Találatok oldalanként</label><select id="transfer-per-page" name="per_page" form="transfer-filters" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} / oldal</option>@endforeach</select></div>{{ $transfers->links('pagination.inventory') }}</div>
    </div>
</x-app-layout>
