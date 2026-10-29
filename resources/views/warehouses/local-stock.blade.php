<div class="hidden overflow-x-auto rounded-lg border border-gray-200 bg-white md:block">
    <table class="w-full text-left text-sm text-gray-600"><caption class="sr-only">Termékek helyi készlete</caption><thead class="border-b border-gray-200 bg-gray-50 text-xs"><tr>
        @foreach(['product_name' => 'Termék', 'sku' => 'Cikkszám', 'quantity' => 'Helyi készlet'] as $column => $label)
            @php
                $active = $filters['sort_by'] === $column;
                $nextDirection = $active && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc';
            @endphp
            <th scope="col" aria-sort="{{ $active ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}" class="px-4 py-3 {{ $column === 'quantity' ? 'text-right' : '' }}">
                <a href="{{ route('warehouses.show', $baseParams + array_replace($filters, ['sort_by' => $column, 'sort_dir' => $nextDirection])) }}#local-stock"
                    onclick="if (!event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) { event.preventDefault(); const form = document.getElementById('local-stock-filters'); form.elements.namedItem('sort_by').value = '{{ $column }}'; form.elements.namedItem('sort_dir').value = '{{ $nextDirection }}'; form.requestSubmit(); }"
                    title="{{ $nextDirection === 'asc' ? 'Növekvő rendezés' : 'Csökkenő rendezés' }}" class="inline-flex items-center gap-1 whitespace-nowrap hover:text-indigo-600 focus-visible:ring-2 focus-visible:ring-indigo-500">{{ $label }}@if($active)<span aria-hidden="true">{{ $filters['sort_dir'] === 'asc' ? '↑' : '↓' }}</span>@endif</a>
            </th>
        @endforeach
        <th scope="col" class="px-4 py-3"><span class="sr-only">Mozgások</span></th>
    </tr></thead>
        <tbody class="divide-y divide-gray-100">@forelse($stocks as $stock)<tr data-stock-product-id="{{ $stock->product_id }}"><td class="px-4 py-3 font-medium text-gray-900">{{ $stock->product->product_name }}</td><td class="px-4 py-3 font-mono text-xs">{{ $stock->product->sku }}</td><td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-gray-900">{{ number_format($stock->quantity, 0, ',', ' ') }} db</td><td class="w-12 px-3 py-2">@include('warehouses.stock-movements-link')</td></tr>@empty<tr><td colspan="4" role="status" class="px-4 py-8 text-center">Nincs a szűrésnek megfelelő helyi készletsor.</td></tr>@endforelse</tbody>
    </table>
</div>
<div class="space-y-3 md:hidden">@forelse($stocks as $stock)<article data-stock-product-id="{{ $stock->product_id }}" class="rounded-lg border border-gray-200 bg-white p-4"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><h4 class="break-words text-sm font-medium text-gray-900">{{ $stock->product->product_name }}</h4><p class="mt-1 break-all font-mono text-xs text-gray-500">{{ $stock->product->sku }}</p></div>@include('warehouses.stock-movements-link')</div><p class="mt-3 text-sm text-gray-500">Helyi készlet: <strong class="tabular-nums text-gray-900">{{ number_format($stock->quantity, 0, ',', ' ') }} db</strong></p></article>@empty<p role="status" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">Nincs a szűrésnek megfelelő helyi készletsor.</p>@endforelse</div>
