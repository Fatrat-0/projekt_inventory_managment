<section id="plan-total-cargo" class="{{ $panel }}" data-plan-cargo-total aria-labelledby="plan-total-cargo-title">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="plan-total-cargo-title" x-ref="totalCargoHeading" tabindex="-1" class="text-base font-semibold text-gray-900">Teljes rakomány</h2>
        <span class="rounded-md px-2 py-1 text-xs font-medium"
              :class="cargo?.confirmation?.status === 'confirmed' ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-950'"
              x-text="cargo?.confirmation?.status === 'confirmed' ? 'Megerősítve' : cargo?.confirmation?.status === 'stale' ? 'Új megerősítés szükséges' : 'Megerősítésre vár'"></span>
    </div>
    <dl class="mt-3 grid min-w-0 grid-cols-2 gap-x-3 gap-y-2 text-sm [overflow-wrap:anywhere]">
        <div>
            <dt class="text-xs text-gray-600">Igazolt tételek össztömege</dt>
            <dd class="mt-0.5 font-medium tabular-nums text-gray-900" x-text="formatMass(cargo?.confirmed_item_weight_grams)"></dd>
        </div>
        <div x-show="cargo?.confirmation?.shared_materials_weight_grams != null">
            <dt class="text-xs text-gray-600">Közös segédanyagok</dt>
            <dd class="mt-0.5 font-medium tabular-nums text-gray-900" x-text="formatMass(cargo?.confirmation?.shared_materials_weight_grams)"></dd>
        </div>
        <div>
            <dt class="text-xs text-gray-600">Bruttó rakománytömeg</dt>
            <dd class="mt-0.5 font-semibold tabular-nums text-gray-900" x-text="formatMass(cargo?.confirmation?.gross_weight_grams)"></dd>
        </div>
        <div>
            <dt class="text-xs text-gray-600">Tényleges raklaphelyigény</dt>
            <dd class="mt-0.5 font-semibold tabular-nums text-gray-900" x-text="cargo?.confirmation ? `${cargo.confirmation.pallet_slots} hely` : '—'"></dd>
        </div>
        <div x-show="cargo?.confirmation" class="col-span-2">
            <dt class="text-xs text-gray-600" x-text="cargo?.confirmation?.status === 'stale' ? 'Korábban megerősítette' : 'Megerősítette'"></dt>
            <dd class="mt-0.5 text-gray-900">
                <span class="font-medium" x-text="cargoActor(cargo?.confirmation?.confirmed_by, cargo?.confirmation?.confirmed_by_name)"></span>
                <span aria-hidden="true"> · </span><span x-text="formatDate(cargo?.confirmation?.confirmed_at)"></span>
            </dd>
        </div>
    </dl>
    <p x-show="cargo?.confirmation?.status === 'stale'"
       x-text="cargo?.cargo_blockers.find(blocker => blocker.code === 'cargo_confirmation_stale')?.message || 'Ellenőrizd és erősítsd meg újra a korábban rögzített értékeket.'"
       class="mt-3 rounded-md bg-amber-50 px-2.5 py-2 text-sm text-amber-950"></p>
    <p x-show="cargo?.confirmation?.reason" class="mt-2 whitespace-pre-wrap text-xs text-gray-700 [overflow-wrap:anywhere]">
        <span class="font-medium">Eltérés indoklása</span> <span aria-hidden="true">·</span> <span x-text="cargo?.confirmation?.reason"></span>
    </p>
    @if ($canManage)
        <button type="button" x-show="canEdit && cargo?.can_manage && cargo.items.length" :disabled="!mayChange" @click="openTotalCargo()"
                class="mt-3 {{ $secondary }}" x-text="cargo?.confirmation?.status === 'stale' ? 'Új megerősítés' : cargo?.confirmation ? 'Teljes rakomány módosítása' : 'Teljes rakomány rögzítése'"></button>
    @endif
</section>

@if ($canManage)
    <dialog x-ref="totalCargoDialog" aria-labelledby="plan-total-cargo-dialog-title" @cancel.prevent="closeDialog($refs.totalCargoDialog)"
            class="m-auto max-h-[calc(100dvh-1.5rem)] w-[calc(100%-1.5rem)] max-w-lg overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 text-gray-900 shadow-xl backdrop:bg-gray-900/55 sm:p-5 [overflow-wrap:anywhere]">
        <h2 id="plan-total-cargo-dialog-title" x-ref="totalCargoDialogHeading" tabindex="-1" class="text-lg font-semibold text-gray-900">Teljes rakomány megerősítése</h2>
        <p id="plan-total-cargo-weight-help" class="mt-2 text-sm text-gray-700">A teljes rakománytömeg tartalmazza az árut, a csomagolást, a raklapokat és a rakományrögzítő anyagokat is.</p>
        <p class="mt-2 rounded-md bg-indigo-50 px-2.5 py-2 text-sm text-indigo-900">Igazolt tételek össztömege <strong x-text="formatMass(cargo?.confirmed_item_weight_grams)"></strong></p>
        <form class="mt-4 space-y-4" @submit.prevent="confirmTotalCargo()" novalidate>
            <fieldset :disabled="busy || needsRefresh" class="min-w-0 space-y-3">
                <div class="grid min-w-0 gap-3 sm:grid-cols-2">
                    <div class="min-w-0">
                        <label for="plan-total-cargo-weight" class="block text-sm font-medium">Teljes bruttó rakománytömeg (kg)</label>
                        <input id="plan-total-cargo-weight" type="text" inputmode="decimal" x-model="totalCargoForm.weight" required class="{{ $field }} mt-1"
                               :aria-invalid="Boolean(totalCargoErrors.gross_weight_grams || fieldErrors.gross_weight_grams)"
                               aria-describedby="plan-total-cargo-weight-help plan-total-cargo-weight-error">
                        <p id="plan-total-cargo-weight-error" x-show="totalCargoErrors.gross_weight_grams" x-text="totalCargoErrors.gross_weight_grams" role="alert" class="mt-1 text-xs text-red-700"></p>
                    </div>
                    <div class="min-w-0">
                        <label for="plan-total-cargo-slots" class="block text-sm font-medium">Tényleges raklaphelyigény</label>
                        <input id="plan-total-cargo-slots" type="text" inputmode="numeric" x-model="totalCargoForm.slots" required class="{{ $field }} mt-1"
                               :aria-invalid="Boolean(totalCargoErrors.pallet_slots || fieldErrors.pallet_slots)" aria-describedby="plan-total-cargo-slots-help plan-total-cargo-slots-error">
                        <p id="plan-total-cargo-slots-help" class="mt-1 text-xs text-gray-600">Ha a rakomány nem foglal raklaphelyet, írj 0-t.</p>
                        <p id="plan-total-cargo-slots-error" x-show="totalCargoErrors.pallet_slots" x-text="totalCargoErrors.pallet_slots" role="alert" class="mt-1 text-xs text-red-700"></p>
                    </div>
                </div>
                <div>
                    <label for="plan-total-cargo-materials" class="block text-sm font-medium">Közös rakodási segédanyagok (kg)</label>
                    <input id="plan-total-cargo-materials" type="text" inputmode="decimal" x-model="totalCargoForm.sharedMaterials" class="{{ $field }} mt-1"
                           :aria-invalid="Boolean(totalCargoErrors.shared_materials_weight_grams || fieldErrors.shared_materials_weight_grams)"
                           aria-describedby="plan-total-cargo-materials-help plan-total-cargo-materials-error">
                    <p id="plan-total-cargo-materials-help" class="mt-1 text-xs text-gray-600">Opcionális bontás. A megadott segédanyagtömeg a teljes bruttó érték része.</p>
                    <p id="plan-total-cargo-materials-error" x-show="totalCargoErrors.shared_materials_weight_grams" x-text="totalCargoErrors.shared_materials_weight_grams" role="alert" class="mt-1 text-xs text-red-700"></p>
                </div>
                <div x-show="totalCargoReasonRequired() || Boolean(totalCargoForm.reason) || Boolean(fieldErrors.reason)">
                    <label for="plan-total-cargo-reason" class="block text-sm font-medium">Az eltérés indoka</label>
                    <textarea id="plan-total-cargo-reason" rows="2" maxlength="500" x-model="totalCargoForm.reason" class="{{ $field }} mt-1"
                              :aria-required="totalCargoReasonRequired()" :aria-invalid="Boolean(totalCargoErrors.reason || fieldErrors.reason)" aria-describedby="plan-total-cargo-reason-error"></textarea>
                    <p id="plan-total-cargo-reason-error" x-show="totalCargoErrors.reason" x-text="totalCargoErrors.reason" role="alert" class="mt-1 text-xs text-red-700"></p>
                </div>
            </fieldset>
            <div x-show="submissionError || Object.keys(fieldErrors).length" class="rounded-md bg-amber-50 p-2.5">
                @include('shipments.plan-errors')
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" @click="closeDialog($refs.totalCargoDialog)" :disabled="saving || unconfirmed" class="{{ $secondary }}">Mégse</button>
                <button type="button" x-show="needsRefresh && !unconfirmed" @click="refreshPlan()" :disabled="loading || saving" class="{{ $secondary }}">Fuvarterv frissítése</button>
                <button type="button" x-show="pendingCommand?.action === 'confirmCargo'" @click="retryCommand()" :disabled="saving" class="{{ $primary }}">Mentés újrapróbálása</button>
                <button type="submit" x-show="!unconfirmed && !needsRefresh" :disabled="!mayChange" class="{{ $primary }}">Rakomány megerősítése</button>
            </div>
        </form>
    </dialog>
@endif
