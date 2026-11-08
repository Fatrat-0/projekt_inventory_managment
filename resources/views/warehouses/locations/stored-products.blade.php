<div>
    <h3 class="text-sm font-semibold">Tárolt termékek</h3>
    <ul class="max-h-56 overflow-y-auto text-sm text-gray-700">
        @forelse($stored as $product)
            <li class="break-words py-1">{{ $product->product_name }} · {{ $product->sku }} · {{ $product->quantity }} db</li>
        @empty
            <li>A tárhely üres.</li>
        @endforelse
    </ul>
</div>
