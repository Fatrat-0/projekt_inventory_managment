<x-app-layout>
    <div x-data="{ filtersOpen: {{ count($filterLabels) ? 'true' : 'false' }} }">
        <div class="bg-white border-b border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 space-y-3">
                <div class="flex flex-col lg:flex-row lg:items-center gap-3">
                    <h2 class="text-xl font-bold text-gray-800 whitespace-nowrap">Készletmozgások <span class="text-xs font-medium text-gray-500">{{ $movements->total() }} tétel</span></h2>
                    <form id="movement-filters" method="GET" action="{{ route('movements.index') }}" class="flex-1">
                        <input type="hidden" name="sort_by" value="{{ $filters['sort_by'] }}">
                        <input type="hidden" name="sort_dir" value="{{ $filters['sort_dir'] }}">
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <input type="search" name="search" aria-label="Mozgások keresése" maxlength="200" value="{{ $filters['search'] }}" placeholder="Termék, SKU vagy hivatkozás…" class="w-full rounded-md border-gray-300 text-sm pr-10 focus:border-indigo-500 focus:ring-indigo-500">
                                <button type="submit" aria-label="Keresés" class="absolute right-3 top-3 text-gray-500"><x-icon name="search" /></button>
                            </div>
                            <button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" aria-controls="movement-filter-panel" class="inline-flex items-center gap-2 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"><x-icon name="filter" /> Szűrők</button>
                        </div>
                    </form>
                    <div class="flex gap-2 items-center">
                        <a href="{{ $createUrl }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ Új mozgás</a>
                        @include('movements.pdf-options')
                    </div>
                </div>
                <div id="movement-filter-panel" x-show="filtersOpen" @if(!count($filterLabels)) style="display: none;" @endif class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 border-t border-gray-100 pt-3">
                    @foreach(['type' => 'Mozgás típusa', 'product_id' => 'Termék', 'warehouse_id' => 'Raktár', 'user_id' => 'Rögzítette'] as $key => $label)
                        <div>
                            <label for="movement-{{ $key }}" class="block text-xs font-medium text-gray-600 mb-1">{{ $label }}</label>
                            @if($key === 'product_id')
                                <x-product-picker :products="$products" id="movement-product_id" :value="$filters['product_id'] ?? ''" form="movement-filters" />
                            @else
                            <select id="movement-{{ $key }}" name="{{ $key }}" form="movement-filters" class="w-full rounded-md border-gray-300 text-sm">
                                <option value="">Összes</option>
                                @if($key === 'type')
                                    <option value="in" @selected($filters[$key] === 'in')>Bevételezés</option>
                                    <option value="out" @selected($filters[$key] === 'out')>Kiadás</option>
                                @else
                                    @php
                                        $choices = match($key) { 'product_id' => $products, 'warehouse_id' => $warehouses, default => $users };
                                    @endphp
                                    @foreach($choices as $choice)
                                        <option value="{{ $choice->getKey() }}" @selected((string)$filters[$key] === (string)$choice->getKey())>{{ $choice->product_name ?? $choice->warehouse_name ?? $choice->name }}{{ $key === 'product_id' ? ' ('.$choice->sku.')' : '' }}</option>
                                    @endforeach
                                @endif
                            </select>
                            @endif
                        </div>
                    @endforeach
                    <div><label for="movement-date-from" class="block text-xs font-medium text-gray-600 mb-1">Dátumtól</label><input id="movement-date-from" type="date" name="date_from" form="movement-filters" value="{{ $filters['date_from'] }}" class="w-full rounded-md border-gray-300 text-sm"></div>
                    <div><label for="movement-date-to" class="block text-xs font-medium text-gray-600 mb-1">Dátumig (beleértve)</label><input id="movement-date-to" type="date" name="date_to" form="movement-filters" value="{{ $filters['date_to'] }}" class="w-full rounded-md border-gray-300 text-sm"></div>
                    <button type="submit" form="movement-filters" class="justify-self-start rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Szűrés alkalmazása</button>
                </div>
                @if($filterLabels)
                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                        @foreach($filterLabels as $key => $label)
                            <a href="{{ request()->fullUrlWithQuery([$key => null, 'page' => null]) }}" class="rounded-md border border-indigo-100 bg-indigo-50 px-2 py-1 text-xs text-indigo-800" aria-label="{{ $label }} szűrő eltávolítása">{{ $label }} ×</a>
                        @endforeach
                        <a href="{{ route('movements.index') }}" class="text-xs font-medium text-gray-600 underline">Összes visszaállítása</a>
                    </div>
                @endif
            </div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">
            @if(session('success'))<div role="status" class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('success') }}</div>@endif
            @if($errors->any())<div role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-600">
                <span>A szűrt találatok összesen:</span>
                <span>Bevételezés <strong class="text-green-700">+{{ number_format($totalIn, 0, ',', ' ') }} db</strong></span>
                <span>Kiadás <strong class="text-red-700">−{{ number_format($totalOut, 0, ',', ' ') }} db</strong></span>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="bg-gray-50 text-xs uppercase text-gray-600 border-b">
                        <tr>
                            @foreach($sortOptions as $key => $label)
                                <th scope="col" class="px-4 py-3 {{ $key === 'quantity' ? 'text-right' : '' }}" aria-sort="{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                    <a class="whitespace-nowrap hover:text-indigo-600" href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => $key, 'sort_dir' => $filters['sort_by'] === $key && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc']) }}">{{ $label }} <span class="{{ $filters['sort_by'] === $key ? 'text-indigo-600' : 'text-gray-400' }}">{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? '↑' : '↓') : '↕' }}</span></a>
                                </th>
                            @endforeach
                            <th scope="col" class="px-4 py-3">Hivatkozás</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($movements as $movement)
                            <tr class="hover:bg-indigo-50/30">
                                <td class="px-4 py-3 whitespace-nowrap">{{ $movement->created_at->format('Y.m.d H:i') }}</td>
                                <td class="px-4 py-3"><span class="inline-flex rounded-md px-2 py-1 text-xs font-medium {{ $movement->type === 'in' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $movement->type === 'in' ? 'Bevételezés' : 'Kiadás' }}</span></td>
                                <td class="px-4 py-3 min-w-[180px]"><a href="{{ route('movements.show', ['movement' => $movement, 'context' => $listContext]) }}" class="block font-semibold text-gray-900 hover:text-indigo-600 hover:underline">{{ $movement->product->product_name }}</a>
                                    <a href="{{ route('movements.show', ['movement' => $movement, 'context' => $listContext]) }}" class="text-xs font-mono text-indigo-600 hover:underline">{{ $movement->movement_number }}</a><div class="text-xs font-mono text-gray-500">{{ $movement->product->sku }}</div></td>
                                <td class="px-4 py-3">{{ $movement->warehouse->warehouse_name }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap font-medium">{{ $movement->type === 'in' ? '+' : '−' }}{{ number_format($movement->quantity, 0, ',', ' ') }} db</td>
                                <td class="px-4 py-3">{{ $movement->user->name }}</td>
                                <td class="px-4 py-3 break-words max-w-xs">{{ $movement->reference ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-12 text-center">Nincs a feltételeknek megfelelő készletmozgás. @if($filterLabels)<a href="{{ route('movements.index') }}" class="block mt-2 text-indigo-600 underline">Szűrők törlése</a>@endif</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="flex flex-col sm:flex-row sm:flex-wrap gap-3 sm:items-center sm:justify-between">
                <div class="flex items-center gap-3 text-sm text-gray-600">
                    <span>{{ $movements->firstItem() ?? 0 }}–{{ $movements->lastItem() ?? 0 }} / {{ $movements->total() }} találat</span>
                    <label for="movement-per-page" class="sr-only">Találatok oldalanként</label>
                    <select id="movement-per-page" name="per_page" form="movement-filters" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} / oldal</option>@endforeach</select>
                </div>
                {{ $movements->onEachSide(1)->links('pagination.inventory') }}
            </div>
        </div>
    </div>
</x-app-layout>
