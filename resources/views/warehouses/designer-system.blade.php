<template x-if="selected.systemId">
    <div class="space-y-3">
        <p class="text-xs text-gray-600" x-text="`${selected.code} · Zóna: ${selected.zone}`"></p>
        <a :href="selected.frontUrl" x-text="selected.kind === 'floor' ? 'Tárolóterület megnyitása' : 'Állvány megnyitása'" class="inline-flex min-h-10 items-center rounded-md bg-indigo-50 px-3 text-sm font-medium text-indigo-700 hover:bg-indigo-100"></a>
        <p x-show="selected.rackHeight !== null" class="text-xs text-gray-600" x-text="selected.rackHeight === null ? '' : `Állványmagasság · ${selected.rackHeight.toLocaleString('hu-HU')} m`"></p>
        @if($plan['canEdit'])
            <button type="button" x-show="!systemEdit" @click="beginSystemEdit(selected)" :disabled="changed || saving || stale" class="min-h-10 text-sm font-medium text-indigo-700 disabled:opacity-50">Tulajdonságok szerkesztése</button>
            <template x-if="systemEdit">
                <fieldset :disabled="saving" class="space-y-2 rounded-md border border-gray-200 p-2 text-sm">
                    <label class="block">Név<input maxlength="80" x-model="systemEdit.name" class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                    <label class="block">Zóna<input maxlength="80" list="floor-zones" x-model="systemEdit.zone" class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                    <label x-show="selected.kind !== 'floor'" class="block">Állvány magassága (m)<input type="number" min="0.5" max="30" step="0.01" x-model.number="systemEdit.rack_height" class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                    <label x-show="selected.kind !== 'floor'" class="block">Összes szint új teherbírása (kg)
                        <input type="number" min="0.001" step="0.001" x-model.number="systemEdit.level_weight" placeholder="Jelenlegi értékek megtartása" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                    </label>
                    <label class="block">Teljes rendszer (kg)<input type="number" min="0.001" step="0.001" x-model.number="systemEdit.system_weight" class="mt-1 w-full rounded-md border-gray-300 text-sm"></label>
                    <p x-show="serverError" role="alert" class="text-xs text-rose-700" x-text="serverError"></p>
                    <div class="flex flex-wrap gap-2"><button type="button" @click="saveSystemEdit()" :disabled="stale" class="min-h-10 rounded bg-indigo-600 px-3 text-white disabled:opacity-50">Tulajdonságok mentése</button>
                        <button type="button" @click="systemEdit = null; serverError = ''" class="min-h-10 px-2">Mégse</button></div>
                </fieldset>
            </template>
        @endif
        <h3 class="font-semibold" x-text="selected.kind === 'floor' ? 'Padlóhelyek' : 'Szintek és tárolóhelyek'"></h3>
        <template x-for="level in selected.levels" :key="level.name">
            <details class="rounded border border-gray-200 p-2">
                <summary class="cursor-pointer text-sm font-medium" x-text="`${level.name} · ${level.bins.length} hely · ${capacityState(level)}`"></summary>
                <div class="mt-2 space-y-2">
                    @include('warehouses.designer-capacity', ['item' => 'level'])
                    <template x-for="bin in level.bins" :key="bin.id">
                        <details class="border-t border-gray-100 py-2">
                            <summary class="cursor-pointer text-xs font-medium" x-text="`${bin.code} · ${capacityState(bin)}`"></summary>
                            <div class="mt-2 space-y-1">
                                @include('warehouses.designer-capacity', ['item' => 'bin'])
                                <p x-show="!bin.products.length" class="text-xs">A tárhely üres.</p>
                                <template x-for="product in bin.products" :key="product.id"><p class="break-words text-xs" x-text="`${product.name} · ${product.quantity} db`"></p></template>
                                <p x-show="bin.productCount > bin.products.length" class="text-xs" x-text="`További ${bin.productCount - bin.products.length} termék a tárhely adatlapján`"></p>
                                <a :href="bin.url" class="text-xs font-medium text-indigo-700 hover:underline">Tárhely adatlapja</a>
                            </div>
                        </details>
                    </template>
                </div>
            </details>
        </template>
    </div>
</template>
