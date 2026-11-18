<section class="{{ $panel }}" aria-labelledby="plan-assignments-title">
    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h2 id="plan-assignments-title" class="text-base font-semibold text-gray-900">Hozzárendelt igények</h2>
        <p class="text-sm text-gray-600" x-text="`${assignments.length} igény · ${stops.length} célmegálló`"></p>
    </div>
    <p x-show="!assignments.length" role="status" class="mt-3 rounded-md bg-gray-50 p-3 text-sm text-gray-700">Ehhez a fuvarhoz még nincs igény rendelve.</p>
    <ol x-show="assignments.length" class="mt-3 space-y-3">
        <template x-for="stop in stops" :key="stop.id">
            <li class="min-w-0 rounded-md border border-gray-200">
                <h3 class="rounded-t-md bg-gray-50 px-3 py-2 font-semibold text-gray-900 [overflow-wrap:anywhere]" x-text="`${stop.position}. ${stop.warehouse_name}`"></h3>
                <ul class="divide-y divide-gray-200">
                    <template x-for="row in assignments.filter(assignment => assignment.shipment_stop_id === stop.id)" :key="row.id">
                        <li data-plan-assignment class="min-w-0 px-3 py-3 text-sm [overflow-wrap:anywhere]">
                            <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                                <div class="min-w-0 flex-1 basis-36">
                                    <a :href="intentUrl(row.transfer_intent_id)" :aria-label="`Igény megnyitása · ${row.intent.reference}`"
                                       class="rounded font-mono text-xs font-medium text-indigo-800 underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600" x-text="row.intent.reference"></a>
                                    <p class="mt-0.5 font-semibold text-gray-900" x-text="row.intent.product_name"></p>
                                    <p class="mt-0.5 text-xs text-gray-600" x-text="row.intent.sku"></p>
                                </div>
                                <span class="shrink-0 rounded-md bg-indigo-50 px-2 py-1 font-semibold text-indigo-950" x-text="formatQuantity(row.intent.quantity)"></span>
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                                <span class="rounded-md bg-gray-100 px-2 py-1 font-medium text-gray-800">Fuvarhoz rendelve</span>
                                <span x-show="prepFor(row.transfer_intent_id)?.reservation_status === 'active'" class="rounded-md bg-indigo-50 px-2 py-1 font-medium text-indigo-900">Készlet biztosítva</span>
                                <span x-show="['unreserved', 'released'].includes(prepFor(row.transfer_intent_id)?.reservation_status)"
                                      class="rounded-md bg-amber-50 px-2 py-1 font-medium text-amber-950">Készletbiztosításra vár</span>
                                <span x-show="prepFor(row.transfer_intent_id)?.picking_status === 'in_progress'" class="rounded-md bg-indigo-50 px-2 py-1 font-medium text-indigo-900">
                                    Kitárolás folyamatban · <span x-text="formatQuantity(prepFor(row.transfer_intent_id)?.staged_quantity)"></span>
                                </span>
                                <span x-show="prepFor(row.transfer_intent_id)?.stock_ready && !prepFor(row.transfer_intent_id)?.dispatched_quantity" class="rounded-md bg-emerald-50 px-2 py-1 font-medium text-emerald-900">Összekészítve</span>
                                <span x-show="prepFor(row.transfer_intent_id)?.dispatched_quantity > 0" class="rounded-md bg-indigo-50 px-2 py-1 font-medium text-indigo-900">Feladva</span>
                                <span x-show="row.intent.shipping_preference === 'dedicated_requested'" class="rounded-md bg-amber-50 px-2 py-1 font-medium text-amber-950">Külön fuvar kérése</span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-600">
                                <p><span x-text="@js($priorities)[row.intent.priority] || 'Normál'"></span> prioritás</p>
                                <p x-show="row.intent.requested_arrival_at">Kért érkezés <span class="font-medium text-gray-900" x-text="formatDate(row.intent.requested_arrival_at)"></span></p>
                            </div>
                            @if ($canManage)
                            <div class="mt-1 flex flex-wrap justify-between gap-x-2" x-show="canEdit || canOpenTask(prepFor(row.transfer_intent_id))">
                            @else
                            <div class="mt-1 flex flex-wrap justify-between gap-x-2" x-show="canOpenTask(prepFor(row.transfer_intent_id))">
                            @endif
                                <a x-show="canOpenTask(prepFor(row.transfer_intent_id))" :href="taskUrl(row.transfer_intent_id)"
                                   :aria-label="`Kitárolási feladat megnyitása · ${row.intent.reference}`" class="{{ $link }}">Kitárolási feladat</a>
                                @if ($canManage)
                                    <button type="button" x-show="canEdit" @click="openDetach(row)" :disabled="busy || needsRefresh || unconfirmed"
                                            :aria-label="`Leválasztás · ${row.intent.reference}`" class="ms-auto {{ $link }} !text-gray-700 disabled:opacity-50">Leválasztás</button>
                                @endif
                            </div>
                        </li>
                    </template>
                </ul>
            </li>
        </template>
    </ol>
</section>
