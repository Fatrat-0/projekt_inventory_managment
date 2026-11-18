<dialog x-ref="editorDialog" aria-labelledby="vehicle-editor-title" class="{{ $dialog }} max-w-lg" @cancel.prevent="closeCommand($refs.editorDialog)">
    <template x-if="editor">
        <form @submit.prevent="saveEditor()" class="space-y-4">
            <h2 id="vehicle-editor-title" class="text-lg font-semibold" x-text="editor.id === null ? 'Új jármű' : 'Jármű szerkesztése'"></h2>
            <fieldset :disabled="commandLocked" class="min-w-0 space-y-3">
                <legend class="sr-only">Járműadatok</legend>
                <div>
                    <label for="vehicle-name" class="block text-sm font-medium text-gray-800">Megnevezés</label>
                    <input id="vehicle-name" x-ref="nameInput" x-model="editor.name" type="text" maxlength="100" required class="{{ $field }} mt-1"
                           :aria-invalid="!!fieldError('name')" aria-describedby="vehicle-name-error">
                    <p id="vehicle-name-error" class="mt-1 text-sm text-red-800" x-show="fieldError('name')" x-text="fieldError('name')"></p>
                </div>
                <div>
                    <label for="vehicle-registration" class="block text-sm font-medium text-gray-800">Rendszám</label>
                    <input id="vehicle-registration" x-model="editor.registration" type="text" maxlength="32" class="{{ $field }} mt-1"
                           :aria-invalid="!!fieldError('registration_number')" aria-describedby="vehicle-registration-error">
                    <p id="vehicle-registration-error" class="mt-1 text-sm text-red-800" x-show="fieldError('registration_number')" x-text="fieldError('registration_number')"></p>
                </div>
                <div class="grid min-w-0 gap-3 sm:grid-cols-2">
                    <div>
                        <label for="vehicle-payload" class="block text-sm font-medium text-gray-800">Hasznos teherbírás (kg)</label>
                        <input id="vehicle-payload" x-model="editor.payload" type="text" inputmode="decimal" class="{{ $field }} mt-1"
                               :aria-invalid="!!fieldError('payload_capacity_kg')" aria-describedby="vehicle-capacities-help vehicle-payload-error">
                        <p id="vehicle-payload-error" class="mt-1 text-sm text-red-800" x-show="fieldError('payload_capacity_kg')" x-text="fieldError('payload_capacity_kg')"></p>
                    </div>
                    <div>
                        <label for="vehicle-slots" class="block text-sm font-medium text-gray-800">Raklaphelykapacitás</label>
                        <input id="vehicle-slots" x-model="editor.slots" type="text" inputmode="numeric" class="{{ $field }} mt-1"
                               :aria-invalid="!!fieldError('pallet_slots_capacity')" aria-describedby="vehicle-capacities-help vehicle-slots-error">
                        <p id="vehicle-slots-error" class="mt-1 text-sm text-red-800" x-show="fieldError('pallet_slots_capacity')" x-text="fieldError('pallet_slots_capacity')"></p>
                    </div>
                </div>
                <p id="vehicle-capacities-help" class="text-xs text-gray-600">Az ismeretlen kapacitást hagyd üresen. Ha a kapacitás ténylegesen nulla, adj meg 0-t.</p>
                <div>
                    <label for="vehicle-note" class="block text-sm font-medium text-gray-800">Megjegyzés</label>
                    <textarea id="vehicle-note" x-model="editor.note" rows="2" maxlength="1000" class="{{ $field }} mt-1"
                              :aria-invalid="!!fieldError('note')" aria-describedby="vehicle-note-error"></textarea>
                    <p id="vehicle-note-error" class="mt-1 text-sm text-red-800" x-show="fieldError('note')" x-text="fieldError('note')"></p>
                </div>
                <label class="flex min-h-11 items-center gap-2 text-sm font-medium text-gray-800">
                    <input type="checkbox" x-model="editor.active" @change="editor.inactivationConfirmed = false"
                           class="rounded border-gray-300 text-indigo-700 focus:ring-indigo-600" aria-describedby="vehicle-active-error"
                           :aria-invalid="!!fieldError('active')">
                    Aktív jármű
                </label>
                <p id="vehicle-active-error" class="text-sm text-red-800" x-show="fieldError('active')" x-text="fieldError('active')"></p>
                <div x-show="editorDeactivates" class="space-y-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                    <p>@include('vehicles.inactivation-message')</p>
                    @include('vehicles.inactivation-reason', ['reasonId' => 'vehicle-edit-reason', 'reasonModel' => 'editor.reason'])
                    <label class="flex min-h-11 items-center gap-2 font-medium">
                        <input type="checkbox" x-model="editor.inactivationConfirmed" class="rounded border-gray-300 text-indigo-700 focus:ring-indigo-600">
                        Megerősítem az inaktiválást.
                    </label>
                </div>
            </fieldset>
            @include('vehicles.command-feedback', ['commandDialog' => 'editorDialog'])
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" @click="closeCommand($refs.editorDialog)" :disabled="submitting || !!pending" class="{{ $secondary }}">Mégse</button>
                <button type="submit" class="{{ $primary }}" x-show="!pending && !needsRefresh" :disabled="!canSaveEditor"
                        x-text="submitting ? 'Mentés…' : 'Jármű mentése'">Jármű mentése</button>
            </div>
        </form>
    </template>
</dialog>
