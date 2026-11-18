<x-app-layout>
    @php
        $statuses = ['pending' => 'Átvételre vár', 'partial' => 'Részben átvéve', 'received' => 'Teljesen átvéve'];
        $config = [
            'snapshot' => $snapshot, 'history' => $history->toArray(), 'statuses' => $statuses,
            'urls' => [
                'state' => route('shipment-plans.stops.receiving.show', [$shipment, $stop]),
                'store' => route('shipment-plans.stops.receipts.store', [$shipment, $stop]),
                'history' => route('shipment-plans.stops.receipts.index', [$shipment, $stop]),
                'putaway' => route('warehouse-putaway-pages.index'),
                'putawayItem' => route('warehouse-putaway-pages.show', ['item' => '__ITEM__']),
            ],
        ];
        $panel = 'min-w-0 rounded-lg border border-gray-200 bg-white p-3 sm:p-4';
        $field = 'mt-1 min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100';
        $button = 'inline-flex min-h-11 max-w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-center text-sm font-semibold text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $primary = 'inline-flex min-h-11 max-w-full items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-center text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $link = 'inline-flex min-h-11 items-center rounded text-sm font-medium text-indigo-700 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600';
    @endphp
    <div class="app-page min-w-0 space-y-4 py-4" data-testid="receiving-worksheet" x-data="warehouseReceiving(@js($config))"
         @pageshow.window="if ($event.persisted) refresh()">
        <x-page-toolbar title="Átvételi munkalap">
            <a href="{{ route('shipments.show', $shipment) }}" class="{{ $button }}">Fuvar megnyitása</a>
        </x-page-toolbar>
        @include('transfers.tabs')
        @include('transfers.task-tabs')

        <p x-show="accessError" x-cloak x-ref="accessNotice" tabindex="-1" role="alert" x-text="accessError"
           class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-900"></p>
        <div style="display: none" x-show="!accessError" class="space-y-4">
            <header class="{{ $panel }} grid gap-3 [overflow-wrap:anywhere] md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div class="min-w-0">
                    <p class="font-mono text-xs text-gray-600">{{ $snapshot['shipment']['shipment_number'] }}</p>
                    <h2 class="font-semibold text-gray-900">{{ $snapshot['shipment']['reference'] }}</h2>
                    <p class="mt-1 text-sm text-gray-700">{{ $snapshot['shipment']['origin_warehouse_name'] }} <span aria-hidden="true">→</span><span class="sr-only"> – </span> {{ $snapshot['stop']['warehouse_name'] }}</p>
                </div>
                <dl class="grid min-w-0 grid-cols-2 gap-y-2 text-sm md:self-center">
                    <div><dt class="text-xs text-gray-600">Célmegálló</dt><dd class="font-medium">{{ $snapshot['stop']['position'] }}. megálló</dd></div>
                    <div><dt class="text-xs text-gray-600">Tényleges feladás</dt><dd class="font-medium" x-text="formatDate(snapshot.dispatch.dispatched_at)"></dd></div>
                    <div class="col-span-2">
                        <dt class="sr-only">Átvételi állapot</dt>
                        <dd data-testid="receiving-status" class="inline-flex rounded-md px-2 py-1 text-xs font-semibold"
                            :class="{ 'bg-indigo-50 text-indigo-800': snapshot.stop.receiving_status === 'pending', 'bg-amber-50 text-amber-900': snapshot.stop.receiving_status === 'partial', 'bg-emerald-50 text-emerald-800': snapshot.stop.receiving_status === 'received' }"
                            x-text="statuses[snapshot.stop.receiving_status]">{{ $statuses[$snapshot['stop']['receiving_status']] }}</dd>
                    </div>
                </dl>
            </header>

            <section x-show="success" x-cloak x-ref="success" tabindex="-1" data-testid="receiving-success"
                     class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-950 sm:p-4">
                <h2 class="font-semibold" x-text="success ? quantity(success.quantity) + ' sikeresen átvéve. A készlet a célraktárban van, és betárolásra vár.' : ''"></h2>
                <p class="mt-1" x-show="success?.remaining > 0" x-text="success?.remaining > 0 ? quantity(success.remaining) + ' továbbra is úton van ehhez a megállóhoz.' : ''"></p>
                <div class="mt-2 flex flex-wrap gap-x-3">
                    <a :href="success?.next" class="{{ $primary }}">Betárolás megkezdése</a>
                    <a href="{{ route('warehouse-receiving-pages.index') }}" class="{{ $link }}">Vissza az átvételi feladatokhoz</a>
                </div>
            </section>

            <div x-show="needsRefresh" x-cloak role="status" class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                <p x-text="refreshError || (refreshRequest ? 'Átvételi adatok frissítése…' : 'A folytatás előtt frissítsd az átvételi adatokat. A beírt mennyiségek és megjegyzések megmaradnak.')"></p>
                <button x-show="!refreshRequest" type="button" @click="refresh()" class="{{ $button }} mt-2">Átvételi adatok frissítése</button>
            </div>

            <form @submit.prevent="openConfirmation()" class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(14rem,0.43fr)]">
                @include('warehouse-receiving.lines')
                <section data-testid="receiving-summary" class="{{ $panel }} lg:sticky lg:top-[calc(var(--app-topbar-height,4rem)_+_1rem)]" aria-labelledby="receiving-summary-heading">
                    <h2 id="receiving-summary-heading" class="font-semibold text-gray-900">Átvétel összegzése</h2>
                    <p class="mt-3 text-sm font-semibold text-gray-900" data-receiving-line-count="total" x-text="snapshot.items.length + ' érintett tétel'"></p>
                    <ul class="mt-1 space-y-1 text-sm text-gray-700">
                        <li data-receiving-line-count="received" x-show="lineCounts.received > 0" x-text="lineCounts.received + ' teljesen átvett tétel'"></li>
                        <li data-receiving-line-count="partial" x-show="lineCounts.partial > 0" x-text="lineCounts.partial + ' részben átvett tétel'"></li>
                        <li data-receiving-line-count="pending" x-show="lineCounts.pending > 0" x-text="lineCounts.pending + ' átvételre váró tétel'"></li>
                    </ul>
                    <dl class="mt-3 space-y-2 border-t border-gray-200 pt-2 text-xs tabular-nums text-gray-600">
                        <div class="flex flex-wrap justify-between gap-x-2"><dt>Összesen átvéve</dt><dd class="font-medium" x-text="quantity(snapshot.stop.received_quantity)"></dd></div>
                        <div class="flex flex-wrap justify-between gap-x-2"><dt>Összesen átvehető</dt><dd class="font-medium" x-text="quantity(snapshot.stop.remaining_quantity)"></dd></div>
                        <div x-show="snapshot.stop.can_receive" class="space-y-2 border-t border-gray-200 pt-2">
                            <div class="flex flex-wrap justify-between gap-x-2"><dt class="text-gray-700">Most átveendő</dt><dd class="font-semibold text-indigo-900" x-text="lines.length + ' tétel · ' + quantity(total)"></dd></div>
                            <div class="flex flex-wrap justify-between gap-x-2"><dt class="text-gray-700">Úton maradna</dt><dd class="font-medium" x-text="quantity(remainder)"></dd></div>
                        </div>
                    </dl>
                    <template x-if="snapshot.stop.can_receive">
                        <div class="mt-3">
                            <button type="submit" x-ref="start" :disabled="!canConfirm" class="{{ $primary }} w-full">Átvétel rögzítése</button>
                            <p x-show="lines.length > 1000" class="mt-2 text-xs text-red-700">Egy átvételhez legfeljebb 1000 tétel rögzíthető.</p>
                        </div>
                    </template>
                    <a href="{{ route('warehouse-receiving-pages.index') }}" class="{{ $link }} mt-1" x-show="!success">Vissza az átvételi feladatokhoz</a>
                </section>
            </form>

            @include('warehouse-receiving.history')
        </div>

        @include('warehouse-receiving.confirmation')
    </div>
</x-app-layout>

