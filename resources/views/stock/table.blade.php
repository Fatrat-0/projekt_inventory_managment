<div class="relative hidden min-w-0 overflow-x-auto rounded-lg border border-gray-200 bg-white md:block" tabindex="0" role="region" aria-label="Készlettáblázat, vízszintesen görgethető">
    <table class="w-full text-left text-sm text-gray-600"><caption class="sr-only">Termékek raktárankénti készlete</caption>
        <thead class="border-b border-gray-200 bg-gray-50 text-xs"><tr>
            @foreach(\App\Queries\StockListing::SORTS as $key => $label)
                <th scope="col" aria-sort="{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}" class="px-2 py-2 {{ in_array($key, ['quantity', 'available', 'quarantined_quantity', 'min_quantity'], true) ? 'text-right' : '' }}">@include('stock.sort-link')</th>
            @endforeach
            <th scope="col" class="px-2 py-2"><span class="sr-only">Műveletek</span></th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($stocks as $stock)
                <tr data-stock-row="{{ $stock->id }}" class="hover:bg-gray-50">
                    <td class="min-w-44 max-w-xs px-4 py-3"><a href="{{ route('products.show', $stock->product_id) }}" class="dashboard-text font-semibold text-gray-900 hover:text-indigo-700">{{ $stock->product->product_name }}</a><p class="dashboard-text mt-1 text-xs text-gray-500">{{ $stock->product->category?->category_name ?? 'Kategória nélkül' }}</p></td>
                    <td class="max-w-40 break-all px-4 py-3 font-mono text-xs">{{ $stock->product->sku }}</td>
                    <td class="min-w-32 max-w-48 px-4 py-3"><a href="{{ route('stock.index', array_replace($filters, ['warehouse_id' => $stock->warehouse_id])) }}" class="dashboard-text text-indigo-700 hover:underline">{{ $stock->warehouse->warehouse_name }}</a></td>
                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-900">{{ number_format($stock->quantity, 0, ',', ' ') }} db</td>
                    <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $stock->available_quantity < $stock->min_quantity ? 'text-amber-800' : 'text-gray-900' }}">{{ number_format($stock->available_quantity, 0, ',', ' ') }} db</td>
                    <td class="px-4 py-3 text-right tabular-nums">@include('stock.quarantine')</td>
                    <td class="min-w-36 max-w-48 px-4 py-3 text-right">@include('stock.minimum')</td>
                    <td class="px-2 py-3">@include('stock.movement-link')</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
