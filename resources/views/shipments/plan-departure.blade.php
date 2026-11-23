<form x-cloak x-show="canEdit && departureEditing" @submit.prevent="saveDeparture()" novalidate
      class="mt-3 space-y-2 border-t border-gray-200 pt-3">
    <label for="plan-departure-edit" class="block text-sm font-medium">Új tervezett indulás</label>
    <p id="plan-departure-edit-help" class="text-xs text-gray-600">Magyarországi idő. Az aktuális perc vagy későbbi időpont választható.</p>
    <div class="flex flex-wrap items-end gap-2">
        <div class="min-w-0 w-full sm:max-w-64">
            <input id="plan-departure-edit" type="datetime-local" required x-ref="departureInput" x-model="departure"
                   :min="readiness?.minimum_planned_departure_at" max="9999-12-31T23:59"
                   data-past-message="{{ \App\Support\PlanningTime::PAST_DEPARTURE }}"
                   aria-describedby="plan-departure-edit-help plan-departure-edit-error"
                   :aria-invalid="!!fieldErrors.planned_departure_at"
                   :disabled="saving || unconfirmed || needsRefresh" class="{{ $field }}"
                   @input="departureBadInput = $event.target.validity.badInput; delete fieldErrors.planned_departure_at">
            <p id="plan-departure-edit-error" x-text="fieldErrors.planned_departure_at?.[0]" role="alert" class="empty:hidden mt-1 text-sm text-red-800"></p>
        </div>
        <button type="submit" :disabled="!mayChange" class="{{ $primary }}">Indulás mentése</button>
        <button type="button" :disabled="saving || unconfirmed" @click="closeDepartureEditor()" class="{{ $secondary }}">Mégse</button>
    </div>
</form>
