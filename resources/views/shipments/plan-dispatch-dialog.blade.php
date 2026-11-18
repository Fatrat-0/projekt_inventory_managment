<dialog x-ref="dispatchDialog" aria-labelledby="plan-dispatch-dialog-title" @cancel.prevent="closeDialog($refs.dispatchDialog)"
        class="m-auto max-h-[calc(100dvh-1.5rem)] w-[calc(100%-1.5rem)] max-w-xl overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 text-gray-900 shadow-xl backdrop:bg-gray-900/55 sm:p-5 [overflow-wrap:anywhere]">
    <form @submit.prevent="confirmDispatch()" class="space-y-4">
        <h2 id="plan-dispatch-dialog-title" class="text-lg font-semibold">Fuvar feladásának megerősítése</h2>
        <dl class="grid gap-x-4 gap-y-2 rounded-md bg-gray-50 p-3 text-sm sm:grid-cols-2">
            <div><dt class="text-xs text-gray-600">Fuvarazonosító</dt><dd class="font-medium" x-text="detail?.shipment.shipment_number"></dd></div>
            <div><dt class="text-xs text-gray-600">Induló raktár</dt><dd class="font-medium" x-text="detail?.shipment.origin_warehouse_name"></dd></div>
            <div><dt class="text-xs text-gray-600">Jármű</dt><dd class="font-medium" x-text="vehicleName(cargo?.vehicle)"></dd></div>
            <div><dt class="text-xs text-gray-600">Igények és megállók</dt><dd class="font-medium" x-text="`${assignments.length} igény · ${stops.length} megálló`"></dd></div>
            <div><dt class="text-xs text-gray-600">Bruttó rakománytömeg</dt><dd class="font-medium" x-text="formatMass(cargo?.confirmation?.gross_weight_grams)"></dd></div>
            <div><dt class="text-xs text-gray-600">Raklaphelyigény</dt><dd class="font-medium" x-text="formatSlots(cargo?.confirmation?.pallet_slots)"></dd></div>
        </dl>
        <ul class="max-h-44 divide-y divide-gray-100 overflow-y-auto rounded-md border border-gray-200 px-3 text-sm" aria-label="Feladandó tételek célmegállónként">
            <template x-for="row in assignments" :key="row.id">
                <li class="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-0.5 py-2">
                    <span class="min-w-0"><strong x-text="row.intent.reference"></strong><span class="block text-xs text-gray-600" x-text="`${row.intent.product_name} · ${row.intent.to_warehouse_name}`"></span></span>
                    <span class="shrink-0 font-semibold" x-text="formatQuantity(row.intent.quantity)"></span>
                </li>
            </template>
        </ul>
        <p class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">A feladás rögzítésekor a rakomány kikerül a forrásraktár készletéből, és szállítás alatti készletként szerepel tovább. Ez nem egyszerű tervezési művelet.</p>
        <div>
            <label for="plan-dispatch-note" class="block text-sm font-medium">Feladási megjegyzés <span class="font-normal text-gray-600">(opcionális)</span></label>
            <textarea id="plan-dispatch-note" x-model="dispatchNote" maxlength="500" rows="2" class="{{ $field }} mt-1" :disabled="saving || unconfirmed || needsRefresh"></textarea>
        </div>
        <label class="flex items-start gap-2 rounded-md border border-gray-200 p-3 text-sm font-medium">
            <input type="checkbox" x-model="dispatchConfirmed" class="mt-0.5 rounded border-gray-400 text-indigo-700 focus:ring-indigo-600" :disabled="saving || unconfirmed || needsRefresh">
            <span>Ellenőriztem, hogy a teljes rakományt ténylegesen átadták szállításra.</span>
        </label>
        <div x-show="submissionError" class="space-y-2 rounded-md bg-amber-50 p-3">
            @include('shipments.plan-errors')
            <ul x-show="conflictBlockers.length > 1" class="list-disc space-y-1 ps-4 text-xs text-amber-950">
                <template x-for="(blocker, index) in conflictBlockers.slice(1)" :key="index"><li x-text="blocker.message"></li></template>
            </ul>
        </div>
        <div class="flex flex-wrap justify-end gap-2">
            <button type="button" @click="closeDialog($refs.dispatchDialog)" :disabled="saving" class="{{ $secondary }}" autofocus x-text="unconfirmed ? 'Bezárás' : 'Mégse'"></button>
            <button type="button" x-show="needsRefresh && !unconfirmed" @click="refreshPlan()" :disabled="loading || saving" class="{{ $secondary }}">Fuvarterv frissítése</button>
            <button type="button" x-show="pendingCommand?.action === 'dispatch'" @click="retryCommand()" :disabled="saving" class="{{ $primary }}">Feladás újrapróbálása</button>
            <button type="submit" x-show="!unconfirmed" :disabled="!canDispatch || !dispatchConfirmed" class="{{ $primary }}" x-text="saving ? 'Feladás rögzítése…' : 'Feladás véglegesítése'"></button>
        </div>
    </form>
</dialog>
