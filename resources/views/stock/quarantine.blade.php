@if($stock->quarantined_quantity > 0)
    <a href="{{ route('quality-returns.index', ['status' => 'open', 'product_id' => $stock->product_id, 'warehouse_id' => $stock->warehouse_id]) }}" data-quality-link class="font-medium text-amber-800 underline hover:text-amber-900" aria-label="Elkülönített készlet minősítése: {{ $stock->product->product_name }} · {{ $stock->warehouse->warehouse_name }}">{{ number_format($stock->quarantined_quantity, 0, ',', ' ') }} db</a>
@else
    <span class="text-gray-500">0 db</span>
@endif
