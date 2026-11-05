<li class="space-y-2 border-b border-gray-100 p-4" data-dashboard-row data-product-alert="{{ $product->product_id }}">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0 flex-1"><a href="{{ route('products.index', ['search' => $product->sku, 'stock_status' => 'low']) }}" class="break-all font-semibold text-indigo-700 hover:underline">{{ $product->product_name }}</a><p class="mt-1 break-all text-xs text-gray-500">{{ $product->sku }}</p></div>
        <span class="rounded-md bg-red-50 px-2 py-1 text-sm font-semibold tabular-nums text-red-700">Hiány: {{ number_format($product->shortage, 0, ',', ' ') }} db</span>
    </div>
    <p class="text-sm font-semibold text-red-700">Kiadható: {{ number_format($product->total_available, 0, ',', ' ') }} db <span class="font-normal text-gray-500">· Minimum: {{ number_format($product->minimum_quantity, 0, ',', ' ') }} db</span></p>
    <p class="text-xs text-gray-500">Fizikai: {{ number_format($product->total_stock, 0, ',', ' ') }} db · Elkülönítve: {{ number_format($product->total_quarantined, 0, ',', ' ') }} db</p>
    <a href="{{ route('movements.index', ['product_id' => $product->product_id]) }}" class="inline-block text-xs font-medium text-indigo-700 hover:underline">Termék mozgásnaplója →</a>
</li>
