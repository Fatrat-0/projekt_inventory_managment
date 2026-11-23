<x-transfer-layout>
    @php
        $config = [
            'shipmentId' => $shipment->id,
            'originId' => (int) $shipment->origin_warehouse_id,
            'originName' => $shipment->origin->warehouse_name,
            'canManage' => $canManage,
            'canOverrideDedicated' => $canOverrideDedicated,
            'urls' => [
                'detail' => route('shipment-plans.show', $shipment),
                'eligible' => route('shipment-plans.eligible'),
                'attach' => route('shipment-plans.intents.attach', $shipment),
                'detach' => route('shipment-plans.intents.detach', ['shipment' => $shipment, 'transferIntent' => '__intent__']),
                'stops' => route('shipment-plans.stops.update', $shipment),
                'loading' => route('shipment-plans.loading-order', $shipment),
                'readiness' => route('shipment-plans.readiness', $shipment),
                'departure' => route('shipment-plans.departure.update', $shipment),
                'cargo' => route('shipment-plans.cargo.show', $shipment),
                'cargoItem' => route('shipment-plans.cargo.items.update', ['shipment' => $shipment, 'assignment' => '__assignment__']),
                'dispatch' => route('shipment-plans.dispatch.show', $shipment),
                'vehicles' => route('shipment-plans.vehicles.index'),
                'vehicle' => route('shipment-plans.vehicle.update', $shipment),
                'intent' => route('transfer-intent-pages.show', ['transferIntent' => '__intent__']),
                'task' => route('warehouse-picking-pages.show', ['transferIntent' => '__intent__']),
            ],
        ];
        $panel = 'min-w-0 rounded-lg border border-gray-200 bg-white p-3 sm:p-4';
        $secondary = 'inline-flex min-h-11 max-w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-left text-sm font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $primary = 'inline-flex min-h-11 max-w-full items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-left text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $link = 'inline-flex min-h-11 max-w-full items-center rounded text-left text-sm font-medium text-indigo-700 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600';
        $field = 'w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100';
        $priorities = \App\Support\TransferIntentDisplay::PRIORITY;
    @endphp

    <div class="app-page space-y-4 py-4" data-shipment-plan x-data="shipmentPlanDetail(@js($config))"
         @pageshow.window="if ($event.persisted) refreshPlan()">
        <x-page-toolbar :title="$shipment->reference">
            <a href="{{ route('shipments.index') }}" class="{{ $secondary }}">Vissza a fuvarokhoz</a>
        </x-page-toolbar>

        <header x-show="!accessLost" class="{{ $panel }} [overflow-wrap:anywhere]" x-ref="planHeading" tabindex="-1" aria-label="A fuvar adatai">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <p class="font-mono text-sm font-medium text-indigo-800">{{ $shipment->shipment_number }}</p>
                <p class="rounded-md bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-900"
                   x-text="@js(\App\Models\Shipment::STATUSES)[detail?.shipment?.status] || @js(\App\Models\Shipment::STATUSES[$shipment->status] ?? 'Fuvarterv')">{{ \App\Models\Shipment::STATUSES[$shipment->status] ?? 'Fuvarterv' }}</p>
                <div class="flex flex-wrap items-center gap-2 sm:ms-auto">
                    <a x-cloak x-show="draft" href="#plan-preparation-title" class="{{ $link }}">Indulási ellenőrzés</a>
                    <div x-cloak x-show="stops.length && (draft || dispatchRecord)">@include('shipments.pdf-preview')</div>
                </div>
            </div>
            <dl class="mt-3 grid min-w-0 gap-3 text-sm sm:grid-cols-3">
                <div class="min-w-0"><dt class="text-xs text-gray-600">Induló raktár</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $shipment->origin->warehouse_name }}</dd></div>
                <div class="min-w-0">
                    <dt class="text-xs text-gray-600">Tervezett indulás</dt>
                    <dd class="mt-0.5 font-medium text-gray-900" x-text="detail ? formatPlannedDeparture(detail.shipment.planned_departure_at) : $el.textContent.trim()">{{ $shipment->planned_departure_at?->timezone(\App\Support\PlanningTime::ZONE)->format('Y. m. d. H:i') ?? 'Nincs megadva' }}</dd>
                    @if ($canManage)
                        <dd x-cloak x-show="canEdit && !departureEditing">
                            <button type="button" x-ref="departureEditButton" @click="openDepartureEditor()" :disabled="!mayChange"
                                    class="{{ $link }} text-sm disabled:opacity-50">Tervezett indulás módosítása</button>
                        </dd>
                    @endif
                </div>
                <div x-cloak x-show="dispatchRecord" class="min-w-0"><dt class="text-xs text-gray-600">Tényleges feladás</dt><dd class="mt-0.5 font-medium text-gray-900" x-text="formatDate(dispatchRecord?.dispatched_at)"></dd></div>
                @if ($shipment->vehicle_label)
                    <div class="min-w-0"><dt class="text-xs text-gray-600">Korábban megadott járműadat</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $shipment->vehicle_label }}</dd></div>
                @endif
                @if ($shipment->note)
                    <div class="min-w-0 sm:col-span-3"><dt class="text-xs text-gray-600">Megjegyzés</dt><dd class="mt-0.5 whitespace-pre-wrap text-gray-900">{{ $shipment->note }}</dd></div>
                @endif
            </dl>
            @if ($canManage)
                @include('shipments.plan-departure')
            @endif
        </header>

        <p x-cloak x-show="notice" x-text="notice" role="status"
           class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900"></p>
        <div x-cloak x-show="needsRefresh || dataError || submissionError" class="min-w-0 space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 [overflow-wrap:anywhere]">
            <p role="alert" x-text="dataError || submissionError || 'A fuvarterv megváltozott. Frissítsd a mentett adatokat a folytatáshoz.'"></p>
            @include('shipments.plan-errors', ['showSubmissionMessage' => false])
            <button type="button" x-ref="refreshButton" x-show="(needsRefresh || dataError) && !unconfirmed" @click="refreshPlan()"
                    :disabled="loading || saving" class="{{ $secondary }}">Fuvarterv frissítése</button>
            <button type="button" x-show="unconfirmed" @click="retryCommand()" :disabled="saving" class="{{ $secondary }}"
                    x-text="pendingCommand?.action === 'dispatch' ? 'Feladás újrapróbálása' : 'Mentés újrapróbálása'"></button>
        </div>
        <p x-cloak x-show="loading && !detail" role="status" class="{{ $panel }} text-sm text-gray-700">A fuvarterv betöltése…</p>

        <div x-cloak x-show="detail" class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] xl:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
            <div class="flex min-w-0 flex-col gap-4" :class="{ 'lg:col-span-2': !draft }">
                @include('shipments.plan-receiving')
                @include('shipments.plan-transit')
                @include('shipments.plan-assignments')
                <div x-show="draft">@include('shipments.plan-item-cargo')</div>
                @include('shipments.plan-route')
                @if ($canManage) @include('shipments.plan-eligible') @endif
            </div>
            <aside x-show="draft" aria-label="Fuvarozási adatok és előkészítés" class="flex min-w-0 flex-col gap-4">
                @include('shipments.plan-vehicle')
                @include('shipments.plan-cargo-total')
                @include('shipments.plan-capacity')
                @include('shipments.plan-readiness')
            </aside>
        </div>

        @if ($canManage) @include('shipments.plan-dialogs') @endif
        @include('shipments.plan-vehicle-dialogs')
        @if ($canManage) @include('shipments.plan-dispatch-dialog') @endif
    </div>
</x-transfer-layout>
