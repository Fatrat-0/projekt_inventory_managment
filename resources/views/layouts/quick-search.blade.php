<div x-data="appSearch({{ \Illuminate\Support\Js::from(['endpoint' => route('app-search.index'), 'results' => $quickSearch['results']]) }})" @keydown.window="shortcut($event)" @pageshow.window="if ($event.persisted) close()">
    <a href="{{ route('app-search.index') }}" @click.prevent="show($event)" aria-label="Globális gyorskereső" title="Globális gyorskereső (Ctrl+K / ⌘K)" class="inline-flex h-11 w-11 shrink-0 items-center justify-center gap-2 rounded-md text-gray-500 hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-indigo-500 lg:w-64 lg:justify-start lg:border lg:border-gray-200 lg:bg-gray-50 lg:px-3">
        <x-icon name="search" class="h-5 w-5" /><span class="hidden text-sm lg:inline">Gyorskereső…</span><kbd class="ml-auto hidden shrink-0 rounded border border-gray-200 bg-white px-1.5 text-xs text-gray-400 lg:inline">Ctrl K</kbd>
    </a>
    <dialog x-ref="dialog" aria-labelledby="app-search-title" class="app-search-dialog shadow-2xl" @close="closed()" @click="backdrop($event)">
        <div class="flex max-h-[85dvh] flex-col bg-white">
            <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4 py-2"><h2 id="app-search-title" class="text-sm font-semibold text-gray-900">Gyorskereső</h2><button type="button" @click="close()" aria-label="Gyorskereső bezárása" class="inline-flex h-11 w-11 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100"><x-icon name="close" /></button></div>
            <form method="GET" action="{{ route('app-search.index') }}" class="shrink-0 p-4">
                <label for="app-search-input" class="sr-only">Oldal vagy termék keresése</label>
                <input id="app-search-input" x-ref="input" x-model="query" @input="edit()" type="search" name="q" maxlength="200" placeholder="Oldal, terméknév vagy cikkszám…" role="combobox" aria-autocomplete="list" aria-controls="app-search-results" :aria-expanded="open" :aria-activedescendant="active >= 0 ? 'app-search-result-' + active : null" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="enter($event)" autocomplete="off" class="w-full min-w-0 rounded-md border-gray-300 text-sm">
            </form>
            <div class="min-h-0 overflow-y-auto" :aria-busy="loading">
                <p x-show="loading" class="px-4 py-3 text-sm text-gray-500">Keresés…</p>
                <div x-show="error" class="space-y-2 px-4 py-3 text-sm text-red-700"><p role="alert" x-text="error"></p><button type="button" @click="search()" class="font-semibold underline">Újrapróbálás</button></div>
                <ul id="app-search-results" x-ref="results" role="listbox" aria-label="Gyorskereső találatai">
                    <template x-for="(result, index) in results" :key="result.url">
                        <li :id="'app-search-result-' + index" role="option" :aria-selected="index === active" @mouseenter="active = index" :class="index === active ? 'bg-indigo-50' : ''">
                            <a :href="result.url" tabindex="-1" class="block px-4 py-3 hover:bg-indigo-50"><span class="text-xs text-gray-500" x-text="result.kind"></span><span class="dashboard-text block text-sm font-semibold text-gray-900" x-text="result.label"></span><span class="dashboard-text block text-xs text-gray-500" x-text="result.detail"></span></a>
                        </li>
                    </template>
                </ul>
                <p x-show="!loading && !error && results.length === 0" class="px-4 py-5 text-sm text-gray-500">Nincs egyező oldal vagy termék.</p>
                <a x-show="moreUrl" :href="moreUrl" class="block px-4 py-3 text-sm font-semibold text-indigo-700">További terméktalálatok →</a>
                <p role="status" aria-live="polite" class="sr-only" x-text="loading ? 'Keresés folyamatban' : results.length + ' találat'"></p>
            </div>
            <footer class="flex shrink-0 flex-wrap items-center justify-between gap-2 border-t border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-500"><span>↑ ↓ választás · Enter megnyitás · Esc bezárás</span><a :href="pageUrl" class="font-medium text-indigo-700 underline">Teljes kereső megnyitása</a></footer>
        </div>
    </dialog>
</div>
