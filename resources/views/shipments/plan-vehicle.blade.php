<section id="shipment-plan-vehicle" x-ref="vehicleSection" tabindex="-1" x-show="detail?.shipment?.status === 'draft'"
         class="{{ $panel }} [overflow-wrap:anywhere]" aria-labelledby="plan-vehicle-title" data-plan-vehicle>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="plan-vehicle-title" class="text-base font-semibold text-gray-900">Jármű</h2>
        @if (auth()->user()->role === 'admin')
            <a href="{{ route('vehicle-pages.index') }}" class="{{ $link }}">Járműtörzs kezelése</a>
        @endif
    </div>
    <p x-show="!currentVehicle" class="mt-2 text-sm text-gray-600">Még nincs jármű kiválasztva.</p>
    <template x-if="currentVehicle">
        <div class="mt-2 space-y-2.5">
            <div class="flex flex-wrap items-start justify-between gap-1.5">
                <p class="min-w-0 text-sm font-semibold text-gray-900" x-text="currentVehicle.registration_number ? `${currentVehicle.name} · ${currentVehicle.registration_number}` : currentVehicle.name"></p>
                <span class="rounded-md px-2 py-0.5 text-xs font-medium"
                      :class="currentVehicle.active ? 'bg-gray-100 text-gray-700' : 'bg-amber-50 text-amber-900'"
                      x-text="currentVehicle.active ? 'Aktív' : 'Inaktív'"></span>
            </div>
            <dl class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,8rem),1fr))] gap-2.5 text-sm">
                <div><dt class="text-xs text-gray-600">Hasznos teherbírás</dt><dd class="font-medium text-gray-900" x-text="vehiclePayloadLabel(currentVehicle.payload_capacity_grams)"></dd></div>
                <div><dt class="text-xs text-gray-600">Raklaphelykapacitás</dt><dd class="font-medium text-gray-900" x-text="vehiclePalletLabel(currentVehicle.pallet_slots_capacity)"></dd></div>
            </dl>
        </div>
    </template>
    <p x-show="currentVehicle?.active === false" class="mt-3 rounded-md bg-amber-50 p-2.5 text-sm text-amber-950">
        A hozzárendelt jármű inaktív. A feladáshoz aktív járműre kell cserélni vagy újra kell aktiválni.
    </p>
    <div class="mt-3 flex flex-wrap gap-2" x-show="canEdit || currentVehicle?.active === false">
        @if ($canManage)
            <button type="button" x-show="canEdit" @click="openVehiclePicker()" :disabled="!mayChange" class="{{ $secondary }}"
                    x-text="currentVehicle?.active === false ? 'Jármű cseréje' : 'Jármű kiválasztása'">Jármű kiválasztása</button>
        @endif
        <button type="button" x-show="currentVehicle?.active === false && !needsRefresh && !dataError" @click="refreshVehicleStatus()" :disabled="busy" class="{{ $secondary }} text-xs">
            Járműállapot frissítése
        </button>
    </div>
</section>
