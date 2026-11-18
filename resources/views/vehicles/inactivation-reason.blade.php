<div>
    <label for="{{ $reasonId }}" class="block text-sm font-medium">Inaktiválás indoka <span class="font-normal">(opcionális)</span></label>
    <textarea id="{{ $reasonId }}" x-model="{{ $reasonModel }}" :disabled="commandLocked" rows="2" maxlength="500" class="{{ $field }} mt-1"
              :aria-invalid="!!fieldError('inactivation_reason')" aria-describedby="{{ $reasonId }}-error"></textarea>
    <p id="{{ $reasonId }}-error" class="mt-1 text-sm text-red-800" x-show="fieldError('inactivation_reason')" x-text="fieldError('inactivation_reason')"></p>
</div>
