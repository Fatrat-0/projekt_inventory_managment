@props(['warehouse', 'item', 'prefix' => "''", 'draft' => false])

<fieldset x-cloak disabled
    x-data="{
        get choices() { return storageLocations[String({{ $warehouse }})] ?? null; },
        get pallet() { return this.choices?.some(location => location.id === String({{ $item }}.to_location_id) && location.kind === 'pallet'); },
        field(name) { const prefix = {{ $prefix }}; return prefix ? prefix + '[' + name + ']' : name; }
    }"
    x-show="choices !== null" :disabled="!choices?.length"
    {{ $attributes->class(['space-y-2 rounded-md border border-indigo-100 bg-indigo-50/40 p-3']) }}>
    <legend class="px-1 text-sm font-semibold text-gray-900">Cél tárhely és helyfoglalás</legend>
    <p x-show="choices?.length === 0" class="text-sm text-amber-800">A célraktárban nincs aktív tárhely. Indítás előtt a raktárkezelőnek aktiválnia kell egyet.</p>
    <div x-show="choices?.length" class="grid gap-3 sm:grid-cols-2">
        <label class="min-w-0 text-sm font-medium sm:col-span-2">Cél tárhely
            <select :name="field('to_location_id')" x-model="{{ $item }}.to_location_id" @required(!$draft) class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                <option value="">Válassz tárhelyet…</option>
                <template x-for="location in choices ?? []" :key="location.id"><option :value="location.id" x-text="location.label"></option></template>
            </select>
        </label>
        <label class="text-sm font-medium">Teljes tömeg (kg)
            <input :name="field('cargo_weight_kg')" x-model="{{ $item }}.cargo_weight_kg" :disabled="!{{ $item }}.to_location_id" type="number" min="0.001" max="1000000" step="0.001" required class="mt-1 block w-full rounded-md border-gray-300 text-sm">
        </label>
        <label x-show="pallet" class="text-sm font-medium">Raklapok száma
            <input :name="field('pallet_slots')" x-model="{{ $item }}.pallet_slots" :disabled="!pallet" :required="pallet" type="number" min="1" max="1000000" step="1" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
        </label>
    </div>
    <p x-show="choices?.length" class="text-xs text-gray-600">A tömegbe a csomagolást és a raklapot is számítsd bele. A szabad kapacitást az indításkor újra ellenőrizzük.</p>
</fieldset>
