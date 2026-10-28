<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-6 space-y-5">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Készletmozgások</a>
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-xl font-bold text-gray-900">{{ $movement->movement_number }}</h2>
            <span class="rounded-md px-2.5 py-1 text-xs font-semibold {{ $movement->type === 'in' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $movement->type === 'in' ? 'Bevételezés' : 'Kiadás' }}</span>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-5 sm:p-6 shadow-sm space-y-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                <div><dt class="text-gray-500">Termék</dt><dd class="mt-1 font-semibold text-gray-900">{{ $movement->product->product_name }}</dd><dd class="mt-1 font-mono text-xs text-gray-500">{{ $movement->product->sku }}</dd></div>
                <div><dt class="text-gray-500">Raktár</dt><dd class="mt-1 font-medium text-gray-900">{{ $movement->warehouse->warehouse_name }}</dd></div>
                <div><dt class="text-gray-500">Rögzítve</dt><dd class="mt-1 text-gray-900">{{ $movement->created_at->format('Y.m.d H:i:s') }}</dd></div>
                <div><dt class="text-gray-500">Rögzítette</dt><dd class="mt-1 text-gray-900">{{ $movement->user->name }}</dd></div>
                <div><dt class="text-gray-500">Mennyiség</dt><dd class="mt-1 font-semibold {{ $movement->type === 'in' ? 'text-green-700' : 'text-red-700' }}">{{ $movement->type === 'in' ? '+' : '−' }}{{ number_format($movement->quantity, 0, ',', ' ') }} db</dd></div>
                <div><dt class="text-gray-500">Bizonylatszám vagy megjegyzés</dt><dd class="mt-1 text-gray-900 break-words whitespace-pre-wrap">{{ $movement->reference ?? '—' }}</dd></div>
            </dl>
            <section class="border-t border-gray-100 pt-5" aria-label="Készletváltozás">
                <h3 class="mb-3 text-sm font-semibold text-gray-700">Raktári készlet a művelet pillanatában</h3>
                @if($movement->stock_before !== null && $movement->stock_after !== null)
                    <div class="grid grid-cols-2 gap-4 rounded-lg bg-gray-50 p-4">
                        <div><p class="text-xs text-gray-500">Művelet előtt</p><p class="mt-1 text-xl font-semibold text-gray-900">{{ number_format($movement->stock_before, 0, ',', ' ') }} db</p></div>
                        <div><p class="text-xs text-gray-500">Művelet után</p><p class="mt-1 text-xl font-semibold text-gray-900">{{ number_format($movement->stock_after, 0, ',', ' ') }} db</p></div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Ezek a rögzítéskori egyenlegek; a jelenlegi készlet azóta megváltozhatott.</p>
                @else
                    <p class="rounded-md bg-gray-50 p-3 text-sm text-gray-600">Ehhez a korábbi tételhez nem áll rendelkezésre rögzített előtte–utána készletegyenleg.</p>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
