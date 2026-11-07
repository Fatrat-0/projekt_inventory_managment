<x-app-layout>
    <div class="app-page space-y-4 py-4">
        <a href="{{ route('products.index') }}" class="inline-flex text-sm font-medium text-indigo-700 hover:underline">← Termékek</a>
        <x-page-toolbar :title="$product->product_name" :description="$product->sku">
            <a href="{{ route('stock.index', ['product_id' => $product->product_id]) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-indigo-700">Raktári készlet</a>
            <a href="{{ route('products.edit', $product) }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Termék szerkesztése</a>
        </x-page-toolbar>
        @php
            $physical = (int)($product->stocks_sum_quantity ?? 0);
            $quarantined = (int)($product->stocks_sum_quarantined_quantity ?? 0);
        @endphp
        <dl class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] gap-3">
            @foreach(['Fizikai készlet' => $physical, 'Kiadható készlet' => $physical - $quarantined, 'Elkülönített készlet' => $quarantined] as $label => $quantity)
                <div class="min-w-0 rounded-lg border border-gray-200 bg-white p-4"><dt class="text-xs text-gray-500">{{ $label }}</dt><dd class="dashboard-text mt-2 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($quantity, 0, ',', ' ') }} <span class="text-xs font-normal">db</span></dd></div>
            @endforeach
        </dl>
        <p class="text-xs text-gray-500">Minden raktárra összesítve, {{ number_format($product->stocks_count, 0, ',', ' ') }} raktári készletsorból. Az úton lévő árut a raktári készlet még nem tartalmazza.</p>
        <dl class="grid gap-4 rounded-lg border border-gray-200 bg-white p-5 sm:grid-cols-2">
            <div class="min-w-0"><dt class="text-xs text-gray-500">Cikkszám</dt><dd class="dashboard-text mt-1 font-mono text-sm text-gray-900">{{ $product->sku }}</dd></div>
            <div class="min-w-0"><dt class="text-xs text-gray-500">Kategória</dt><dd class="dashboard-text mt-1 text-sm text-gray-900">{{ $product->category?->category_name ?? 'Nincs megadva' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Egységár</dt><dd class="mt-1 text-sm text-gray-900">{{ number_format($product->price, 2, ',', ' ') }} Ft</dd></div>
            <div><dt class="text-xs text-gray-500">Termék saját globális minimuma</dt><dd class="mt-1 text-sm text-gray-900">{{ $product->min_stock_override === null ? 'Nincs felülbírálva' : number_format($product->min_stock_override, 0, ',', ' ').' db' }}</dd></div>
        </dl>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('movements.index', ['product_id' => $product->product_id]) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-indigo-700">Készletmozgások</a>
            @if($quarantined > 0)<a href="{{ route('quality-returns.index', ['status' => 'open', 'product_id' => $product->product_id]) }}" class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">Nyitott visszáru-minősítések</a>@endif
        </div>
    </div>
</x-app-layout>
