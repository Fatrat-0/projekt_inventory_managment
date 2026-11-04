<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8" @pageshow.window="if ($event.persisted) window.location.reload()"
        x-data="{ status(value, event) { if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); this.$refs.status.value = value; this.$refs.filtersForm.requestSubmit(); }, sort(value, direction, event) { if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); this.$refs.sortBy.value = value; this.$refs.sortDir.value = direction; this.$refs.filtersForm.requestSubmit(); } }">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-xl font-bold text-gray-900">Minősítési munkalista</h2><p class="mt-1 text-sm text-gray-500">Vevői visszáruk vizsgálata és az elkülönített készlet feldolgozása.</p></div>
            <a href="{{ route('movements.index') }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Készletmozgások</a>
        </div>
        <x-operation-feedback />
        <section aria-label="Minősítési összesítők" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach(['open' => ['Nyitott', 'open_count'], 'waiting' => ['Várakozik', 'waiting_count'], 'partial' => ['Részben minősítve', 'partial_count'], 'closed' => ['Lezárt', 'closed_count']] as $value => [$label, $key])
                <a href="{{ route('quality-returns.index', array_replace($filters, ['status' => $value])) }}" @click="status('{{ $value }}', $event)"
                    @if($filters['status'] === $value) aria-current="page" @endif
                    class="rounded-lg border bg-white p-4 shadow-sm hover:border-indigo-400 {{ $filters['status'] === $value ? 'border-indigo-400 ring-1 ring-indigo-200' : 'border-gray-200' }}">
                    <span class="block text-xs font-medium text-gray-500">{{ $label }}</span><strong class="mt-2 block text-2xl tabular-nums text-gray-900">{{ number_format($summary[$key], 0, ',', ' ') }}</strong>
                    @if($value === 'open')<span class="mt-1 block text-xs text-amber-800">{{ number_format($summary['pending_quantity'], 0, ',', ' ') }} db vár minősítésre</span>@endif
                </a>
            @endforeach
        </section>
        @if($summary['oldest_at'])<p class="text-xs text-gray-500">Legrégebbi nyitott beérkezés: {{ $summary['oldest_at']->format('Y.m.d H:i') }}. Az összesítők a kereséssel és a többi szűrővel egyező tételekre vonatkoznak.</p>@endif
        <form id="quality-filters" x-ref="filtersForm" method="GET" action="{{ route('quality-returns.index') }}" class="space-y-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <input type="hidden" x-ref="sortBy" name="sort_by" value="{{ $filters['sort_by'] }}"><input type="hidden" x-ref="sortDir" name="sort_dir" value="{{ $filters['sort_dir'] }}">
            <input type="hidden" name="product_id" value="{{ $filters['product_id'] }}">
            <div class="flex min-w-0 items-center gap-3">
                <x-listing-search :items="$suggestions" :query="$filters['search']" id="quality-search" label="Minősítések keresése" placeholder="Termék, cikkszám, partner, indok vagy MOZ-azonosító…" filter-name="product_id" :clear-filter-on-edit="true" results-label="Termékjavaslatok" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div><label for="quality-status" class="mb-1 block text-xs font-medium text-gray-600">Állapot</label><select id="quality-status" x-ref="status" name="status" onchange="this.form.requestSubmit()" class="w-full rounded-md border-gray-300 text-sm">
                    @foreach(\App\Queries\ReturnQualityListing::STATUSES as $value => $label)<option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>@endforeach
                </select></div>
                <div><label for="quality-warehouse" class="mb-1 block text-xs font-medium text-gray-600">Raktár</label><select id="quality-warehouse" name="warehouse_id" onchange="this.form.requestSubmit()" class="w-full rounded-md border-gray-300 text-sm"><option value="">Összes raktár</option>
                    @foreach($warehouses as $warehouse)<option value="{{ $warehouse->warehouse_id }}" @selected((string)$filters['warehouse_id'] === (string)$warehouse->warehouse_id)>{{ $warehouse->warehouse_name }}</option>@endforeach
                </select></div>
                <div><label for="quality-partner" class="mb-1 block text-xs font-medium text-gray-600">Partner</label><select id="quality-partner" name="partner_id" onchange="this.form.requestSubmit()" class="w-full rounded-md border-gray-300 text-sm"><option value="">Összes partner</option>
                    @foreach($partners as $partner)<option value="{{ $partner->id }}" @selected((string)$filters['partner_id'] === (string)$partner->id)>{{ $partner->name }} · #{{ $partner->id }}{{ $partner->isArchived() ? ' · Archivált' : '' }}</option>@endforeach
                </select></div>
                <div class="flex items-end"><button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Szűrés alkalmazása</button></div>
            </div>
            <div class="grid grid-cols-2 gap-3 md:hidden">
                <div><label for="quality-mobile-sort" class="mb-1 block text-xs font-medium text-gray-600">Rendezés</label><select id="quality-mobile-sort" onchange="this.form.elements.namedItem('sort_by').value=this.value; this.form.requestSubmit()" class="w-full rounded-md border-gray-300 text-sm">
                    @foreach(\App\Queries\ReturnQualityListing::SORTS as $value => $label)<option value="{{ $value }}" @selected($filters['sort_by'] === $value)>{{ $label }}</option>@endforeach
                </select></div>
                <div><label for="quality-mobile-dir" class="mb-1 block text-xs font-medium text-gray-600">Rendezés iránya</label><select id="quality-mobile-dir" onchange="this.form.elements.namedItem('sort_dir').value=this.value; this.form.requestSubmit()" class="w-full rounded-md border-gray-300 text-sm"><option value="asc" @selected($filters['sort_dir'] === 'asc')>Növekvő</option><option value="desc" @selected($filters['sort_dir'] === 'desc')>Csökkenő</option></select></div>
            </div>
        </form>
        @if($filterLabels)
            <div class="flex flex-wrap items-center gap-2" aria-label="Aktív minősítési szűrők">
                @foreach($filterLabels as $key => $label)<a href="{{ request()->fullUrlWithQuery([$key => null, 'page' => null]) }}" aria-label="{{ $label }} szűrő eltávolítása" class="max-w-full break-words rounded-md border border-indigo-100 bg-indigo-50 px-2 py-1 text-xs text-indigo-800">{{ $label }} ×</a>@endforeach
                <a href="{{ route('quality-returns.index') }}" class="text-xs text-gray-600 underline">Szűrők visszaállítása</a>
            </div>
        @endif
        <div class="relative hidden overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm md:block">
            <table class="w-full text-left text-sm text-gray-600"><thead class="border-b bg-gray-50 text-xs uppercase text-gray-600"><tr>
                @foreach(\App\Queries\ReturnQualityListing::SORTS as $key => $label)
                    @php
                        $direction = $filters['sort_by'] === $key && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc';
                    @endphp
                    <th scope="col" class="whitespace-nowrap px-4 py-3 {{ str_ends_with($key, '_quantity') ? 'text-right' : '' }}" aria-sort="{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                        <a href="{{ route('quality-returns.index', array_replace($filters, ['sort_by' => $key, 'sort_dir' => $direction])) }}" @click="sort('{{ $key }}', '{{ $direction }}', $event)" class="hover:text-indigo-600">{{ $label }} <span class="{{ $filters['sort_by'] === $key ? 'text-indigo-600' : 'text-gray-400' }}">{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? '↑' : '↓') : '↕' }}</span></a>
                    </th>
                @endforeach
                <th scope="col" class="px-4 py-3">Állapot</th><th scope="col" class="px-4 py-3"><span class="sr-only">Művelet</span></th>
            </tr></thead><tbody class="divide-y divide-gray-100">
                @forelse($returns as $return)
                    @php
                        $status = \App\Queries\ReturnQualityListing::status($return);
                        $days = max(0, (int)$return->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay(), false));
                        $detailUrl = route('movements.show', ['movement' => $return, 'context' => $listContext]);
                    @endphp
                    <tr data-quality-return-id="{{ $return->id }}" class="hover:bg-indigo-50/30">
                        <td class="whitespace-nowrap px-4 py-3">{{ $return->created_at->format('Y.m.d H:i') }}@if($status !== 'closed')<span class="mt-1 block text-xs text-gray-500">{{ $days === 0 ? 'Ma érkezett' : $days.' napja vár' }}</span>@endif</td>
                        <td class="min-w-[180px] max-w-xs px-4 py-3 break-all"><a href="{{ $detailUrl }}" class="block font-medium text-gray-900 hover:text-indigo-600">{{ $return->product->product_name }}</a><span class="mt-1 block font-mono text-xs text-gray-500">{{ $return->product->sku }}</span><a href="{{ $detailUrl }}" class="mt-1 block font-mono text-xs text-indigo-600 hover:underline">{{ $return->movement_number }}</a>@if($return->partner_name !== null)<span class="mt-1 block max-w-xs break-words text-xs text-gray-500">{{ $return->partner_name }}</span>@endif</td>
                        <td class="px-4 py-3 break-words">{{ $return->warehouse->warehouse_name }}</td>
                        @foreach(['original' => $return->quarantined_quantity, 'released' => $return->released_quantity, 'scrapped' => $return->scrapped_quantity, 'remaining' => $return->remaining_quantity] as $kind => $quantity)
                            <td data-quality-quantity="{{ $kind }}" class="whitespace-nowrap px-4 py-3 text-right tabular-nums {{ $kind === 'remaining' ? 'font-semibold text-amber-800' : '' }}">{{ number_format((int)$quantity, 0, ',', ' ') }} db</td>
                        @endforeach
                        <td class="px-4 py-3">@include('quality-returns.status')</td><td class="px-4 py-3"><a href="{{ $detailUrl }}" class="whitespace-nowrap text-sm font-medium text-indigo-600 hover:underline">{{ $status === 'closed' ? 'Megtekintés' : 'Minősítés' }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="9" role="status" class="p-8 text-center text-gray-500">Nincs a szűrésnek megfelelő visszáru.</td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div class="space-y-3 md:hidden">
            @forelse($returns as $return)
                @php
                    $status = \App\Queries\ReturnQualityListing::status($return);
                    $days = max(0, (int)$return->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay(), false));
                    $detailUrl = route('movements.show', ['movement' => $return, 'context' => $listContext]);
                @endphp
                <article data-quality-return-id="{{ $return->id }}" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2"><a href="{{ $detailUrl }}" class="font-mono text-xs text-indigo-600">{{ $return->movement_number }}</a>@include('quality-returns.status')</div>
                    <div><h3 class="break-words font-semibold text-gray-900">{{ $return->product->product_name }}</h3><p class="mt-1 break-all font-mono text-xs text-gray-500">{{ $return->product->sku }}</p></div>
                    <p class="break-words text-sm text-gray-600">{{ $return->warehouse->warehouse_name }}@if($return->partner_name !== null)<span class="mt-1 block">{{ $return->partner_name }}</span>@endif</p>
                    <p class="text-xs text-gray-500">{{ $return->created_at->format('Y.m.d H:i') }} · {{ $status === 'closed' ? 'Lezárt' : ($days === 0 ? 'Ma érkezett' : $days.' napja vár') }}</p>
                    <dl class="grid grid-cols-2 gap-3 rounded-md bg-gray-50 p-3 text-sm">
                        @foreach(['original' => ['Eredeti', $return->quarantined_quantity], 'released' => ['Felszabadítva', $return->released_quantity], 'scrapped' => ['Selejtezve', $return->scrapped_quantity], 'remaining' => ['Hátralévő', $return->remaining_quantity]] as $kind => [$label, $quantity])
                            <div><dt class="text-xs text-gray-500">{{ $label }}</dt><dd data-quality-quantity="{{ $kind }}" class="mt-1 font-semibold tabular-nums {{ $kind === 'remaining' ? 'text-amber-800' : 'text-gray-900' }}">{{ number_format((int)$quantity, 0, ',', ' ') }} db</dd></div>
                        @endforeach
                    </dl>
                    <a href="{{ $detailUrl }}" class="block rounded-md border border-indigo-200 px-3 py-2 text-center text-sm font-semibold text-indigo-600">{{ $status === 'closed' ? 'Megtekintés' : 'Minősítés' }}</a>
                </article>
            @empty
                <p role="status" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">Nincs a szűrésnek megfelelő visszáru.</p>
            @endforelse
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600"><div class="flex flex-wrap items-center gap-3"><span>{{ $returns->firstItem() ?? 0 }}–{{ $returns->lastItem() ?? 0 }} / {{ $returns->total() }} találat</span><label for="quality-per-page" class="sr-only">Minősítések oldalanként</label><select id="quality-per-page" name="per_page" form="quality-filters" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} / oldal</option>@endforeach</select></div>{{ $returns->links('pagination.inventory') }}</div>
        <p class="text-xs text-gray-500">A munkalista az elkülönítve bevett vevői visszárukat tartalmazza. A hátralévő mennyiség az adott visszáruból még minősítendő áru.</p>
    </div>
</x-app-layout>
