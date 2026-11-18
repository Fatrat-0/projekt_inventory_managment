<div x-show="submissionError" class="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
    <p role="alert" x-text="submissionError"></p>
    <p x-show="needsRefresh" class="text-xs">A frissítés után a műveletet az aktuális adatokkal indíthatod újra.</p>
    <button type="button" x-show="needsRefresh" @click="refreshAfterConflict($refs.{{ $commandDialog }})" class="{{ $secondary }}">Járműlista frissítése</button>
    <button type="button" x-show="pending" @click="retryCommand()" :disabled="submitting"
            class="{{ $secondary }}" x-text="submitting ? 'Újrapróbálás…' : 'Mentés újrapróbálása'">Mentés újrapróbálása</button>
</div>
