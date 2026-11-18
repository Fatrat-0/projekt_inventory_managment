<section class="{{ $panel }} [overflow-wrap:anywhere]" data-plan-preparation data-plan-checklist aria-labelledby="plan-preparation-title">
    <h2 id="plan-preparation-title" class="scroll-mt-20 text-base font-semibold text-gray-900">Indulási ellenőrzés</h2>
    <template x-if="readiness">
        <div class="mt-3 divide-y divide-gray-200 text-sm">
            <section class="pb-3" aria-label="Készlet és összekészítés">
                <div class="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                    <h3 class="font-semibold text-gray-900">Készlet és összekészítés</h3>
                    <p :class="readiness.stock_ready ? 'text-emerald-800' : 'text-amber-800'" class="text-xs font-semibold" x-text="`${preparedCount} / ${readiness.intents.length} összekészítve`"></p>
                </div>
                <template x-for="blocker in readiness.stock_blockers" :key="blocker.code"><p class="mt-2 text-gray-700" x-text="blocker.message"></p></template>
                <ul x-show="!readiness.stock_ready && readiness.intents.length" class="mt-2 divide-y divide-gray-100">
                    <template x-for="row in readiness.intents.filter(row => !row.stock_ready)" :key="row.transfer_intent_id">
                        <li class="py-2">
                            <p class="font-mono text-xs font-semibold text-gray-900" x-text="row.reference"></p>
                            <template x-for="blocker in row.blockers" :key="blocker.code"><p class="mt-0.5 text-xs text-gray-700" x-text="blocker.message"></p></template>
                            <a :href="canOpenTask(row) ? taskUrl(row.transfer_intent_id) : intentUrl(row.transfer_intent_id)"
                               class="{{ $link }} min-h-9 text-xs" :aria-label="`Előkészítés folytatása · ${row.reference}`"
                               x-text="canOpenTask(row) ? 'Kitárolási feladat megnyitása' : 'Átmozgatási igény megnyitása'"></a>
                        </li>
                    </template>
                </ul>
            </section>
            <div data-plan-transport>
                <section class="border-b border-gray-200 py-3" aria-label="Rakományadatok ellenőrzése">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                        <h3 class="font-semibold text-gray-900">Rakományadatok</h3>
                        <span class="text-xs font-semibold" :class="readiness.cargo_ready ? 'text-emerald-800' : 'text-amber-800'" x-text="readiness.cargo_ready ? 'Rendben' : 'Teendő'" ></span>
                    </div>
                    <ul x-show="readiness.cargo_blockers.length" class="mt-2 space-y-1">
                        <template x-for="(blocker, index) in readiness.cargo_blockers" :key="index">
                            <li class="text-xs text-gray-700"><span x-text="blocker.message"></span>
                                <a :href="blocker.assignment_id ? `#plan-item-cargo-${blocker.assignment_id}` : '#plan-total-cargo'"
                                   class="ms-1 font-medium text-indigo-700 underline underline-offset-2"
                                   x-text="blocker.assignment_id ? 'Tétel megnyitása' : 'Teljes rakomány megnyitása'"></a>
                            </li>
                        </template>
                    </ul>
                </section>
                <section class="py-3" aria-label="Jármű ellenőrzése">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1">
                        <h3 class="font-semibold text-gray-900">Jármű</h3>
                        <span class="text-xs font-semibold" :class="readiness.vehicle_ready ? 'text-emerald-800' : 'text-amber-800'" x-text="readiness.vehicle_ready ? 'Rendben' : 'Ellenőrzésre vár'"></span>
                    </div>
                    <ul x-show="readiness.vehicle_blockers.length" class="mt-2 list-disc space-y-1 ps-4 text-xs text-gray-700">
                        <template x-for="blocker in readiness.vehicle_blockers" :key="blocker.code"><li x-text="blocker.message"></li></template>
                    </ul>
                    <p x-show="!readiness.vehicle_ready && !readiness.vehicle_blockers.length" class="mt-2 text-xs text-gray-700">A járműkorlátok összevetéséhez a teljes rakomány adatait is meg kell erősíteni.</p>
                    <a x-show="!readiness.vehicle_ready" href="#shipment-plan-vehicle" class="{{ $link }} min-h-9 text-xs">Járműadatok megnyitása</a>
                </section>
            </div>
            <div class="space-y-2 pt-3">
                <p x-show="!readiness.has_dispatch_permission" class="text-xs text-gray-600">A fuvart a forrásraktár felügyelője vagy központi adminisztrátor adhatja fel.</p>
                <p x-show="readiness.has_dispatch_permission && !readiness.can_dispatch" class="text-xs text-gray-600">A feladáshoz rendezd a fenti teendőket.</p>
                <p x-show="readiness.can_dispatch && !orderChanged" class="text-xs font-medium text-emerald-800">A feladási előfeltételek teljesültek.</p>
                <p x-show="readiness.can_dispatch && orderChanged" class="text-xs text-amber-900">
                    A feladás előtt mentsd vagy vesd el a megállók módosított sorrendjét.
                    <a href="#plan-order-title" class="font-medium text-indigo-700 underline underline-offset-2">Lerakodási sorrend</a>
                </p>
                @if($canManage)
                    <button type="button" x-show="readiness.has_dispatch_permission" @click="openDispatch()" :disabled="!canDispatch" class="{{ $primary }} w-full">Fuvar feladása</button>
                @endif
            </div>
        </div>
    </template>
</section>
