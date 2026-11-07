<div data-listing-cards>
    @forelse($transfers as $transfer)
        <article data-transfer-id="{{ $transfer->id }}" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            <header class="flex flex-wrap items-start justify-between gap-2">
                <a href="{{ route('transfers.show', ['transfer' => $transfer, 'context' => $context]) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $transfer->transfer_number }}</a>
                <span class="rounded-md px-2 py-1 text-xs font-medium {{ match($transfer->status) { 'pending' => 'bg-amber-50 text-amber-800', 'completed' => 'bg-green-50 text-green-800', default => 'bg-gray-100 text-gray-600' } }}">{{ \App\Models\InventoryTransfer::STATUSES[$transfer->status] }}</span>
            </header>
            <div><h3 class="font-semibold text-gray-900"><a href="{{ route('transfers.show', ['transfer' => $transfer, 'context' => $context]) }}" class="hover:text-indigo-600 hover:underline">{{ $transfer->product->product_name }}</a></h3><p class="mt-1 break-all font-mono text-xs text-gray-500">{{ $transfer->product->sku }}</p></div>
            <dl class="grid grid-cols-2 gap-3 rounded-md bg-gray-50 p-3 text-sm">
                <div><dt class="text-xs text-gray-500">Mennyiség</dt><dd class="mt-1 font-semibold tabular-nums">{{ number_format($transfer->quantity, 0, ',', ' ') }} db</dd></div>
                <div><dt class="text-xs text-gray-500">Indítás</dt><dd class="mt-1">{{ $transfer->created_at->format('Y.m.d H:i') }}</dd></div>
                <div><dt class="text-xs text-gray-500">Forrásraktár</dt><dd class="mt-1">{{ $transfer->fromWarehouse->warehouse_name }}</dd></div>
                <div><dt class="text-xs text-gray-500">Célraktár</dt><dd class="mt-1">{{ $transfer->toWarehouse->warehouse_name }}</dd></div>
            </dl>
            <footer class="space-y-3 border-t border-gray-100 pt-3"><x-transfer-actions :transfer="$transfer" :context="$context" /><a href="{{ route('export.transfer', $transfer) }}" class="inline-block text-xs text-gray-500 underline">Szállítólevél (PDF)</a></footer>
        </article>
    @empty
        <p role="status" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">Nincs a feltételeknek megfelelő átmozgatás.</p>
    @endforelse
</div>
