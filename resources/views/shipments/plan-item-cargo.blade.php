<section class="{{ $panel }}" data-plan-item-cargo aria-labelledby="plan-item-cargo-title">
    <h2 id="plan-item-cargo-title" x-ref="itemCargoHeading" tabindex="-1" class="text-base font-semibold text-gray-900">Tételenkénti rakomány</h2>
    <p x-show="cargo && !cargo.items.length" class="mt-2 text-sm text-gray-600">Ehhez a fuvarhoz még nincs rakománytétel.</p>
    <ul x-show="cargo?.items.length" class="mt-2 divide-y divide-gray-200">
        <template x-for="item in (cargo?.items ?? [])" :key="item.assignment_id">
            <li data-plan-cargo-item :id="`plan-item-cargo-${item.assignment_id}`" class="min-w-0 py-3 text-sm [overflow-wrap:anywhere]">
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1.5">
                    <div class="min-w-0 flex-1 basis-44">
                        <a :href="intentUrl(item.transfer_intent_id)" x-text="item.reference" class="rounded font-mono text-xs font-medium text-indigo-800 underline underline-offset-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"></a>
                        <p class="mt-0.5 font-semibold text-gray-900" x-text="cargoIntent(item)?.product_name"></p>
                        <p class="mt-0.5 text-xs text-gray-600"><span x-text="cargoIntent(item)?.sku"></span><span aria-hidden="true"> · </span><span x-text="formatQuantity(item.quantity)"></span></p>
                    </div>
                    <p class="rounded-md px-2 py-1 text-xs font-medium"
                       :class="item.cargo?.status === 'confirmed' ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-950'"
                       x-text="cargoItemStatus(item)"></p>
                </div>
                <p x-show="item.suggestion" x-text="cargoSuggestion(item)" class="mt-2 text-xs text-gray-700"></p>
                <p x-show="item.cargo?.gross_weight_grams != null" class="mt-1 text-xs text-gray-700">
                    Mentett bruttó tömeg <span class="font-semibold text-gray-900" x-text="formatMass(item.cargo?.gross_weight_grams)"></span>
                    <span aria-hidden="true"> · </span><span x-text="cargoSource(item.cargo?.source)"></span>
                </p>
                <p x-show="item.cargo?.dimensions_cm" class="mt-1 text-xs text-gray-700">
                    Külső méretek <span aria-hidden="true">·</span> <span x-text="cargoDimensions(item.cargo?.dimensions_cm)"></span>
                </p>
                <p x-show="item.cargo?.packaging_note" class="mt-1 whitespace-pre-wrap text-xs text-gray-700" x-text="item.cargo?.packaging_note"></p>
                @if ($canManage)
                    <button type="button" x-show="canEdit && cargo?.can_manage" :disabled="!mayChange" @click="openItemCargo(item.assignment_id)"
                            :aria-label="`Tétel szerkesztése · ${item.reference}`" class="{{ $link }} disabled:opacity-50">Tétel szerkesztése</button>
                @endif
            </li>
        </template>
    </ul>
</section>

@if ($canManage)
    <dialog x-ref="itemCargoDialog" aria-labelledby="plan-item-cargo-dialog-title" @cancel.prevent="closeDialog($refs.itemCargoDialog)"
            class="m-auto max-h-[calc(100dvh-1.5rem)] w-[calc(100%-1.5rem)] max-w-lg overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 text-gray-900 shadow-xl backdrop:bg-gray-900/55 sm:p-5 [overflow-wrap:anywhere]">
        <h2 id="plan-item-cargo-dialog-title" x-ref="itemCargoDialogHeading" tabindex="-1" class="text-lg font-semibold text-gray-900">Tétel rakományadatai</h2>
        <p class="mt-1 text-sm text-gray-700">
            <span x-text="itemCargoTarget?.reference"></span> · <span x-text="cargoIntent(itemCargoTarget)?.product_name"></span> · <span x-text="formatQuantity(itemCargoTarget?.quantity)"></span>
        </p>
        <form class="mt-4 space-y-4" @submit.prevent="saveItemCargo(false)" novalidate>
            <fieldset :disabled="busy || needsRefresh" class="min-w-0 space-y-3">
                <fieldset class="min-w-0 rounded-md border border-gray-200 px-3 pb-2">
                    <legend class="px-1 text-sm font-medium text-gray-900">Tömeg forrása</legend>
                    <label x-show="itemCargoTarget?.suggestion" class="flex min-h-10 items-center gap-2 text-sm">
                        <input type="radio" name="plan-item-cargo-source" value="calculated" x-model="itemCargoForm.source" class="border-gray-400 text-indigo-700 focus:ring-indigo-600">
                        <span>Csomagolásból számított</span>
                    </label>
                    <label class="flex min-h-10 items-center gap-2 text-sm">
                        <input type="radio" name="plan-item-cargo-source" value="manual" x-model="itemCargoForm.source" class="border-gray-400 text-indigo-700 focus:ring-indigo-600">
                        <span>Kézzel mért</span>
                    </label>
                </fieldset>
                <p x-show="itemCargoTarget?.suggestion" x-text="cargoSuggestion(itemCargoTarget)" class="rounded-md bg-indigo-50 p-2.5 text-sm text-indigo-900"></p>
                <p x-show="itemCargoErrors.source" x-text="itemCargoErrors.source" role="alert" class="text-sm text-red-700"></p>
                <div x-show="itemCargoForm.source === 'manual'">
                    <label for="plan-item-cargo-weight" class="block text-sm font-medium">Mért bruttó tömeg (kg)</label>
                    <input id="plan-item-cargo-weight" type="text" inputmode="decimal" x-model="itemCargoForm.weight" class="{{ $field }} mt-1"
                           :aria-invalid="Boolean(itemCargoErrors.gross_weight_grams || fieldErrors.gross_weight_grams)" aria-describedby="plan-item-cargo-weight-error">
                    <p id="plan-item-cargo-weight-error" x-show="itemCargoErrors.gross_weight_grams" x-text="itemCargoErrors.gross_weight_grams" role="alert" class="mt-1 text-xs text-red-700"></p>
                </div>
                <div x-show="itemCargoReasonRequired()">
                    <label for="plan-item-cargo-reason" class="block text-sm font-medium">Az eltérés indoka</label>
                    <textarea id="plan-item-cargo-reason" rows="2" maxlength="500" x-model="itemCargoForm.reason" class="{{ $field }} mt-1"
                              :aria-required="itemCargoReasonRequired()" :aria-invalid="Boolean(itemCargoErrors.reason || fieldErrors.reason)" aria-describedby="plan-item-cargo-reason-error"></textarea>
                    <p id="plan-item-cargo-reason-error" x-show="itemCargoErrors.reason" x-text="itemCargoErrors.reason" role="alert" class="mt-1 text-xs text-red-700"></p>
                </div>
                <details x-ref="itemCargoDimensions" class="rounded-md border border-gray-200 px-3 py-1">
                    <summary class="cursor-pointer rounded py-2 text-sm font-medium text-indigo-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Külső méretek és csomagolás</summary>
                    <div class="grid min-w-0 gap-2 py-2 sm:grid-cols-3" aria-describedby="plan-item-cargo-dimensions-error">
                        @foreach (['width' => 'Szélesség', 'depth' => 'Mélység', 'height' => 'Magasság'] as $key => $label)
                            <div class="min-w-0">
                                <label for="plan-item-cargo-{{ $key }}" class="block text-xs font-medium">{{ $label }} (cm)</label>
                                <input id="plan-item-cargo-{{ $key }}" type="text" inputmode="decimal" x-model="itemCargoForm.dimensions.{{ $key }}" class="{{ $field }} mt-1"
                                       :aria-invalid="Boolean(itemCargoErrors.dimensions_cm || fieldErrors.dimensions_cm || fieldErrors['dimensions_cm.{{ $key }}'])">
                            </div>
                        @endforeach
                    </div>
                    <p id="plan-item-cargo-dimensions-error" x-show="itemCargoErrors.dimensions_cm" x-text="itemCargoErrors.dimensions_cm" role="alert" class="text-xs text-red-700"></p>
                    <label for="plan-item-cargo-package-note" class="mt-1 block text-sm font-medium">Csomagolási megjegyzés</label>
                    <textarea id="plan-item-cargo-package-note" rows="2" maxlength="500" x-model="itemCargoForm.packagingNote" class="{{ $field }} mb-2 mt-1"
                              :aria-invalid="Boolean(fieldErrors.packaging_note)"></textarea>
                </details>
            </fieldset>
            <div x-show="submissionError || Object.keys(fieldErrors).length" class="rounded-md bg-amber-50 p-2.5">
                @include('shipments.plan-errors')
            </div>
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" @click="closeDialog($refs.itemCargoDialog)" :disabled="saving || unconfirmed" class="{{ $secondary }}">Mégse</button>
                <button type="button" x-show="needsRefresh && !unconfirmed" @click="refreshPlan()" :disabled="loading || saving" class="{{ $secondary }}">Fuvarterv frissítése</button>
                <button type="button" x-show="pendingCommand?.action === 'saveItem'" @click="retryCommand()" :disabled="saving" class="{{ $primary }}">Mentés újrapróbálása</button>
                <button type="submit" x-show="!unconfirmed && !needsRefresh" :disabled="!mayChange" class="{{ $secondary }}">Mentés tervezetként</button>
                <button type="button" x-show="!unconfirmed && !needsRefresh" @click="saveItemCargo(true)" :disabled="!mayChange" class="{{ $primary }}">Tételtömeg megerősítése</button>
            </div>
        </form>
    </dialog>
@endif
