@props(['sorts', 'filters', 'form'])
@php
    $applied = \Illuminate\Support\Arr::only($filters, ['sort_by', 'sort_dir', 'then_by', 'then_dir']);
    $summary = $sorts[$filters['sort_by']]."\u{00A0}".($filters['sort_dir'] === 'asc' ? '↑' : '↓');
    if (!empty($filters['then_by'])) $summary .= ', '.$sorts[$filters['then_by']]."\u{00A0}".($filters['then_dir'] === 'asc' ? '↑' : '↓');
    $field = 'mt-1 min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500';
@endphp
<div data-listing-sort x-data="listingSort(@js($applied), @js($form))" @pageshow.window="restorePage($event)" {{ $attributes->class('min-w-0 max-w-full text-sm') }}>
    <input type="hidden" name="sort_by" value="{{ $filters['sort_by'] }}" disabled :disabled="false" :value="params.sort_by">
    <input type="hidden" name="sort_dir" value="{{ $filters['sort_dir'] }}" disabled :disabled="false" :value="params.sort_dir">
    <input type="hidden" name="then_by" value="{{ $filters['then_by'] ?? '' }}" disabled :disabled="!params.then_by" :value="params.then_by || ''">
    <input type="hidden" name="then_dir" value="{{ $filters['then_dir'] ?? '' }}" disabled :disabled="!params.then_by" :value="params.then_dir || ''">

    <button type="button" x-cloak x-ref="trigger" data-listing-sort-trigger @click="openDialog()"
        aria-haspopup="dialog" aria-controls="{{ $form }}-sort-dialog" :aria-expanded="isOpen"
        class="flex min-h-11 max-w-full items-center gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-left text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
        <svg aria-hidden="true" class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 5h14M5 10h10M7 15h6"/></svg>
        <span class="min-w-0 break-words sm:max-w-56">Rendezés: {{ $summary }}</span>
    </button>

    <dialog id="{{ $form }}-sort-dialog" x-ref="dialog" data-listing-sort-dialog aria-labelledby="{{ $form }}-sort-title" aria-modal="true"
        @cancel.prevent="close()" @close="closed()" @click="backdrop($event)" @keydown.tab="trapFocus($event)"
        class="m-auto overflow-hidden rounded-xl border-0 bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-gray-900/50"
        style="width: calc(100% - 1rem); max-width: 35rem; max-height: calc(100dvh - 1rem);">
        <div data-listing-sort-panel class="flex min-h-0 flex-col" style="max-height: calc(100dvh - 1rem);">
            <header class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-5">
                <h2 id="{{ $form }}-sort-title" class="font-semibold">Rendezés beállítása</h2>
                <button type="button" @click="close()" aria-label="Rendező bezárása"
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded text-xl text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">×</button>
            </header>

            <div data-listing-sort-body class="min-h-0 space-y-4 overflow-y-auto overscroll-contain p-4 sm:p-5">
                <fieldset class="min-w-0">
                    <legend class="text-sm font-semibold text-gray-800">Elsődleges rendezés</legend>
                    <div class="mt-2 grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)]">
                        <div class="min-w-0">
                            <label for="{{ $form }}-sort-by" class="block text-xs text-gray-700">Elsődleges szempont</label>
                            <select x-ref="primary" id="{{ $form }}-sort-by" x-model="primary" @change="primaryChanged()" class="{{ $field }}">
                                @foreach ($sorts as $value => $label)<option value="{{ $value }}" @selected($filters['sort_by'] === $value)>{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div class="min-w-0">
                            <label for="{{ $form }}-sort-dir" class="block text-xs text-gray-700">Elsődleges irány</label>
                            <select id="{{ $form }}-sort-dir" x-model="direction" class="{{ $field }}">
                                <option value="asc" @selected($filters['sort_dir'] === 'asc')>Növekvő ↑</option><option value="desc" @selected($filters['sort_dir'] === 'desc')>Csökkenő ↓</option>
                            </select>
                        </div>
                    </div>
                </fieldset>

                <button type="button" x-ref="addSecondary" x-show="!secondaryEnabled" @click="addSecondary()"
                    class="min-h-11 rounded text-left text-sm font-semibold text-indigo-700 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">+ Másodlagos rendezés hozzáadása</button>
                <template x-if="secondaryEnabled">
                    <fieldset class="min-w-0 border-t border-gray-100 pt-3">
                        <legend class="text-sm font-semibold text-gray-800">Másodlagos rendezés</legend>
                        <p class="mt-1 text-xs text-gray-600">Csak azonos elsődleges érték esetén dönt.</p>
                        <div class="mt-3 grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,0.8fr)]">
                            <div class="min-w-0">
                                <label for="{{ $form }}-then-by" class="block text-xs text-gray-700">Másodlagos szempont</label>
                                <select data-listing-sort-secondary id="{{ $form }}-then-by" x-model="secondary" class="{{ $field }}">
                                    <option value="">Nincs másodlagos rendezés</option>
                                    @foreach ($sorts as $value => $label)<option value="{{ $value }}" :disabled="primary === @js($value)">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div class="min-w-0">
                                <label for="{{ $form }}-then-dir" class="block text-xs text-gray-700">Másodlagos irány</label>
                                <select id="{{ $form }}-then-dir" x-model="secondaryDirection" class="{{ $field }}">
                                    <option value="asc">Növekvő ↑</option><option value="desc">Csökkenő ↓</option>
                                </select>
                            </div>
                        </div>
                        <button type="button" @click="removeSecondary()" class="mt-2 min-h-11 rounded text-left text-sm font-semibold text-gray-600 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Másodlagos rendezés eltávolítása</button>
                    </fieldset>
                </template>
            </div>

            <footer data-listing-sort-footer class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-gray-200 bg-gray-50 p-4 sm:px-5">
                <button type="button" @click="close()" class="min-h-11 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Mégse</button>
                <button type="button" @click="apply()" class="min-h-11 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Rendezés alkalmazása</button>
            </footer>
        </div>
    </dialog>

    <noscript>
        <details data-listing-sort-fallback class="rounded-md border border-gray-300 bg-white p-3">
            <summary class="min-h-8 cursor-pointer font-medium text-gray-700">Rendezés: {{ $summary }}</summary>
            <div class="mt-3 grid min-w-0 gap-3 sm:grid-cols-2">
                @foreach (['sort_by' => 'Elsődleges szempont', 'then_by' => 'Másodlagos szempont'] as $key => $label)
                    <div class="min-w-0">
                        <label for="{{ $form }}-{{ $key }}-fallback" class="block text-xs text-gray-700">{{ $label }}</label>
                        <select id="{{ $form }}-{{ $key }}-fallback" name="{{ $key }}" class="{{ $field }}">
                            @if ($key === 'then_by')<option value="">Nincs másodlagos rendezés</option>@endif
                            @foreach ($sorts as $value => $option)<option value="{{ $value }}" @selected(($filters[$key] ?? '') === $value)>{{ $option }}</option>@endforeach
                        </select>
                    </div>
                    @php($directionKey = $key === 'sort_by' ? 'sort_dir' : 'then_dir')
                    <div class="min-w-0">
                        <label for="{{ $form }}-{{ $directionKey }}-fallback" class="block text-xs text-gray-700">{{ $key === 'sort_by' ? 'Elsődleges irány' : 'Másodlagos irány' }}</label>
                        <select id="{{ $form }}-{{ $directionKey }}-fallback" name="{{ $directionKey }}" class="{{ $field }}">
                            <option value="asc" @selected(($filters[$directionKey] ?? 'asc') === 'asc')>Növekvő ↑</option><option value="desc" @selected(($filters[$directionKey] ?? '') === 'desc')>Csökkenő ↓</option>
                        </select>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-gray-600">A másodlagos rendezés nem kötelező. Azonos szempont esetén csak az elsődleges rendezést alkalmazzuk.</p>
            <button type="submit" class="mt-3 min-h-11 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Rendezés alkalmazása</button>
        </details>
    </noscript>
</div>
