<li data-dashboard-row data-warehouse-stock="{{ $warehouse->warehouse_id }}" class="space-y-2 border-b border-gray-100 p-4">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <a href="{{ route('stock.index', ['warehouse_id' => $warehouse->warehouse_id]) }}" class="dashboard-text min-w-0 text-sm font-semibold text-indigo-700 hover:underline">{{ $warehouse->warehouse_name }}</a>
        <span class="text-sm font-semibold tabular-nums text-gray-900">{{ number_format($warehouse->physical, 0, ',', ' ') }} db</span>
    </div>
    <div aria-hidden="true" class="flex h-4 overflow-hidden rounded bg-gray-100">
        <div class="h-full bg-indigo-500" style="width: {{ 100 * $warehouse->available / $warehousePeak }}%"></div>
        <div class="h-full bg-amber-400" style="width: {{ 100 * $warehouse->quarantined / $warehousePeak }}%"></div>
    </div>
    <p class="text-xs text-gray-600">Kiadható: {{ number_format($warehouse->available, 0, ',', ' ') }} db · Elkülönítve: {{ number_format($warehouse->quarantined, 0, ',', ' ') }} db</p>
</li>
