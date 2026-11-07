<div data-stock-table data-listing-table class="relative min-w-0 overflow-x-auto rounded-lg border border-gray-200 bg-white" tabindex="0" role="region" aria-label="Készlettáblázat, vízszintesen görgethető">
    <table class="w-full text-left text-sm text-gray-600"><caption class="sr-only">Termékek raktárankénti készlete</caption>
        <thead class="border-b border-gray-200 bg-gray-50 text-xs"><tr>
            @foreach(\App\Queries\StockListing::SORTS as $key => $label)
                <th data-listing-column="{{ $key }}" x-show="columnVisible('{{ $key }}')" scope="col" aria-sort="{{ $filters['sort_by'] === $key ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}" class="px-2 py-2 {{ in_array($key, ['quantity', 'available', 'quarantined_quantity', 'min_quantity'], true) ? 'text-right' : '' }}">@include('stock.sort-link')</th>
            @endforeach
            <th data-listing-column="actions" x-show="columnVisible('actions')" scope="col" class="px-2 py-2"><span class="sr-only">Műveletek</span><x-listing-columns /></th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($stocks as $stock)
                <tr data-stock-row="{{ $stock->id }}" class="hover:bg-gray-50">
                    <td data-listing-column="product_name" x-show="columnVisible('product_name')" class="min-w-44 max-w-xs px-4 py-3"><a href="{{ route('products.show', $stock->product_id) }}" class="dashboard-text font-semibold text-gray-900 hover:text-indigo-700">{{ $stock->product->product_name }}</a><p class="dashboard-text mt-1 text-xs text-gray-500">{{ $stock->product->category?->category_name ?? 'Kategória nélkül' }}</p></td>
                    <td data-listing-column="sku" x-show="columnVisible('sku')" class="max-w-40 break-all px-4 py-3 font-mono text-xs">{{ $stock->product->sku }}</td>
                    <td data-listing-column="warehouse_name" x-show="columnVisible('warehouse_name')" class="min-w-32 max-w-48 px-4 py-3"><a href="{{ route('stock.index', array_replace($filters, ['warehouse_id' => $stock->warehouse_id])) }}" class="dashboard-text text-indigo-700 hover:underline">{{ $stock->warehouse->warehouse_name }}</a></td>
                    <td data-listing-column="quantity" x-show="columnVisible('quantity')" class="px-4 py-3 text-right font-semibold tabular-nums text-gray-900">{{ number_format($stock->quantity, 0, ',', ' ') }} db</td>
                    <td data-listing-column="available" x-show="columnVisible('available')" class="px-4 py-3 text-right font-semibold tabular-nums {{ $stock->available_quantity < $stock->min_quantity ? 'text-amber-800' : 'text-gray-900' }}">{{ number_format($stock->available_quantity, 0, ',', ' ') }} db</td>
                    <td data-listing-column="quarantined_quantity" x-show="columnVisible('quarantined_quantity')" class="px-4 py-3 text-right tabular-nums">@include('stock.quarantine')</td>
                    <td data-listing-column="min_quantity" x-show="columnVisible('min_quantity')" class="min-w-36 max-w-48 px-4 py-3 text-right">@include('stock.minimum', ['variant' => 'table'])</td>
                    <td data-listing-column="actions" x-show="columnVisible('actions')" class="px-2 py-3">@include('stock.actions', ['variant' => 'table'])</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
