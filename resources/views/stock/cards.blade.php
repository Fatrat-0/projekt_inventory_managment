<div class="space-y-3 md:hidden">
    @foreach($stocks as $stock)
        <article data-stock-row="{{ $stock->id }}" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
            <header class="flex items-start justify-between gap-2 border-b border-gray-200 bg-gray-50 p-4">
                <div class="min-w-0"><h2 class="dashboard-text font-semibold text-gray-900"><a href="{{ route('products.show', $stock->product_id) }}" class="hover:text-indigo-700">{{ $stock->product->product_name }}</a></h2><p class="mt-1 break-all font-mono text-xs text-gray-500">{{ $stock->product->sku }}</p><p class="dashboard-text mt-1 text-xs text-gray-500">{{ $stock->product->category?->category_name ?? 'Kategória nélkül' }}</p></div>
                @include('stock.movement-link')
            </header>
            <div class="space-y-3 p-4">
                <p class="dashboard-text text-sm"><span class="text-gray-500">Raktár: </span><a href="{{ route('stock.index', array_replace($filters, ['warehouse_id' => $stock->warehouse_id])) }}" class="font-medium text-indigo-700 underline">{{ $stock->warehouse->warehouse_name }}</a></p>
                <dl class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,6rem),1fr))] gap-2">
                    <div class="min-w-0"><dt class="dashboard-text text-xs text-gray-500">Fizikai</dt><dd class="dashboard-text mt-1 text-sm font-semibold tabular-nums">{{ number_format($stock->quantity, 0, ',', ' ') }} db</dd></div>
                    <div class="min-w-0"><dt class="dashboard-text text-xs text-gray-500">Kiadható</dt><dd class="dashboard-text mt-1 text-sm font-semibold tabular-nums {{ $stock->available_quantity < $stock->min_quantity ? 'text-amber-800' : 'text-gray-900' }}">{{ number_format($stock->available_quantity, 0, ',', ' ') }} db</dd></div>
                    <div class="min-w-0"><dt class="dashboard-text text-xs text-gray-500">Elkülönített</dt><dd class="dashboard-text mt-1 text-sm tabular-nums">@include('stock.quarantine')</dd></div>
                </dl>
                <div class="border-t border-gray-100 pt-3"><p class="mb-1 text-xs text-gray-500">Helyi minimum</p>@include('stock.minimum')</div>
            </div>
        </article>
    @endforeach
</div>
