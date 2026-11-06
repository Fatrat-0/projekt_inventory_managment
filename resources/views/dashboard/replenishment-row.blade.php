@php($suggested = min((int)$replenishment->shortage, (int)($replenishment->surplus ?? 0)))
<li data-dashboard-row data-replenishment="{{ $replenishment->id }}" class="space-y-2 border-b border-gray-100 p-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <a href="{{ route('products.show', $replenishment->product_id) }}" class="dashboard-text min-w-0 text-sm font-semibold text-indigo-700 hover:underline">{{ $replenishment->product_name }} <span class="font-normal text-gray-500">· {{ $replenishment->sku }}</span></a>
        <span class="text-sm font-semibold text-amber-800">Még hiányzik: {{ number_format($replenishment->shortage, 0, ',', ' ') }} db</span>
    </div>
    <p class="dashboard-text text-sm text-gray-800">{{ $replenishment->warehouse_name }} · Kiadható: {{ number_format($replenishment->available, 0, ',', ' ') }} db · Helyi minimum: {{ number_format($replenishment->min_quantity, 0, ',', ' ') }} db · Úton ide: {{ number_format($replenishment->incoming, 0, ',', ' ') }} db</p>
    @if($suggested > 0)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="dashboard-text text-sm text-gray-600">{{ $replenishment->source_warehouse_name }} → {{ $replenishment->warehouse_name }} · {{ number_format($suggested, 0, ',', ' ') }} db @if($suggested < $replenishment->shortage) <span class="text-amber-800">(részleges pótlás)</span>@endif</p>
            <a data-replenishment-prepare href="{{ route('transfers.create', ['replenishment_stock_id' => $replenishment->id, 'source_stock_id' => $replenishment->source_stock_id]) }}" class="inline-flex min-h-11 items-center rounded-md border border-indigo-200 px-3 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50">Átmozgatás előkészítése →</a>
        </div>
    @else
        <p class="text-sm font-medium text-amber-800">Nincs helyi minimum feletti, kiadható készlet másik raktárban. Külső pótlás szükséges.</p>
    @endif
</li>
