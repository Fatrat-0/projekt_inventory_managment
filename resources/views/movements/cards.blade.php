<div data-listing-cards>
    @forelse($movements as $movement)
        <article data-movement-id="{{ $movement->id }}" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            <header class="flex flex-wrap items-start justify-between gap-2">
                <a href="{{ route('movements.show', ['movement' => $movement, 'context' => $listContext]) }}" class="font-mono text-xs text-indigo-600 hover:underline">{{ $movement->movement_number }}</a>
                <span class="rounded-md px-2 py-1 text-xs font-medium {{ $movement->type === 'in' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">{{ $movement->type === 'in' ? 'Bevételezés' : 'Kiadás' }}</span>
            </header>
            <div><h3 class="font-semibold text-gray-900"><a href="{{ route('movements.show', ['movement' => $movement, 'context' => $listContext]) }}" class="hover:text-indigo-600 hover:underline">{{ $movement->product->product_name }}</a></h3><p class="mt-1 break-all font-mono text-xs text-gray-500">{{ $movement->product->sku }}</p></div>
            <dl class="grid grid-cols-2 gap-3 rounded-md bg-gray-50 p-3 text-sm">
                <div><dt class="text-xs text-gray-500">Mennyiség</dt><dd class="mt-1 font-semibold tabular-nums {{ $movement->type === 'in' ? 'text-green-800' : 'text-red-800' }}">{{ $movement->type === 'in' ? '+' : '−' }}{{ number_format($movement->quantity, 0, ',', ' ') }} db</dd></div>
                <div><dt class="text-xs text-gray-500">Időpont</dt><dd class="mt-1">{{ $movement->created_at->format('Y.m.d H:i') }}</dd></div>
                <div><dt class="text-xs text-gray-500">Raktár</dt><dd class="mt-1">{{ $movement->warehouse->warehouse_name }}</dd></div>
                <div><dt class="text-xs text-gray-500">Rögzítette</dt><dd class="mt-1">{{ $movement->user->name }}</dd></div>
            </dl>
            <div class="border-t border-gray-100 pt-3 text-sm text-gray-600"><p class="mb-1 text-xs text-gray-500">Hivatkozás</p>@include('movements.list-reference')</div>
        </article>
    @empty
        <p role="status" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">Nincs a feltételeknek megfelelő készletmozgás. @if($filterLabels)<a href="{{ route('movements.index') }}" class="mt-2 block text-indigo-600 underline">Szűrők törlése</a>@endif</p>
    @endforelse
</div>
