<dialog x-ref="transitionDialog" aria-labelledby="vehicle-transition-title" class="{{ $dialog }} max-w-lg" @cancel.prevent="closeCommand($refs.transitionDialog)">
    <template x-if="transition">
        <form @submit.prevent="saveTransition()" class="space-y-4">
            <h2 id="vehicle-transition-title" class="text-lg font-semibold" x-text="transition.vehicle.active ? 'Jármű inaktiválása' : 'Jármű újraaktiválása'"></h2>
            <p class="text-sm font-semibold text-gray-900">
                <span x-text="transition.vehicle.name"></span>
                <span class="ms-1 text-xs font-normal text-gray-600" x-text="transition.vehicle.registration_number"></span>
            </p>
            <div class="space-y-3" x-show="transition.vehicle.active">
                <p class="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">@include('vehicles.inactivation-message')</p>
                @include('vehicles.inactivation-reason', ['reasonId' => 'vehicle-inactivation-reason', 'reasonModel' => 'transition.reason'])
            </div>
            <p x-show="!transition.vehicle.active" class="text-sm text-gray-700">Az újraaktivált jármű ismét választható lesz a fuvarokhoz.</p>
            @include('vehicles.command-feedback', ['commandDialog' => 'transitionDialog'])
            <div class="flex flex-wrap justify-end gap-2">
                <button type="button" x-ref="transitionCancel" @click="closeCommand($refs.transitionDialog)"
                        :disabled="submitting || !!pending" class="{{ $secondary }}">Mégse</button>
                <button type="submit" x-show="!pending && !needsRefresh" :disabled="submitting"
                        :class="transition.vehicle.active ? @js($danger) : @js($primary)"
                        x-text="submitting ? 'Mentés…' : (transition.vehicle.active ? 'Inaktiválás megerősítése' : 'Újraaktiválás megerősítése')"></button>
            </div>
        </form>
    </template>
</dialog>
