@php $shipment = $transfer->shipmentItem?->stop?->shipment; @endphp
<li class="space-y-2 border-b border-gray-100 p-4" data-dashboard-row data-pending-transfer="{{ $transfer->id }}">
    <div class="flex flex-wrap items-start justify-between gap-2"><a href="{{ $shipment ? route('shipments.show', $shipment) : route('transfers.show', $transfer) }}" class="break-all text-sm font-semibold text-indigo-700 hover:underline">{{ $transfer->transfer_number }}@if($shipment) · {{ $shipment->shipment_number }}@endif</a><span class="text-sm font-semibold tabular-nums text-gray-900">{{ number_format($transfer->quantity, 0, ',', ' ') }} db</span></div>
    <p class="break-all text-sm text-gray-900">{{ $transfer->product->product_name }}</p>
    <p class="break-all text-xs text-gray-500">{{ $transfer->fromWarehouse->warehouse_name }} → {{ $transfer->toWarehouse->warehouse_name }}</p>
    <p class="text-xs text-gray-500">Indítva: <time datetime="{{ $transfer->created_at->toIso8601String() }}">{{ $transfer->created_at->format('Y.m.d. H:i') }}</time></p>
</li>
