@php($columns = \App\Support\ListingColumns::forRoute(request()->route()?->getName()))
@if($columns)
    <div x-cloak data-listing-columns-control class="shrink-0" x-init="configureColumns({{ \Illuminate\Support\Js::from($columns) }})">
        <div x-data="listingColumns" class="flex justify-end" @resize.window="if (open) position()" @scroll.window.capture="if (open) position()">
            <button type="button" x-ref="trigger" popovertarget="listing-columns-panel" aria-controls="listing-columns-panel" aria-haspopup="dialog" :aria-expanded="open" aria-label="Táblázat oszlopai" title="Táblázat oszlopai" class="inline-flex h-9 w-9 items-center justify-center rounded-md text-gray-500 hover:bg-gray-200 hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500"><x-icon name="column-settings" class="h-4 w-4" /></button>
            <div id="listing-columns-panel" x-ref="menu" data-listing-columns-panel popover="auto" role="dialog" tabindex="-1" aria-labelledby="listing-columns-title" @toggle="changed($event)" @focusout="leave($event)" @change="refreshPosition()" class="app-action-menu max-h-[calc(100dvh-1rem)] overflow-y-auto rounded-lg border border-gray-200 bg-white p-3 text-left text-sm font-normal normal-case text-gray-700 shadow-xl" style="position: fixed; inset: auto; width: min(20rem, calc(100vw - 1rem));">
                <h2 id="listing-columns-title" class="mb-2 font-semibold text-gray-900">Táblázat oszlopai</h2>
                <div class="space-y-1">
                    @foreach($columns as $column)
                        <label class="flex min-h-10 items-start gap-2 rounded-md px-2 py-2 {{ $column['required'] ? 'text-gray-400' : 'cursor-pointer hover:bg-gray-50' }}">
                            <input type="checkbox" data-column-key="{{ $column['key'] }}" aria-label="{{ $column['label'] }} oszlop megjelenítése" checked :checked="columnVisible('{{ $column['key'] }}')" @change="setColumn('{{ $column['key'] }}', $event.target.checked)" @disabled($column['required']) class="mt-0.5 rounded border-gray-300 text-indigo-600 disabled:opacity-60">
                            <span class="min-w-0 break-words">{{ $column['label'] }}@if($column['required'])<span class="block text-xs font-normal">Mindig látható</span>@endif</span>
                        </label>
                    @endforeach
                </div>
                <p class="mt-3 border-t border-gray-100 pt-3 text-xs text-gray-500">Csak a táblázat megjelenését módosítja. A kártyák, a rendezés és a PDF változatlan marad.</p>
                <button type="button" @click="resetColumns(); refreshPosition()" class="mt-3 min-h-10 w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-50">Alapbeállítások visszaállítása</button>
            </div>
        </div>
    </div>
@endif
