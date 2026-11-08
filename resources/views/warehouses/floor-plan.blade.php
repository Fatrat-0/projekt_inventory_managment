<x-warehouse-layout :$warehouse :$context :$detailContext>
    <div class="app-page space-y-4 py-4" x-data="warehouseFloorPlan(@js($plan))" @keydown.escape="escapePlacement($event)">
        <x-page-toolbar title="Raktári tervrajz" :description="$warehouse->warehouse_name">
            @if($plan['canEdit'])
                <button type="button" x-cloak x-show="!editing" x-ref="edit" @click="beginEdit()" :disabled="stale"
                    class="min-h-11 rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Tervrajz szerkesztése</button>
                <div x-cloak x-show="editing && !wizard && !systemEdit" class="flex flex-wrap gap-2">
                    <button type="button" @click="cancel()" :disabled="saving" class="min-h-11 rounded-md border border-gray-300 bg-white px-4 text-sm font-medium disabled:opacity-50">Mégse</button>
                    <button type="button" @click="save()" :disabled="saving || !!drag || stale || !!issue || !changed || !!wizard || !!systemEdit" class="min-h-11 rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" x-text="saving ? 'Mentés…' : 'Tervrajz mentése'">Tervrajz mentése</button>
                </div>
            @endif
        </x-page-toolbar>

        <p x-cloak x-show="feedback" x-text="feedback" role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800"></p>
        <template x-if="createdSystem">
            <section aria-label="Létrehozási összefoglaló" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
                <div>
                    <p class="font-semibold" x-text="`${createdSystem.code} · ${createdSystem.name} létrejött`"></p>
                    <p x-text="`${createdSystem.levels.reduce((count, level) => count + level.bins.length, 0)} tárolóhely · ${kilograms(createdSystem.occupied + createdSystem.reserved + createdSystem.free)} kg használható összkapacitás`"></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a :href="createdSystem.frontUrl" class="inline-flex min-h-10 items-center rounded-md bg-emerald-800 px-3 font-medium text-white hover:bg-emerald-900" x-text="createdSystem.kind === 'floor' ? 'Új tárolóterület megnyitása' : 'Új állvány megnyitása'"></a>
                    <button type="button" @click="createdSystem = null" class="min-h-10 px-2 font-medium">Bezárás</button>
                </div>
            </section>
        </template>

        <div x-cloak x-show="!previewActive" role="group" aria-label="Tervrajz eszköztára" class="flex flex-wrap items-center gap-1 rounded-lg border border-gray-200 bg-white p-2 text-sm">
            <button type="button" @click="tool = 'select'" :aria-pressed="tool === 'select'" class="floor-tool">Kijelölés</button>
            <button type="button" @click="tool = 'pan'" :aria-pressed="tool === 'pan'" class="floor-tool">Nézet húzása</button>
            <button type="button" @click="setZoom(zoom - 0.25)" :disabled="zoom <= 1" aria-label="Kicsinyítés" class="floor-tool">−</button>
            <button type="button" @click="setZoom(1); $refs.viewport.scrollTo(0, 0)" aria-label="Raktár illesztése a nézethez" class="floor-tool">Illesztés</button>
            <button type="button" @click="setZoom(zoom + 0.25)" :disabled="zoom >= 4" aria-label="Nagyítás" class="floor-tool">+</button>
            <span class="px-1 text-xs tabular-nums text-gray-600" x-text="`${Math.round(zoom * 100)}%`"></span>
            @if($plan['canEdit'])
                <button type="button" @click="beginWizard('rack')" :disabled="changed || saving || stale || !!wizard || !!systemEdit" class="floor-tool">Polcos állvány hozzáadása</button>
                <button type="button" @click="beginWizard('pallet')" :disabled="changed || saving || stale || !!wizard || !!systemEdit" class="floor-tool">Raklapos állvány hozzáadása</button>
                <button type="button" @click="beginWizard('floor')" :disabled="changed || saving || stale || !!wizard || !!systemEdit" class="floor-tool">Padlótároló zóna hozzáadása</button>
                <div class="flex flex-wrap gap-1">
                    <button type="button" @click="if (!editing) beginEdit(); tool = 'move'" :aria-pressed="editing && tool === 'move'" :disabled="saving || stale || !!wizard || !!systemEdit" class="floor-tool">Mozgatás</button>
                    <button type="button" @click="if (!editing) beginEdit(); rotate(selectedPosition)" :disabled="!selectedPosition || saving || stale || !!wizard || !!systemEdit" class="floor-tool">Forgatás</button>
                    <button type="button" @click="historyMove(-1)" :disabled="historyIndex === 0 || saving || !!wizard || !!drag || !!systemEdit" class="floor-tool">Visszavonás</button>
                    <button type="button" @click="historyMove(1)" :disabled="historyIndex >= history.length - 1 || saving || !!wizard || !!drag || !!systemEdit" class="floor-tool">Újra</button>
                </div>
            @endif
        </div>
        <datalist id="floor-zones">@foreach($plan['zones'] as $zone)<option value="{{ $zone }}">@endforeach</datalist>

        <div x-cloak class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_19rem]">
            @if($plan['canEdit']) @include('warehouses.designer-wizard-preview') @endif
            <section x-show="!previewActive" aria-labelledby="floor-grid-title" class="min-w-0 rounded-lg border border-gray-200 bg-white">
                <div class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-200 px-4 py-3">
                    <div><h2 id="floor-grid-title" class="font-semibold text-gray-900">Felülnézet</h2>
                        <p class="text-xs text-gray-600"><span x-text="(columns / 2).toLocaleString('hu-HU')"></span> × <span x-text="(rows / 2).toLocaleString('hu-HU')"></span> m · 1 cella = 0,5 × 0,5 m</p>
                    </div>
                    <p x-show="editing && !wizard" class="text-xs text-gray-600">Húzd a tárhelyet, vagy mozgasd a nyílbillentyűkkel.</p>
                    <p x-show="placementActive" class="text-xs text-gray-600">Új objektum elhelyezése</p>
                </div>
                <div x-ref="viewport" tabindex="0" aria-label="Görgethető raktári rács" class="h-[54vh] min-h-52 overflow-auto overscroll-contain bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"
                    :class="tool === 'pan' ? 'touch-none cursor-grab' : (positioning ? 'cursor-crosshair' : '')"
                    @keydown="placementKey($event)" @pointerdown="panStart($event)" @pointermove="panMove($event)" @pointerup="panEnd($event)" @lostpointercapture="panEnd($event)">
                    <div class="flex min-h-full min-w-full">
                    <div x-ref="board" @click="choosePlacement($event)" @pointermove="hoverPlacement($event)" @pointerleave="if (positioning) placementHover = null" class="warehouse-floor-grid relative m-auto shrink-0 overflow-hidden" :style="{ width: `${columns * cellSize}px`, height: `${rows * cellSize}px`, '--floor-cell': `${cellSize}px` }">
                        <template x-for="{ location, position } in placed" :key="position.id">
                            <button type="button" :data-floor-tile="position.id" :style="tileStyle(position)" :aria-pressed="selectedId === position.id" :tabindex="placementActive ? -1 : 0"
                                :aria-label="`${location.code} · ${location.name} · ${capacityState(location)}`" :title="`${location.code} · ${location.name}`"
                                @click="if (tool !== 'pan' && !wizard) { select(position.id); if (tool === 'select') showInspector() }" @pointerdown="startDrag($event, position)" @pointermove="moveDrag($event, position)"
                                @pointerup="finishDrag($event, position)" @pointercancel="finishDrag($event, position, true)" @lostpointercapture="finishDrag($event, position, true)" @keydown="moveByKey($event, position)"
                                class="warehouse-floor-tile absolute overflow-hidden rounded border p-1 text-left text-xs leading-tight focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-700"
                                :class="{
                                    'touch-none cursor-grab': editing && tool === 'move', 'z-10 cursor-grabbing': drag?.id === position.id,
                                    'z-10 ring-2 ring-inset ring-indigo-600': selectedId === position.id,
                                    'outline outline-2 -outline-offset-2 outline-rose-700': placementOverlaps(position),
                                    'border-slate-400 bg-slate-200 text-slate-800': capacityTone(location) === 'blocked',
                                    'border-rose-400 bg-rose-100 text-rose-900': capacityTone(location) === 'full',
                                    'border-amber-400 bg-amber-100 text-amber-900': capacityTone(location) === 'near',
                                    'border-emerald-400 bg-emerald-100 text-emerald-900': capacityTone(location) === 'available',
                                }">
                                <strong class="block truncate" x-text="location.code"></strong>
                                <span class="block truncate" x-text="location.name"></span>
                            </button>
                        </template>
                        <p x-show="!placed.length" class="pointer-events-none sticky left-0 top-0 max-w-64 p-4 text-sm text-slate-600">A tervrajzon még nincs elhelyezett tárhely.</p>
                        <template x-if="placementActive">
                            <div aria-hidden="true" data-placement-preview :data-placement-state="placementState" :data-invalid="!!placementIssue(shownPlacement)"
                                class="pointer-events-none absolute z-20 overflow-hidden rounded border-2 p-1 text-xs" :style="tileStyle(shownPlacement)"
                                :class="positioning ? (placementIssue(shownPlacement) ? 'border-dashed border-rose-700 bg-rose-100/80 text-rose-900' : 'border-dashed border-emerald-700 bg-emerald-100/80 text-emerald-900') : 'border-solid border-indigo-700 bg-indigo-100/80 font-semibold text-indigo-900'"
                                x-text="draftCode"></div>
                        </template>
                    </div>
                    </div>
                </div>
                <template x-if="placementActive">
                    <p class="border-t border-gray-200 px-4 py-2 text-sm font-medium" :class="positioning && placementIssue(shownPlacement) ? 'text-rose-700' : 'text-emerald-800'"
                        x-text="savingPlacement ? 'Elhelyezés mentése…' : (positionSelected ? 'Pozíció rögzítve' : (placementIssue(shownPlacement) || 'Szabad hely. Kattints vagy nyomj Entert a rögzítéshez.'))"></p>
                </template>
                <div x-show="stale || (editing && !wizard && !systemEdit && (issue || serverError))" role="alert" class="border-t border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                    <p x-text="serverError || issue"></p>
                    <a x-show="stale" href="{{ request()->fullUrl() }}" class="mt-1 inline-block font-medium underline">Mentett tervrajz betöltése</a>
                </div>
                <p x-show="editing && changed" class="border-t border-gray-200 px-4 py-2 text-xs text-gray-600">Új rendszer hozzáadása vagy a teherbírás szerkesztése előtt mentsd az elrendezést.</p>
                <ul class="flex flex-wrap gap-x-4 gap-y-1 border-t border-gray-200 px-4 py-3 text-xs text-gray-700" aria-label="Kapacitás jelmagyarázata">
                    <li><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-emerald-300" aria-hidden="true"></span>Van szabad kapacitás</li>
                    <li><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-amber-300" aria-hidden="true"></span>Közel telített</li>
                    <li><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-rose-300" aria-hidden="true"></span>Betelt</li>
                    <li><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-slate-300" aria-hidden="true"></span>Zárolt vagy kikapcsolt</li>
                </ul>
            </section>

            <aside x-ref="inspector" aria-label="A tervrajz adatai" class="min-w-0 space-y-3 scroll-mt-24">
                @if($plan['canEdit']) @include('warehouses.designer-wizard') @endif
                <div x-show="!wizard" class="space-y-3" @change="if (editing && !systemEdit) record()">
                @if($plan['canEdit'])
                    <fieldset x-show="editing && !systemEdit" :disabled="saving" class="min-w-0 rounded-lg border border-gray-200 bg-white p-4">
                        <legend class="px-1 text-sm font-semibold text-gray-900">Rács mérete</legend>
                        <div class="grid grid-cols-2 gap-2 text-xs font-medium text-gray-700">
                            <label>Szélesség (cella)<input type="number" x-model.number="plan.columns" :min="minSize" :max="maxSize" class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
                            <label>Magasság (cella)<input type="number" x-model.number="plan.rows" :min="minSize" :max="maxSize" class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
                        </div>
                    </fieldset>
                @endif

                <section class="min-w-0 space-y-2 rounded-lg border border-gray-200 bg-white p-4">
                    <label for="floor-location" class="block text-sm font-semibold text-gray-900">Tárhely keresése a tervrajzon</label>
                    <select id="floor-location" x-model.number="selectedId" :disabled="!!systemEdit" @change="$nextTick(() => revealSelected())" class="w-full min-w-0 rounded-md border-gray-300 text-sm">
                        <option value="">Válassz tárhelyet</option>
                        <template x-for="location in locations" :key="location.id"><option :value="location.id" x-text="`${location.code} · ${location.name}`"></option></template>
                    </select>
                    <template x-if="selected">
                        <div class="min-w-0 space-y-2 text-sm">
                            <p class="break-words font-semibold text-gray-900" x-text="selected.name"></p>
                            <p class="text-xs text-gray-600" x-text="`${selected.kindLabel} · ${capacityState(selected)}`"></p>
                            <p x-show="selectedPosition" class="text-xs text-gray-600" x-text="selectedPosition ? `${(selectedPosition.width / 2).toLocaleString('hu-HU')} × ${(selectedPosition.height / 2).toLocaleString('hu-HU')} m` : ''"></p>
                            @include('warehouses.designer-capacity', ['item' => 'selected'])
                            @include('warehouses.designer-system')
                            <div class="border-t border-gray-100 pt-2 text-xs text-gray-700">
                                <h3 class="mb-1 font-semibold">Tárolt termékek</h3>
                                <p x-show="!selected.products.length">Nincs tárolt készlet.</p>
                                <ul class="space-y-1">
                                    <template x-for="product in selected.products" :key="product.id"><li class="flex justify-between gap-2"><span class="min-w-0 break-words" x-text="product.name"></span><span class="shrink-0" x-text="`${product.quantity} db`"></span></li></template>
                                </ul>
                                <p x-show="selected.productCount > selected.products.length" class="mt-1" x-text="`További ${selected.productCount - selected.products.length} termék a tárhelyek adatlapjain`"></p>
                            </div>
                            <template x-if="!selected.systemId">
                                <a :href="selected.url" class="inline-flex min-h-10 items-center font-medium text-indigo-700 hover:underline">Tárhely adatlapja</a>
                            </template>
                            @if($plan['canEdit'])
                                <template x-if="editing && selectedPosition && !systemEdit">
                                    <fieldset :disabled="saving" class="min-w-0 space-y-2 border-t border-gray-200 pt-2 text-xs text-gray-700">
                                        <legend class="pr-2 font-semibold">Helyzet és méret (cella)</legend>
                                        <div class="grid grid-cols-2 gap-2">
                                            <label>Balról (X)<input type="number" min="0" max="99" x-model.number="selectedPosition.x" class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
                                            <label>Felülről (Y)<input type="number" min="0" max="99" x-model.number="selectedPosition.y" class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
                                            <label x-show="!selected.fixedSize">Tárhely szélessége<input type="number" min="1" max="100" x-model.number="selectedPosition.width" class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
                                            <label x-show="!selected.fixedSize">Tárhely magassága<input type="number" min="1" max="100" x-model.number="selectedPosition.height" class="mt-1 w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
                                        </div>
                                        <div class="flex flex-wrap gap-x-3 gap-y-1">
                                            <button type="button" @click="rotate(selectedPosition)" class="min-h-10 font-medium text-indigo-700 hover:underline">Elforgatás</button>
                                            <button type="button" x-show="!selected.systemId" @click="remove(selectedId)" class="min-h-10 font-medium text-rose-700 hover:underline">Levétel a tervrajzról</button>
                                        </div>
                                    </fieldset>
                                </template>
                            @endif
                        </div>
                    </template>
                </section>

                <section class="min-w-0 space-y-2 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-semibold text-gray-900">Tervrajzon kívüli tárhelyek <span class="font-normal text-gray-600" x-text="`(${unplaced.length})`"></span></h2>
                    <p x-show="!unplaced.length && locations.length" class="text-sm text-gray-600">Minden tárhely szerepel a tervrajzon.</p>
                    <p x-show="!locations.length" class="text-sm text-gray-600">Ebben a raktárban még nincs tárhely.</p>
                    <ul class="max-h-60 space-y-1 overflow-auto">
                        <template x-for="location in unplaced" :key="location.id">
                            <li class="flex items-center justify-between gap-2">
                                <button type="button" @click="select(location.id)" class="min-h-10 min-w-0 break-words text-left text-sm font-medium text-indigo-700 hover:underline" x-text="`${location.code} · ${location.name}`"></button>
                                @if($plan['canEdit'])
                                    <button type="button" x-show="!systemEdit" :disabled="saving || stale" @click="if (!editing) beginEdit(); add(location)" :aria-label="`${location.code} elhelyezése`" class="min-h-10 shrink-0 rounded border border-gray-300 px-2 text-xs font-medium text-gray-700 hover:bg-gray-50">Elhelyezés</button>
                                @endif
                            </li>
                        </template>
                    </ul>
                </section>
                </div>
            </aside>
        </div>

        <noscript>
            <p class="rounded-md border border-gray-200 bg-white p-3 text-sm">A tervrajz megjelenítéséhez JavaScript szükséges. <a href="{{ route('warehouses.locations.index', array_filter(['warehouse' => $warehouse, 'context' => $context, 'detail_context' => $detailContext])) }}" class="font-medium underline">Tárhelylista megnyitása</a></p>
        </noscript>
    </div>
</x-warehouse-layout>
