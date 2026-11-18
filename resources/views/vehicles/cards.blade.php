<div data-listing-cards>
    <template x-for="vehicle in results" :key="vehicle.id">
        <article :data-vehicle-id="vehicle.id" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white text-sm [overflow-wrap:anywhere]">
            <header class="flex items-start justify-between gap-2 border-b border-gray-200 bg-gray-50 p-3">
                <div class="min-w-0">
                    <h2 class="font-semibold text-gray-900" x-text="vehicle.name"></h2>
                    <p class="mt-0.5 font-mono text-xs text-gray-700" x-text="vehicle.registration_number || 'Rendszám nincs megadva'"></p>
                    <div class="mt-2">@include('vehicles.status')</div>
                </div>
                @include('vehicles.actions', ['variant' => 'card'])
            </header>
            <div class="space-y-2.5 p-3">
                <dl class="grid min-w-0 grid-cols-[repeat(auto-fit,minmax(min(100%,7rem),1fr))] gap-2">
                    <div><dt class="text-xs text-gray-600">Hasznos teherbírás</dt><dd class="font-medium text-gray-900" x-text="massLabel(vehicle.payload_capacity_grams)"></dd></div>
                    <div><dt class="text-xs text-gray-600">Raklaphelykapacitás</dt><dd class="font-medium text-gray-900" x-text="slotsLabel(vehicle.pallet_slots_capacity)"></dd></div>
                </dl>
                <dl x-show="vehicle.note">
                    <dt class="text-xs text-gray-600">Megjegyzés</dt>
                    <dd class="whitespace-pre-wrap text-gray-800" x-text="vehicle.note"></dd>
                </dl>
            </div>
        </article>
    </template>
</div>
