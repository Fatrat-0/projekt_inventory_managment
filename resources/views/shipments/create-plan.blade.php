<x-transfer-layout>
    @php
        $config = ['endpoint' => route('shipment-plans.store'), 'detailUrl' => route('shipments.show', ['shipment' => '__shipment__']),
            'vehiclesUrl' => route('shipment-plans.vehicles.index', ['active_only' => 1]), 'vehicles' => $vehicles];
        $warehouseItems = $warehouses->map(fn ($warehouse) => ['value' => (string) $warehouse->warehouse_id, 'label' => $warehouse->warehouse_name])->values();
        $field = 'mt-1 min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500';
        $secondary = 'inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600';
    @endphp
    <div class="app-page space-y-4 py-4" data-shipment-plan-create>
        <x-page-toolbar title="Új fuvar tervezése">
            <a href="{{ route('shipments.index') }}" class="{{ $secondary }}">Vissza a fuvarokhoz</a>
        </x-page-toolbar>

        @if ($warehouses->isEmpty())
            <p role="status" class="rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700">Új fuvarhoz előbb hozz létre induló raktárat.</p>
        @else
            <form x-data="shipmentPlanCreate(@js($config))" action="{{ route('shipment-plans.store') }}" method="post"
                  @submit.prevent="save()" novalidate class="max-w-4xl space-y-4 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                @csrf
                <div hidden :hidden="!submissionError" x-ref="feedback" tabindex="-1" role="alert" class="rounded-md bg-red-50 p-3 text-sm text-red-900">
                    <p x-text="submissionError"></p>
                    <p x-show="fieldErrors.operation_key" x-text="fieldErrors.operation_key?.[0]"></p>
                </div>
                <fieldset :disabled="submitting || submitted || !!pending || vehicleLoading" class="grid min-w-0 gap-4 sm:grid-cols-2 [&_[role=combobox]]:min-h-11">
                    <div class="min-w-0">
                        <label for="plan-reference" class="text-sm font-medium text-gray-900">Szállítmányhivatkozás</label>
                        <input id="plan-reference" name="reference" x-model.fill="reference" maxlength="100" required class="{{ $field }}"
                               aria-describedby="plan-reference-error" :aria-invalid="!!fieldErrors.reference">
                        <p id="plan-reference-error" x-text="fieldErrors.reference?.[0]" class="empty:hidden mt-1 text-sm text-red-800"></p>
                    </div>
                    <div class="min-w-0" @plan-origin-selected="origin = $event.detail.value">
                        <label for="plan-origin" class="mb-1 block text-sm font-medium text-gray-900">Induló raktár</label>
                        <x-autocomplete-picker :items="$warehouseItems" id="plan-origin" name="origin_warehouse_id"
                            :required="true" event-name="plan-origin-selected" placeholder="Raktár keresése…" described-by="plan-origin-error" />
                        <p id="plan-origin-error" x-text="fieldErrors.origin_warehouse_id?.[0]" class="empty:hidden mt-1 text-sm text-red-800"></p>
                    </div>
                    <div class="min-w-0">
                        <label for="plan-departure" class="text-sm font-medium text-gray-900">Tervezett indulás</label>
                        <input id="plan-departure" type="datetime-local" name="planned_departure_at" x-ref="departureInput" x-model.fill="departure"
                               @input="delete fieldErrors.planned_departure_at"
                               min="{{ \App\Support\PlanningTime::localMinimum() }}" max="9999-12-31T23:59"
                               data-past-message="{{ \App\Support\PlanningTime::PAST_DEPARTURE }}" class="{{ $field }}"
                               aria-describedby="plan-departure-help plan-departure-error" :aria-invalid="!!fieldErrors.planned_departure_at">
                        <p id="plan-departure-help" class="mt-1 text-xs text-gray-600">Opcionális, magyarországi idő szerint. Az aktuális perc vagy későbbi időpont választható.</p>
                        <p id="plan-departure-error" x-text="fieldErrors.planned_departure_at?.[0]" class="empty:hidden mt-1 text-sm text-red-800"></p>
                    </div>
                    <div class="min-w-0 [overflow-wrap:anywhere]" x-ref="vehiclePicker" @plan-vehicle-selected="vehicleSelected($event.detail.value)">
                        <label for="plan-vehicle" class="mb-1 block text-sm font-medium text-gray-900">Nyilvántartott jármű</label>
                        <x-autocomplete-picker :items="[]" id="plan-vehicle" name="vehicle_id"
                            items-source="vehicleItems" model-value="vehicleId" details-source="vehicleDetails"
                            event-name="plan-vehicle-selected" placeholder="Keresés név vagy rendszám alapján…"
                            clear-label="Járműválasztás törlése" results-label="Aktív járművek" :max-results="12"
                            described-by="plan-vehicle-help plan-vehicle-error" client-error="!!fieldErrors.vehicle_id" />
                        <p id="plan-vehicle-help" class="mt-1 text-xs text-gray-600" x-show="!selectedVehicle">
                            A tervezet jármű nélkül is menthető. Csak aktív jármű választható.
                        </p>
                        <p x-show="selectedVehicle" x-cloak class="mt-1 text-xs font-medium text-gray-700" x-text="vehicleDetails[vehicleId]"></p>
                        <p id="plan-vehicle-error" role="status" x-text="fieldErrors.vehicle_id?.[0]" class="empty:hidden mt-1 text-sm text-red-800"></p>
                        <p x-show="vehicleLoading" x-cloak role="status" class="mt-1 text-xs text-gray-600">Aktív járművek frissítése…</p>
                        <button type="button" x-cloak x-show="vehicleRefreshFailed" @click="refreshVehicles()" class="mt-1 text-sm font-medium text-indigo-700 underline">Járműlista újratöltése</button>
                        <button type="button" x-cloak x-show="vehicleNeedsSelection && !vehicleLoading" @click="skipVehicle()" class="mt-1 block text-sm font-medium text-indigo-700 underline">Folytatás jármű nélkül</button>
                    </div>
                    <div class="min-w-0 sm:col-span-2">
                        <label for="plan-note" class="text-sm font-medium text-gray-900">Megjegyzés</label>
                        <textarea id="plan-note" name="note" rows="3" maxlength="1000" x-model.fill="note" class="{{ $field }}"
                                  aria-describedby="plan-note-error" :aria-invalid="!!fieldErrors.note"></textarea>
                        <p id="plan-note-error" x-text="fieldErrors.note?.[0]" class="empty:hidden mt-1 text-sm text-red-800"></p>
                    </div>
                </fieldset>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4">
                    <p class="text-sm text-gray-600">A jóváhagyott igényeket a fuvar adatlapján választhatod ki.</p>
                    <button type="submit" disabled :disabled="submitting || submitted || vehicleLoading" class="min-h-11 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50"
                            x-text="submitting || submitted ? 'Mentés…' : pending ? 'Mentés újrapróbálása' : 'Fuvar létrehozása'">Fuvar létrehozása</button>
                </div>
            </form>
        @endif
    </div>
</x-transfer-layout>
