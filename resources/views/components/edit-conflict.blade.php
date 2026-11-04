@props(['record', 'values', 'reloadUrl', 'fieldMap' => []])
@php
    $submittedVersion = old('lock_version', $record->lock_version);
    $validVersion = is_scalar($submittedVersion) && filter_var($submittedVersion, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) !== false;
    $conflict = !$validVersion || (int) $submittedVersion !== (int) $record->lock_version || $errors->hasAny(['lock_version', 'review_version', 'confirm_edit_conflict']);
    $differences = collect($values)->filter(function ($value, $label) use ($fieldMap, $record) {
        if (!isset($fieldMap[$label])) return true;
        $submitted = old($fieldMap[$label]);
        return !is_scalar($submitted) && $submitted !== null || (string) $submitted !== (string) $record->getAttribute($fieldMap[$label]);
    });
@endphp
<input type="hidden" name="lock_version" value="{{ $validVersion ? $submittedVersion : $record->lock_version }}">
@if($conflict)
    <section aria-labelledby="edit-conflict-title" class="space-y-4 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
        <div>
            <h3 id="edit-conflict-title" class="font-semibold">Az aktuális adatok átnézése szükséges</h3>
            <p class="mt-1">A saját beírt adataid az űrlapon maradtak. Hasonlítsd össze őket az alábbi, jelenleg mentett értékekkel, és szükség esetén javítsd az űrlapot.</p>
        </div>
        <details open>
            <summary class="cursor-pointer font-medium">Eltérő mentett adatok</summary>
            <dl tabindex="0" aria-label="Eltérő mentett adatok" class="mt-3 max-h-80 space-y-2 overflow-y-auto overscroll-contain rounded-md bg-white p-3">
                @foreach($differences as $label => $value)
                    <div class="grid gap-1 sm:grid-cols-3">
                        <dt class="font-medium break-words">{{ $label }}</dt>
                        <dd class="min-w-0 whitespace-pre-wrap break-all sm:col-span-2">{{ $value === null || $value === '' ? 'Nincs megadva' : $value }}</dd>
                    </div>
                @endforeach
                @if($differences->isEmpty())<div><dt class="sr-only">Állapot</dt><dd>A mezőértékek megegyeznek; az adatlapot közben újra mentették.</dd></div>@endif
            </dl>
        </details>
        <input type="hidden" name="review_version" value="{{ $record->lock_version }}">
        <label class="flex items-start gap-2">
            <input type="checkbox" name="confirm_edit_conflict" value="1" required class="mt-0.5 shrink-0 rounded border-amber-400 text-indigo-600">
            <span>Átnéztem az aktuális adatokat; az űrlapom értékeivel szeretnék menteni. Ez felülírhatja a közben mentett módosításokat.</span>
        </label>
        <a href="{{ $reloadUrl }}" class="inline-block font-medium text-indigo-700 underline">Aktuális adatok betöltése a saját módosításaim helyett</a>
    </section>
@endif
