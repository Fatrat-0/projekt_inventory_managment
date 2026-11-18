@php($dialogStyle = 'm-auto max-h-[calc(100dvh-1.5rem)] w-[calc(100%-1.5rem)] max-w-lg overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 text-gray-900 shadow-xl backdrop:bg-gray-900/55 sm:p-5 [overflow-wrap:anywhere]')
@if ($canOverrideDedicated)
    <dialog x-ref="groupingDialog" aria-labelledby="plan-grouping-title" class="{{ $dialogStyle }}" @cancel.prevent="closeDialog($refs.groupingDialog)">
        <h2 id="plan-grouping-title" class="text-lg font-semibold text-gray-900">Külön fuvart kérő igény csoportosítása</h2>
        <p class="mt-2 rounded-md bg-amber-50 p-2.5 text-sm text-amber-950">Legalább egy igényhez külön fuvart kértek. A közös fuvarba sorolás indoka bekerül az eseménynaplóba.</p>
        <div class="mt-3 space-y-3">
            <div>
                <label for="plan-grouping-reason" class="block text-sm font-medium text-gray-900">A csoportosítás indoka</label>
                <textarea id="plan-grouping-reason" x-ref="groupingReasonInput" x-model="groupingReason" maxlength="500" rows="3" required
                          :disabled="saving || unconfirmed" :aria-invalid="!!fieldErrors.grouping_reason" class="{{ $field }} mt-1" autofocus></textarea>
            </div>
            <label class="flex items-start gap-2 text-sm font-medium text-gray-900">
                <input type="checkbox" x-model="groupingConfirmed" required :disabled="saving || unconfirmed" class="mt-0.5 rounded border-gray-400 text-indigo-700 focus:ring-indigo-600">
                <span>Megerősítem a közös fuvarba sorolást</span>
            </label>
        </div>
        <div x-show="submissionError || Object.keys(fieldErrors).length" class="mt-3 rounded-md bg-amber-50 p-2.5">
            @include('shipments.plan-errors')
        </div>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button type="button" @click="closeDialog($refs.groupingDialog)" :disabled="saving" class="{{ $secondary }}">Mégse</button>
            <button type="button" x-show="needsRefresh" @click="refreshPlan()" :disabled="loading || saving || unconfirmed" class="{{ $secondary }}">Fuvarterv frissítése</button>
            <button type="button" x-show="unconfirmed" @click="retryCommand()" :disabled="saving" class="{{ $secondary }}">Mentés újrapróbálása</button>
            <button type="button" x-show="!unconfirmed" @click="confirmAttach()" :disabled="busy || needsRefresh || !groupingConfirmed || !groupingReason.trim()" class="{{ $primary }}">Hozzárendelés megerősítése</button>
        </div>
    </dialog>
@endif

<dialog x-ref="detachDialog" aria-labelledby="plan-detach-title" class="{{ $dialogStyle }}" @cancel.prevent="closeDialog($refs.detachDialog)">
    <h2 id="plan-detach-title" class="text-lg font-semibold text-gray-900">Igény leválasztása</h2>
    <dl class="mt-2 space-y-1 text-sm">
        <div><dt class="inline text-gray-600">Igény</dt> <dd class="inline font-medium" x-text="detachTarget?.intent?.reference"></dd></div>
        <div><dt class="inline text-gray-600">Célmegálló</dt> <dd class="inline font-medium" x-text="detachTarget?.intent?.to_warehouse_name"></dd></div>
    </dl>
    <p class="mt-2 text-sm text-gray-700">A foglalás és a kitárolási előrehaladás megmarad. Az igény másik fuvarhoz rendelhető.</p>
    <label for="plan-detach-reason" class="mt-3 block text-sm font-medium text-gray-900">Leválasztás indoka <span class="font-normal text-gray-600">(opcionális)</span></label>
    <textarea id="plan-detach-reason" x-model="detachReason" maxlength="500" rows="2" :disabled="saving || unconfirmed"
              :aria-invalid="!!fieldErrors.reason" class="{{ $field }} mt-1"></textarea>
    <div x-show="submissionError || Object.keys(fieldErrors).length" class="mt-3 rounded-md bg-amber-50 p-2.5">
        @include('shipments.plan-errors')
    </div>
    <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button type="button" @click="closeDialog($refs.detachDialog)" :disabled="saving" class="{{ $secondary }}" autofocus>Mégse</button>
        <button type="button" x-show="needsRefresh" @click="refreshPlan()" :disabled="loading || saving || unconfirmed" class="{{ $secondary }}">Fuvarterv frissítése</button>
        <button type="button" x-show="unconfirmed" @click="retryCommand()" :disabled="saving" class="{{ $secondary }}">Mentés újrapróbálása</button>
        <button type="button" x-show="!unconfirmed" @click="confirmDetach()" :disabled="busy || needsRefresh || !detachTarget" class="{{ $primary }}">Leválasztás megerősítése</button>
    </div>
</dialog>
