<x-app-layout>
    @php
        $statuses = ['pending' => 'Várakozik', 'in_progress' => 'Folyamatban', 'picked' => 'Kitárolva', 'cancelled' => 'Visszavonva'];
        $config = [
            'snapshot' => $snapshot,
            'product' => $intent->product->product_name.' · '.$intent->product->sku,
            'statuses' => $statuses,
            'urls' => [
                'status' => route('transfer-intents.picking-task.show', ['transferIntent' => $intent, 'task' => $task['id']]),
                'recommendations' => route('transfer-intents.picking-task.recommendations', ['transferIntent' => $intent, 'task' => $task['id']]),
                'picks' => route('transfer-intents.picking-task.picks', $intent),
            ],
        ];
        $panel = 'min-w-0 rounded-lg border border-gray-200 bg-white p-3 sm:p-4';
        $secondary = 'inline-flex min-h-10 max-w-full items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $primary = 'inline-flex min-h-11 max-w-full items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $link = 'rounded text-sm font-medium text-indigo-700 underline decoration-indigo-200 underline-offset-2 hover:text-indigo-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600';
    @endphp
    <div class="app-page space-y-4 py-4" data-picking-task x-data="warehousePickingTask(@js($config))"
         @pageshow.window="if ($event.persisted) refreshTask()">
        <x-page-toolbar title="Kitárolási feladat">
            <a href="{{ route('transfer-intent-pages.show', $intent) }}" class="{{ $secondary }}">Átmozgatási igény megnyitása</a>
        </x-page-toolbar>
        @include('transfers.tabs')

        <p hidden :hidden="!accessError" x-ref="accessNotice" tabindex="-1" role="alert" x-text="accessError"
           class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900"></p>
        <div x-show="!accessError" class="space-y-4">
        <header class="{{ $panel }} grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-start">
            <div class="min-w-0 [overflow-wrap:anywhere]">
                <p class="text-xs text-gray-600"><span x-text="`Feladat #${task.id}`">Feladat #{{ $task['id'] }}</span><span aria-hidden="true"> · </span>{{ $intent->reference }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-gray-900">{{ $intent->product->product_name }}</h2>
                <p class="text-sm text-gray-600">{{ $intent->product->sku }}</p>
            </div>
            <dl class="min-w-0 text-sm [overflow-wrap:anywhere]">
                <div><dt class="inline text-gray-600">Forrásraktár</dt> <dd class="inline font-medium text-gray-900">{{ $intent->fromWarehouse->warehouse_name }}</dd></div>
                <div><dt class="inline text-gray-600">Célraktár</dt> <dd class="inline font-medium text-gray-900">{{ $intent->toWarehouse->warehouse_name }}</dd></div>
            </dl>
            <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                <span class="rounded-md px-2 py-1 text-xs font-semibold" :class="task.status === 'picked' ? 'bg-emerald-50 text-emerald-800' : 'bg-indigo-50 text-indigo-800'"
                      x-text="taskStatus">{{ $statuses[$task['status']] }}</span>
                <span class="text-xs text-gray-700">{{ \App\Support\TransferIntentDisplay::PRIORITY[$intent->priority] }} prioritás</span>
            </div>
        </header>

        <p x-cloak x-show="message" x-text="message" role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900"></p>
        <div x-cloak x-show="needsRefresh || refreshError" class="flex min-w-0 flex-wrap items-center gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            <p role="alert" x-text="refreshError || submissionError || sourceError || 'A folytatáshoz frissítsd a feladat adatait.'"></p>
            <button type="button" class="{{ $secondary }}" @click="refreshTask()" :disabled="refreshing || submitting">Feladat és készlet frissítése</button>
        </div>

        <div class="grid min-w-0 items-start gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] lg:grid-rows-[auto_minmax(0,1fr)] xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
            <section class="{{ $panel }} lg:col-start-2 lg:row-start-1" aria-labelledby="picking-progress-title">
                <h2 id="picking-progress-title" x-ref="progressHeading" tabindex="-1" class="font-semibold text-gray-900">Előrehaladás</h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
                    <div><dt class="text-xs text-gray-600">Elvárt</dt><dd class="font-semibold text-gray-900" x-text="quantity(task.expected_quantity)">{{ $task['expected_quantity'] }} db</dd></div>
                    <div><dt class="text-xs text-gray-600">Eddig kitárolva</dt><dd class="font-semibold text-gray-900" x-text="quantity(task.staged_quantity)">{{ $task['staged_quantity'] }} db</dd></div>
                    <div><dt class="text-xs text-gray-600">Hátralévő</dt><dd class="font-semibold text-indigo-800" x-text="quantity(remaining)"></dd></div>
                    <div><dt class="text-xs text-gray-600">Összekészítve</dt><dd class="font-semibold text-gray-900" x-text="quantity(task.staged_quantity)"></dd></div>
                </dl>
                <progress aria-label="Kitárolási előrehaladás" class="mt-3 h-2 w-full overflow-hidden rounded-full accent-indigo-600" max="100" :value="progress"></progress>
                <dl class="mt-3 space-y-2 border-t border-gray-200 pt-3 text-sm">
                    <div class="flex flex-wrap justify-between gap-x-2 gap-y-0.5"><dt class="text-gray-600">Lefoglalva, kitárolásra vár</dt><dd class="font-medium text-gray-900" x-text="quantity(snapshot.reservation?.remaining_quantity ?? 0)"></dd></div>
                    <div class="flex flex-wrap justify-between gap-x-2 gap-y-0.5"><dt class="text-gray-600">Más műveletekhez szabad</dt><dd class="font-medium text-gray-900" x-text="quantity(snapshot.free_quantity)"></dd></div>
                </dl>
                <p class="mt-3 text-xs text-gray-600" x-show="task.staged_quantity > 0">Az összekészített áru továbbra is a forrásraktárban van.</p>
            </section>

            <div class="min-w-0 space-y-4 lg:col-start-1 lg:row-start-1 lg:row-span-2">
                <section x-cloak x-show="canWork" class="{{ $panel }}" aria-labelledby="picking-sources-title" :aria-busy="loading">
                    <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-3 sm:justify-between">
                        <div role="group" aria-label="Forrásválasztási mód" class="flex flex-wrap gap-1.5">
                            <button type="button" class="{{ $secondary }}" :class="mode === 'suggestions' && 'border-indigo-500 bg-indigo-50 text-indigo-800'"
                                    :aria-pressed="mode === 'suggestions'" @click="mode = 'suggestions'">Ajánlott forráshelyek</button>
                            <button type="button" class="{{ $secondary }}" :class="mode === 'manual' && 'border-indigo-500 bg-indigo-50 text-indigo-800'"
                                    :aria-pressed="mode === 'manual'" @click="openManual()">Kézi forráshelyválasztás</button>
                        </div>
                        <button type="button" @click="loadSources()" :disabled="loading || refreshing || needsRefresh || draftLocked" class="{{ $secondary }}">Forráshelyek frissítése</button>
                    </div>
                    <h2 id="picking-sources-title" class="sr-only">Forráshelyek</h2>
                    <p x-cloak x-show="loading" role="status" class="mt-3 text-sm text-gray-700">Forráshelyek betöltése…</p>
                    <p x-cloak x-show="sourceError && !needsRefresh" x-text="sourceError" role="alert" class="mt-3 text-sm text-red-800"></p>
                    <p x-cloak x-show="!loading && !sourceError && !needsRefresh && !consistent" role="alert" class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900">A készlet- és tárhelyadatok eltérnek. Kitárolás előtt ellenőrizd a készletet.</p>
                    <div x-show="mode === 'suggestions' && !loading && !sourceError && !needsRefresh" class="mt-3 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm text-gray-700">Hátralévő mennyiség <strong class="text-gray-900" x-text="quantity(remaining)"></strong></p>
                            <button type="button" class="{{ $secondary }}" x-show="suggestedSources.length > 0" :disabled="!canApplySuggestion"
                                    @click="applySuggestion()">Ajánlás használata</button>
                        </div>
                        <p x-show="suggestedSources.length && draft.length && !adviceNotice" class="text-xs text-gray-600">A kijelölt források lent szerkeszthetők.</p>
                        <p x-show="suggestedQuantity > 0 && suggestedQuantity < remaining" class="text-sm text-gray-700">A rekeszajánlás <strong x-text="quantity(suggestedQuantity)"></strong> kivételét fedezi. A fennmaradó mennyiséghez kézzel is választhatsz forrást.</p>
                        <p x-show="!suggestedSources.length && consistent" class="text-sm text-gray-700">Nem található kitárolásra alkalmas rekesz.</p>
                        <p x-show="consistent && !hasUsableBin && !canUseUnassigned" role="status" class="text-sm text-gray-700">Jelenleg nincs ehhez a feladathoz kitárolható készlet.</p>
                        <ul x-show="suggestedSources.length > 0" tabindex="0" aria-label="Ajánlott forráshelyek listája" class="max-h-[27rem] space-y-2 overflow-y-auto rounded-md p-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                            <template x-for="source in suggestedSources" :key="key(source)">
                                <li data-picking-source class="min-w-0 rounded-md border border-gray-200 p-3">
                                    @include('warehouse-picking.source')
                                    <p class="mt-1 text-sm font-semibold text-indigo-800">Ajánlott kivétel <span x-text="quantity(source.quantity)"></span></p>
                                </li>
                            </template>
                        </ul>
                        <div x-show="canUseUnassigned" class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-3">
                            <p class="text-sm text-gray-700">A tárhelyhez nem rendelt készletet külön választhatod ki, ha fizikailag megtaláltad.</p>
                            <button type="button" class="{{ $secondary }}" @click="openManual(true)">Tárhely nélküli készlet kiválasztása</button>
                        </div>
                    </div>
                    <div hidden :hidden="mode !== 'manual' || !sourcesReady" class="mt-3 space-y-3">
                        <div>
                            <label for="picking-source-search" class="block text-sm font-medium text-gray-900">Forráshely keresése</label>
                            <input id="picking-source-search" x-ref="sourceSearch" x-model="search" type="search" placeholder="Rekesz, állvány, zóna, szint vagy termék…"
                                   class="mt-1 min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </div>
                        <p x-show="!matchingSources.length" class="text-sm text-gray-700">Nincs megjeleníthető rekesz.</p>
                        <ul x-show="matchingSources.length" tabindex="0" aria-label="Kézzel választható forráshelyek" class="max-h-[27rem] space-y-2 overflow-y-auto rounded-md p-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                            <template x-for="source in matchingSources" :key="key(source)">
                                <li data-picking-source class="min-w-0 rounded-md border border-gray-200 p-3">
                                    @include('warehouse-picking.source')
                                    <button type="button" class="{{ $secondary }} mt-2" :disabled="draftLocked || !consistent || !source.active || source.available_quantity < 1 || sourceSelected(source)"
                                            @click="addSource(source)" x-text="sourceSelected(source) ? 'Hozzáadva' : 'Forrás hozzáadása'"></button>
                                </li>
                            </template>
                        </ul>
                        <div hidden :hidden="!(unassignedSource?.stored_quantity > 0)" data-picking-unassigned class="rounded-md border border-gray-200 bg-gray-50 p-3">
                            <h3 x-ref="unassignedHeading" tabindex="-1" class="text-sm font-semibold text-gray-900">Tárhelyhez nem rendelt készlet</h3>
                            <p class="mt-1 text-sm text-gray-700">A készlet szerepel a raktár nyilvántartásában, de nincs hozzá konkrét rekesz megadva.</p>
                            <dl class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-700">
                                <div><dt class="inline">Nyilvántartva</dt> <dd class="inline font-semibold" x-text="quantity(unassignedSource?.stored_quantity)"></dd></div>
                                <div><dt class="inline">A feladathoz kitárolható</dt> <dd class="inline font-semibold" x-text="quantity(consistent ? unassignedSource?.available_quantity : null)"></dd></div>
                            </dl>
                            <button type="button" class="{{ $secondary }} mt-2" :disabled="draftLocked || !canUseUnassigned || hasUnassignedDraft"
                                    @click="addSource(unassignedSource)" x-text="hasUnassignedDraft ? 'Hozzáadva' : 'Forrás hozzáadása'"></button>
                        </div>
                        <p x-show="consistent && !hasUsableBin && !canUseUnassigned" class="text-sm text-gray-700">Jelenleg nincs ehhez a feladathoz kitárolható készlet.</p>
                    </div>
                </section>

                <section x-cloak x-show="canWork" class="{{ $panel }}" aria-labelledby="picking-draft-title">
                    <h2 id="picking-draft-title" x-ref="draftHeading" tabindex="-1" class="font-semibold text-gray-900">Rögzítendő kitárolás</h2>
                    <p role="status" x-text="adviceNotice" class="empty:hidden mt-2 text-sm font-medium text-emerald-800"></p>
                    <p x-show="!draft.length" class="mt-2 text-sm text-gray-600">Használd az ajánlást vagy adj hozzá forráshelyet kézzel.</p>
                    <ul x-show="draft.length" class="mt-2 divide-y divide-gray-200">
                        <template x-for="row in draft" :key="key(row)">
                            <li data-picking-allocation class="grid min-w-0 gap-2 py-2 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center">
                                <label :for="`pick-quantity-${key(row)}`" class="text-sm font-medium text-gray-900 [overflow-wrap:anywhere]" x-text="row.label"></label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <input :id="`pick-quantity-${key(row)}`" type="text" inputmode="numeric" maxlength="10" x-model="row.quantity"
                                           @input="adviceNotice = ''; if (row.source_type === 'unassigned') unassignedFound = false"
                                           :aria-label="`Mennyiség · ${row.label}`" :aria-invalid="Boolean(rowError(row))" :aria-describedby="rowError(row) ? `pick-error-${key(row)}` : null"
                                           :disabled="draftLocked" class="min-h-10 w-24 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <span class="text-sm text-gray-600">db</span>
                                </div>
                                <button type="button" :aria-label="`Eltávolítás · ${row.label}`" @click="removeSource(row)" :disabled="draftLocked" class="{{ $secondary }} justify-self-start">Eltávolítás</button>
                                <p :id="`pick-error-${key(row)}`" x-show="rowError(row)" x-text="rowError(row)" class="text-xs text-red-800 sm:col-span-3"></p>
                            </li>
                        </template>
                    </ul>
                    <p x-show="draftError" x-text="draftError" role="alert" class="mt-2 text-sm text-red-800"></p>
                    <p x-show="unconfirmedPick" class="mt-2 text-sm text-amber-900">A rögzítés eredménye nem ellenőrizhető. Próbáld újra ugyanezt a műveletet.</p>
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-3">
                        <p class="text-sm text-gray-700">Rögzítendő <strong class="text-gray-900" x-text="quantity(total)"></strong></p>
                        <button type="button" @click="openConfirmation()" :disabled="!canConfirm" class="{{ $primary }}">Kitárolás rögzítése</button>
                    </div>
                </section>

                <section x-cloak x-show="!canWork" class="{{ $panel }}" aria-label="A feladat eredménye">
                    <p x-show="task.status === 'picked'" class="font-semibold text-emerald-800">A kitárolás befejeződött.</p>
                    <p x-show="task.status === 'cancelled'" class="font-semibold text-gray-900">A feladatot visszavonták.</p>
                </section>
            </div>

            <section class="{{ $panel }} lg:col-start-2 lg:row-start-2" data-picking-history aria-labelledby="picking-history-title">
                <h2 id="picking-history-title" class="font-semibold text-gray-900">Rögzített kitárolások</h2>
                <p x-show="!snapshot.allocations.length" class="mt-2 text-sm text-gray-600">Még nincs rögzített kitárolás.</p>
                <ol x-show="snapshot.allocations.length" class="mt-2 divide-y divide-gray-200">
                    <template x-for="allocation in snapshot.allocations" :key="allocation.id">
                        <li class="py-2 text-sm [overflow-wrap:anywhere]">
                            <div class="flex flex-wrap justify-between gap-x-2"><strong class="text-gray-900" x-text="historicalSource(allocation)"></strong><span class="font-semibold text-gray-900" x-text="quantity(allocation.quantity)"></span></div>
                            <p class="mt-0.5 text-xs text-gray-600"><span x-text="allocation.performer_name || '—'"></span> · <time :datetime="allocation.performed_at" x-text="eventDate(allocation.performed_at)"></time></p>
                            <p x-show="allocation.unassigned_confirmation?.found === true" class="mt-1 text-xs text-gray-700">
                                <span class="font-medium">Fizikai megtalálás visszaigazolva</span><span aria-hidden="true"> · </span><span x-text="allocation.unassigned_confirmation?.description"></span>
                            </p>
                            <p x-show="allocation.load_requires_verification" class="mt-1 text-xs font-medium text-amber-900">A rekesz terhelése ellenőrzésre vár.</p>
                            <a x-show="allocation.plan_url" :href="allocation.plan_url" target="_blank" rel="noopener" class="{{ $link }} mt-1 inline-flex min-h-10 items-center">Hely az alaprajzon<span class="sr-only"> (új lapon)</span></a>
                        </li>
                    </template>
                </ol>
                <a href="{{ route('transfer-intent-pages.show', $intent) }}#intent-history-heading" class="{{ $link }} mt-2 inline-flex min-h-10 items-center">Igény eseménytörténete</a>
            </section>
        </div>
        </div>

        <dialog x-ref="confirmation" aria-labelledby="picking-confirm-title" @cancel.prevent="closeConfirmation()"
                class="m-auto max-h-[calc(100dvh-1.5rem)] w-[calc(100%-1.5rem)] max-w-md overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 text-gray-900 shadow-xl backdrop:bg-gray-900/55 sm:p-5">
            <h2 id="picking-confirm-title" class="text-lg font-semibold">Kitárolás megerősítése</h2>
            <p class="mt-1 text-sm text-gray-700 [overflow-wrap:anywhere]" x-text="product"></p>
            <ul class="my-3 divide-y divide-gray-200 border-y border-gray-200 text-sm">
                <template x-for="row in draft" :key="key(row)">
                    <li class="flex flex-wrap justify-between gap-x-3 py-2 [overflow-wrap:anywhere]"><span x-text="row.label"></span><strong x-text="quantity(row.quantity)"></strong></li>
                </template>
            </ul>
            <p class="text-sm font-medium">Most összekészítendő <strong x-text="quantity(total)"></strong></p>
            <p class="mt-1 text-sm text-gray-600">A készlet elérhetőségét mentéskor újra ellenőrizzük.</p>
            <fieldset x-show="hasUnassignedDraft" :disabled="draftLocked" class="mt-3 space-y-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm">
                <legend class="px-1 font-medium text-gray-900">Tárhelyhez nem rendelt készlet</legend>
                <label class="flex items-start gap-2 font-medium text-gray-900">
                    <input type="checkbox" x-model="unassignedFound" :required="hasUnassignedDraft" class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span>Ellenőriztem, hogy a terméket fizikailag megtaláltam.</span>
                </label>
                <div>
                    <label for="pick-found-at" class="block font-medium text-gray-900">Hol találtad meg a terméket?</label>
                    <input id="pick-found-at" type="text" x-model="unassignedDescription" maxlength="255" :required="hasUnassignedDraft"
                           placeholder="Pl. a kiadási terület mellett" class="mt-1 min-h-10 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </fieldset>
            <div x-show="submissionError" role="alert" class="mt-3 rounded-md bg-amber-50 p-2 text-sm text-amber-900">
                <p x-text="submissionError"></p>
                <template x-for="[field, messages] in Object.entries(fieldErrors)" :key="field"><p x-text="messages.join(' ')"></p></template>
            </div>
            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <button type="button" autofocus @click="closeConfirmation()" :disabled="submitting" class="{{ $secondary }}">Vissza</button>
                <button type="button" x-ref="refreshInDialog" x-show="needsRefresh" @click="refreshTask()" :disabled="submitting || refreshing" class="{{ $secondary }}">Feladat és készlet frissítése</button>
                <button type="button" @click="recordPick()" :disabled="!canRecordPick" class="{{ $primary }}"><span x-text="submitting ? 'Rögzítés…' : 'Rögzítés'">Rögzítés</span></button>
            </div>
        </dialog>
    </div>
</x-app-layout>
