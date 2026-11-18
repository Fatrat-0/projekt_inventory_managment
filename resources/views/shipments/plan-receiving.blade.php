@if ($receivingStops !== [])
    @php
        $receivingLabels = ['pending' => 'Átvételre vár', 'partial' => 'Részben átvéve', 'received' => 'Teljesen átvéve'];
        $receivingTones = ['pending' => 'bg-slate-100 text-slate-800', 'partial' => 'bg-amber-50 text-amber-900', 'received' => 'bg-emerald-50 text-emerald-800'];
    @endphp
    <section class="min-w-0 rounded-lg border border-gray-200 bg-white p-4 [overflow-wrap:anywhere]" aria-labelledby="shipment-receiving-stops">
        <h2 id="shipment-receiving-stops" class="font-semibold text-gray-900">Célmegállók átvétele</h2>
        <ul class="mt-2 divide-y divide-gray-200">
            @foreach ($receivingStops as $row)
                @php($stopState = $row['stop'])
                <li data-receiving-stop-summary class="grid min-w-0 gap-x-4 gap-y-2 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold text-gray-900">{{ $stopState['position'] }}. {{ $stopState['warehouse_name'] }}</p>
                            <span class="rounded-md px-2 py-1 text-xs font-semibold {{ $receivingTones[$stopState['receiving_status']] }}">{{ $receivingLabels[$stopState['receiving_status']] }}</span>
                        </div>
                        <p class="mt-1 text-sm text-gray-600">{{ $stopState['item_count'] }} tétel · {{ number_format($stopState['received_quantity'], 0, ',', ' ') }} db átvéve · {{ number_format($stopState['remaining_quantity'], 0, ',', ' ') }} db úton</p>
                        @if ($stopState['pending_putaway_quantity'] > 0)
                            <p class="mt-1 text-sm text-gray-700">{{ number_format($stopState['pending_putaway_quantity'], 0, ',', ' ') }} db betárolásra vár.</p>
                        @endif
                    </div>
                    @if ($row['can_open_receiving'])
                        <div class="flex min-w-0 flex-wrap gap-x-3">
                            <a href="{{ route('warehouse-receiving-pages.show', [$row['shipment']['id'], $stopState['id']]) }}" class="inline-flex min-h-10 items-center rounded text-sm font-semibold text-indigo-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Átvétel megnyitása</a>
                            @if ($stopState['pending_putaway_quantity'] > 0)
                                <a href="{{ route('warehouse-putaway-pages.index', ['shipment_id' => $row['shipment']['id'], 'warehouse_id' => $stopState['warehouse_id']]) }}" class="inline-flex min-h-10 items-center rounded text-sm font-semibold text-indigo-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Betárolási feladatok</a>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif

