@php($vehicleDialogStyle = 'm-auto max-h-[calc(100dvh-1.5rem)] w-[calc(100%-1.5rem)] max-w-lg overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 text-gray-900 shadow-xl backdrop:bg-gray-900/55 sm:p-5 [overflow-wrap:anywhere]')

@if ($canManage)
    <dialog x-ref="vehiclePicker" aria-labelledby="plan-vehicle-picker-title" class="{{ $vehicleDialogStyle }}" @cancel.prevent="closeVehiclePicker()">
        <h2 id="plan-vehicle-picker-title" class="text-lg font-semibold text-gray-900">Jármű kiválasztása</h2>
        <div class="mt-3">
            <label for="plan-vehicle-picker-search" class="block text-sm font-medium text-gray-900">Jármű keresése</label>
            <input id="plan-vehicle-picker-search" type="search" x-ref="vehiclePickerSearch" x-model="vehicleSearch" :disabled="saving || unconfirmed" class="{{ $field }} mt-1" autofocus>
        </div>
        <p x-show="vehicleLoading" role="status" class="mt-3 text-sm text-gray-600">Járművek betöltése…</p>
        <div x-show="vehicleLoadError" class="mt-3 space-y-2 rounded-md bg-amber-50 p-2.5 text-sm text-amber-950">
            <p x-text="vehicleLoadError" role="alert"></p>
            <button type="button" @click="loadVehicles()" :disabled="vehicleLoading" class="{{ $secondary }}">Betöltés újrapróbálása</button>
        </div>
        <p x-show="!vehicleLoading && !vehicleLoadError && !vehicleResults.length" role="status" class="mt-3 text-sm text-gray-600">Nincs a keresésnek megfelelő aktív jármű.</p>
        <ul x-show="!vehicleLoading && !vehicleLoadError && vehicleResults.length" class="mt-3 max-h-[min(35dvh,20rem)] space-y-2 overflow-y-auto p-0.5" aria-label="Járműtalálatok">
            <template x-for="vehicle in vehicleResults" :key="vehicle.id">
                <li>
                    <button type="button" @click="pickVehicle(vehicle)" :disabled="!mayChange"
                            :aria-label="vehicle.registration_number ? `${vehicle.name} · ${vehicle.registration_number}` : vehicle.name"
                            :aria-pressed="vehicleSelection === vehicle.id" :aria-describedby="`plan-vehicle-info-${vehicle.id}`"
                            class="block w-full min-w-0 rounded-md border px-3 py-2 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed"
                            :class="vehicleSelection === vehicle.id ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                        <span class="flex flex-wrap items-start justify-between gap-x-2 gap-y-1">
                            <span class="min-w-0 text-sm font-medium text-gray-900" x-text="vehicle.registration_number ? `${vehicle.name} · ${vehicle.registration_number}` : vehicle.name"></span>
                        </span>
                        <span :id="`plan-vehicle-info-${vehicle.id}`" class="mt-0.5 block text-xs text-gray-600"
                              x-text="`Teherbírás ${vehiclePayloadLabel(vehicle.payload_capacity_grams)} · Raklapkapacitás ${vehiclePalletLabel(vehicle.pallet_slots_capacity)}`"></span>
                    </button>
                </li>
            </template>
        </ul>
        <div x-show="submissionError || Object.keys(fieldErrors).length" class="mt-3 rounded-md bg-amber-50 p-2.5">
            @include('shipments.plan-errors')
        </div>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button type="button" x-show="currentVehicle && vehicleSelection !== null && !unconfirmed" @click="clearVehicleSelection()" :disabled="!mayChange || vehicleLoading"
                    class="{{ $link }} me-auto">Hozzárendelés törlése</button>
            <button type="button" @click="closeVehiclePicker()" :disabled="saving" class="{{ $secondary }}">Mégse</button>
            <button type="button" x-show="needsRefresh" @click="refreshPlan()" :disabled="loading || saving || unconfirmed" class="{{ $secondary }}">Fuvarterv frissítése</button>
            <button type="button" x-show="unconfirmed" @click="retryCommand()" :disabled="saving" class="{{ $secondary }}">Mentés újrapróbálása</button>
            <button type="button" x-show="!unconfirmed" @click="saveVehicleSelection()" :disabled="!canSaveVehicleSelection" class="{{ $primary }}"
                    x-text="saving ? 'Mentés…' : (vehicleSelection === null && currentVehicle ? 'Eltávolítás mentése' : 'Jármű hozzárendelése')">Jármű hozzárendelése</button>
        </div>
    </dialog>
@endif
