<a href="{{ route('products.index', ['warehouse_id' => $warehouse->warehouse_id, 'sort_by' => 'product_name', 'sort_dir' => 'asc']) }}"
    aria-label="{{ $warehouse->warehouse_name }}: {{ number_format($warehouse->products_count, 0, ',', ' ') }} készleten lévő termékfajta megtekintése"
    title="Az ebben a raktárban pozitív készlettel rendelkező termékek megtekintése"
    class="-mx-1.5 -my-0.5 inline-flex min-w-6 items-center rounded px-1.5 py-0.5 font-semibold text-indigo-600 underline decoration-indigo-200 underline-offset-4 hover:bg-indigo-50 hover:decoration-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
    {{ number_format($warehouse->products_count, 0, ',', ' ') }}
</a>
