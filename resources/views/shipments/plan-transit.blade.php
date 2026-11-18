<section x-show="dispatchRecord" data-plan-transit class="{{ $panel }} [overflow-wrap:anywhere]" aria-labelledby="plan-transit-heading">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="plan-transit-heading" x-ref="transitHeading" tabindex="-1" class="text-base font-semibold text-gray-900">Szállítás alatt álló tételek</h2>
        <span class="rounded-md bg-indigo-50 px-2.5 py-1 text-sm font-semibold text-indigo-950" x-text="formatQuantity(dispatchState?.transit_quantity)"></span>
    </div>
    <p class="mt-1 text-sm text-gray-600">A mennyiségek a fuvarhoz tartozó tranzitkészletet mutatják.</p>
    <dl class="mt-3 grid gap-3 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
        <div><dt class="text-xs text-gray-600">Feladási rekord</dt><dd class="font-medium" x-text="dispatchRecord ? `#${dispatchRecord.id}` : '—'"></dd></div>
        <div><dt class="text-xs text-gray-600">Feladta</dt><dd class="font-medium" x-text="dispatchRecord?.actor_name ?? (dispatchRecord ? `Felhasználó #${dispatchRecord.actor_id}` : '—')"></dd></div>
        <div><dt class="text-xs text-gray-600">Feladás időpontja</dt><dd class="font-medium" x-text="formatDate(dispatchRecord?.dispatched_at)"></dd></div>
        <div><dt class="text-xs text-gray-600">Feladáskori jármű</dt><dd class="font-medium" x-text="vehicleName(dispatchRecord?.snapshot.vehicle)"></dd>
            <dd class="text-xs text-gray-600" x-text="dispatchRecord ? `${formatMass(dispatchRecord.snapshot.vehicle?.payload_capacity_grams)} · ${formatSlots(dispatchRecord.snapshot.vehicle?.pallet_slots_capacity)}` : ''"></dd></div>
        <div><dt class="text-xs text-gray-600">Feladáskori rakománytömeg</dt><dd class="font-medium" x-text="formatMass(dispatchRecord?.snapshot.cargo.confirmation?.gross_weight_grams)"></dd></div>
        <div><dt class="text-xs text-gray-600">Feladáskori raklaphelyigény</dt><dd class="font-medium" x-text="formatSlots(dispatchRecord?.snapshot.cargo.confirmation?.pallet_slots)"></dd></div>
    </dl>
    <ul class="mt-3 divide-y divide-gray-200">
        <template x-for="row in transitItems" :key="row.id">
            <li data-plan-transit-item class="grid min-w-0 gap-x-4 gap-y-2 py-3 text-sm sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                <div class="min-w-0">
                    <a :href="intentUrl(row.transfer_intent_id)" class="font-mono text-xs font-medium text-indigo-800 underline underline-offset-2" x-text="transitIntent(row).reference"></a>
                    <p class="font-semibold text-gray-900" x-text="transitIntent(row).product_name"></p>
                    <p class="text-xs text-gray-600" x-text="transitIntent(row).sku"></p>
                </div>
                <dl class="grid min-w-0 grid-cols-2 gap-2">
                    <div class="col-span-2"><dt class="text-xs text-gray-600">Célmegálló</dt><dd class="font-medium" x-text="transitStop(row) ? `${transitStop(row).position}. ${transitStop(row).warehouse_name}` : '—'"></dd></div>
                    <div><dt class="text-xs text-gray-600">Feladva</dt><dd class="font-medium" x-text="formatQuantity(row.dispatched_quantity)"></dd></div>
                    <div><dt class="text-xs text-gray-600">Úton</dt><dd class="font-semibold text-indigo-900" x-text="formatQuantity(row.remaining_quantity)"></dd></div>
                </dl>
            </li>
        </template>
    </ul>
</section>
