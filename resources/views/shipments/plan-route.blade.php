<section class="{{ $panel }}" aria-labelledby="plan-order-title">
    <h2 id="plan-order-title" x-ref="orderHeading" tabindex="-1" class="scroll-mt-20 text-base font-semibold text-gray-900">Lerakodási sorrend</h2>
    <p x-show="!routeStops.length" class="mt-2 text-sm text-gray-600">Az aktív célmegállók az igények hozzárendelése után jelennek meg.</p>
    <ol x-show="routeStops.length" class="mt-2 divide-y divide-gray-200">
        <template x-for="(stop, index) in routeStops" :key="stop.id">
            <li data-plan-stop class="flex min-w-0 flex-wrap items-center justify-between gap-x-2 gap-y-1 py-2">
                <div class="flex min-w-0 flex-1 items-baseline gap-2 [overflow-wrap:anywhere]">
                    <span class="shrink-0 font-mono text-xs font-semibold text-indigo-800" x-text="`${index + 1}.`"></span>
                    <p class="min-w-0 font-medium text-gray-900" x-text="stop.warehouse_name"></p>
                </div>
                @if ($canManage)
                    <div class="flex shrink-0 gap-1" x-show="canEdit && routeStops.length > 1">
                        <button type="button" class="{{ $secondary }} min-w-11"
                                @click="moveStop(index, -1)" :aria-label="`Előrébb · ${stop.warehouse_name}`"
                                :disabled="index === 0 || busy || needsRefresh || unconfirmed"><span aria-hidden="true">↑</span></button>
                        <button type="button" class="{{ $secondary }} min-w-11"
                                @click="moveStop(index, 1)" :aria-label="`Hátrébb · ${stop.warehouse_name}`"
                                :disabled="index === routeStops.length - 1 || busy || needsRefresh || unconfirmed"><span aria-hidden="true">↓</span></button>
                    </div>
                @endif
            </li>
        </template>
    </ol>
    @if ($canManage)
        <div x-show="canEdit && orderChanged" class="mt-2 border-t border-gray-200 pt-3">
            <p class="mb-2 text-xs text-gray-600">A sorrend még nincs mentve.</p>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="saveOrder()" :disabled="busy || needsRefresh" class="{{ $primary }}">Sorrend mentése</button>
                <button type="button" @click="resetOrder()" :disabled="busy || needsRefresh || unconfirmed" class="{{ $secondary }}">Módosítás elvetése</button>
            </div>
        </div>
    @endif

    <div class="mt-3 border-t border-gray-200 pt-3" aria-labelledby="plan-loading-title">
        <h3 id="plan-loading-title" class="text-base font-semibold text-gray-900">Felrakodási sorrend</h3>
        <p x-show="!loadingOrder.length" class="mt-2 text-sm text-gray-600">A sorrend az első célmegálló felvétele után jelenik meg.</p>
        <ol x-show="loadingOrder.length" class="mt-2 space-y-1.5" aria-labelledby="plan-loading-title">
            <template x-for="stop in loadingOrder" :key="stop.id">
                <li data-plan-loading-stop class="flex min-w-0 items-baseline gap-2 rounded-md bg-indigo-50 px-2.5 py-1.5 text-sm text-indigo-950 [overflow-wrap:anywhere]">
                    <span class="shrink-0 font-mono text-xs font-semibold" x-text="`${stop.loading_position}.`"></span>
                    <p class="min-w-0"><strong x-text="stop.warehouse_name"></strong><span class="text-xs" x-text="` · ${stop.position}. lerakodási megálló`"></span></p>
                </li>
            </template>
        </ol>
        <p x-show="loadingOrder.length" class="mt-2 text-xs text-gray-600">A későbbi megállók áruja kerül fel először. A fizikai elhelyezhetőség külön ellenőrzést igényel.</p>
    </div>
</section>
