<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-6 space-y-5">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Átmozgatások</a>
        <x-operation-feedback />
        <div class="flex flex-wrap items-center gap-3"><h2 class="text-xl font-bold text-gray-900">{{ $transfer->transfer_number }}</h2><span class="rounded-md bg-gray-100 px-2.5 py-1 text-sm">{{ \App\Models\InventoryTransfer::STATUSES[$transfer->status] }}</span></div>
        <div class="rounded-lg border border-gray-200 bg-white p-5 space-y-5">
            <div><p class="font-semibold text-gray-900">{{ $transfer->product->product_name }}</p><p class="font-mono text-xs text-gray-500">{{ $transfer->product->sku }}</p></div>
            <dl class="grid sm:grid-cols-2 gap-5 text-sm">
                <div><dt class="text-gray-500">Forrásraktár</dt><dd class="mt-1 font-medium">{{ $transfer->fromWarehouse->warehouse_name }}</dd></div>
                <div><dt class="text-gray-500">Célraktár</dt><dd class="mt-1 font-medium">{{ $transfer->toWarehouse->warehouse_name }}</dd></div>
                <div><dt class="text-gray-500">Mennyiség</dt><dd class="mt-1 font-semibold">{{ number_format($transfer->quantity, 0, ',', ' ') }} db</dd></div>
                <div><dt class="text-gray-500">Elindította</dt><dd class="mt-1">{{ $transfer->user->name }} · {{ $transfer->created_at->format('Y.m.d H:i') }}</dd></div>
            </dl>
            <p class="text-xs text-gray-500">Az úton lévő mennyiség már nem elérhető a forrásraktárban. A célraktár készlete az átvételkor növekszik.</p>
            <x-transfer-actions :transfer="$transfer" :context="$context" />
            <a href="{{ route('export.transfer', $transfer) }}" class="inline-block text-sm text-indigo-600 underline">Szállítólevél letöltése (PDF)</a>
        </div>
    </div>
</x-app-layout>
