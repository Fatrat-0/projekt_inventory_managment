<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-4 space-y-4 [overflow-wrap:anywhere]">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">{{ $returnLabel }}</a>
        <x-operation-feedback />
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-bold text-gray-900">{{ $movement->movement_number }}</h1>
            <div class="space-y-1 text-right">@include('movements.origin')</div>
        </div>
        <a href="{{ route('movements.index', ['product_id' => $movement->product_id]) }}" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Termék mozgásai</a>
        @if($movement->isReturn()) @include('movements.return-history') @endif
        @if($originLink)
            <a href="{{ $originLink['url'] }}" class="inline-block text-sm font-medium text-indigo-700 underline">{{ $originLink['label'] }}</a>
        @endif
        @if($movement->isScrap())
            <section class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900" aria-label="Selejtezés">
                <h3 class="font-semibold">Selejtezés elkülönített visszáruból</h3>
                <a href="{{ route('movements.show', ['movement' => $movement->scrap_of_id, 'context' => $context]) }}" class="underline">Kapcsolódó visszáru</a>
                <p class="mt-2 whitespace-pre-wrap break-words">{{ $movement->qualityAction?->reason }}</p>
            </section>
        @endif
        <div class="rounded-lg border border-gray-200 bg-white p-5 sm:p-6 shadow-sm space-y-4">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                <div class="sm:col-span-2"><dt class="text-gray-500">Partner a rögzítéskor</dt><dd class="mt-1 break-words text-gray-900">
                    @if($movement->partner_id)
                        <a href="{{ route('partners.edit', $movement->partner_id) }}" class="font-medium text-indigo-600 hover:underline">{{ $movement->partner_name }}</a>
                        @if($movement->partner?->isArchived())<span class="ml-1 text-xs text-gray-500">Archivált</span>@endif
                        @if($movement->partner && $movement->partner->name !== $movement->partner_name)<p class="mt-1 text-xs text-gray-500">Jelenlegi név: {{ $movement->partner->name }}</p>@endif
                        <a href="{{ route('movements.index', ['partner_id' => $movement->partner_id]) }}" class="mt-1 block text-xs text-indigo-600 underline">Partner összes mozgása</a>
                    @else Nincs megadva @endif
                </dd></div>
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
        @if($receiptBatch)
            @php($batch = $receiptBatch->state())
            <section class="space-y-3 rounded-lg border border-indigo-200 bg-white p-4 sm:p-5" aria-labelledby="receipt-putaway-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="receipt-putaway-title" class="font-semibold text-gray-900">A bevételezés betárolása</h2>
                    <p class="rounded-md bg-indigo-50 px-2 py-1 text-sm font-medium text-indigo-900">{{ ['pending' => 'Betárolásra vár', 'partial' => 'Részben betárolva', 'completed' => 'Teljesen betárolva'][$batch['status']] }}</p>
                </div>
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
                    @foreach(['received_quantity' => 'Eredetileg bevételezve', 'placed_quantity' => 'Már betárolva', 'pending_putaway_quantity' => 'Betárolásra vár'] as $key => $label)
                        <div><dt class="text-gray-600">{{ $label }}</dt><dd class="font-semibold">{{ number_format($batch[$key], 0, ',', ' ') }} db</dd></div>
                    @endforeach
                </dl>
                @if($canPutaway)
                    @if($recentPutaways->isNotEmpty())
                        <div class="border-t border-gray-100 pt-3 text-sm">
                            <h3 class="font-semibold text-gray-900">Utolsó elhelyezések</h3>
                            <ul class="divide-y divide-gray-100">
                                @foreach($recentPutaways as $placement)
                                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                                        <span><strong>{{ $placement->location_code }}</strong> · {{ number_format($placement->quantity, 0, ',', ' ') }} db</span>
                                        <time datetime="{{ $placement->placed_at->toISOString() }}" class="text-gray-600">{{ $placement->placed_at->timezone('Europe/Budapest')->format('Y. m. d. H:i') }}</time>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm font-medium">
                        @if($batch['can_putaway'])
                            <a class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-3 py-2 font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                               href="{{ route('warehouse-putaway-pages.manual', $receiptBatch) }}">Betárolás folytatása</a>
                        @endif
                        <a class="inline-flex min-h-11 items-center text-indigo-700 underline" href="{{ route('warehouse-putaway-pages.manual', $receiptBatch) }}#putaway-history-title">Betárolási előzmények</a>
                    </div>
                @endif
            </section>
        @endif
        @if($hasIssueSources)
            <section class="rounded-lg border border-gray-200 bg-white p-5" aria-labelledby="issue-history-title">
                <h2 id="issue-history-title" class="font-semibold text-gray-900">Kiadási forráshelyek</h2>
                @if($movement->issueSources->isEmpty())
                    <p class="mt-2 text-sm text-gray-600">Ennél a korábbi mozgásnál még nem történt forráshelyenkénti naplózás.</p>
                @else
                    <dl class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach($movement->issueSources as $source)
                            <div class="flex items-start justify-between gap-3 py-2">
                                <dt class="min-w-0 break-words">
                                    @if($source->source_type === 'unassigned') Tárhely nélküli készlet
                                    @elseif($location = $sourceLocations->get($source->warehouse_location_id))
                                        <a href="{{ route('warehouses.locations.show', ['warehouse' => $movement->warehouse_id, 'warehouseLocation' => $location]) }}" class="font-medium text-indigo-700 hover:underline">{{ $source->location_code }}</a>
                                    @else {{ $source->location_code }} @endif
                                    @if($source->location_name)<span class="block text-xs text-gray-500">{{ $source->location_name }}</span>@endif
                                </dt>
                                <dd class="shrink-0 font-medium tabular-nums">{{ number_format($source->quantity, 0, ',', ' ') }} db</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>
        @endif
        @if($movement->isReturnable()) @include('movements.return-history') @endif
        @if($movement->isReturn() && $movement->type === 'in') @include('movements.quality') @endif
    </div>
</x-app-layout>
