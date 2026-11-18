@props(['items' => [], 'filterName' => null, 'endpoint' => null, 'inputClass' => '', 'maxLength' => 200, 'query' => '', 'id' => 'listing-search', 'label' => 'Keresés', 'placeholder' => 'Keresés…', 'selectionHint' => 'Enter: a kijelölt találat kiválasztása', 'resultsLabel' => 'Kereső javaslatai', 'countLabel' => 'találat', 'emptyHint' => 'Nincs egyező javaslat. A megadott szövegre továbbra is kereshetsz.', 'moreHint' => 'Az első 8 találat látható. Pontosítsd a keresést a további találatokhoz.', 'detailClass' => 'block text-xs text-gray-500', 'clearFilterOnEdit' => false])
<div {{ $attributes->class('relative min-w-0 flex-1') }} @if($endpoint) data-transfer-list-search @endif
    x-data="{{ $endpoint ? 'listingRemoteSearch' : 'listingSearch' }}({{ Illuminate\Support\Js::from(['items' => $items, 'query' => $query, 'filterName' => $filterName, 'endpoint' => $endpoint, 'clearFilterOnEdit' => $clearFilterOnEdit]) }})"
    @click.outside="{{ $endpoint ? 'close()' : 'open = false; active = -1' }}" @focusout="if (!$el.contains($event.relatedTarget)) { {{ $endpoint ? 'close()' : 'open = false; active = -1' }}; }">
    <input id="{{ $id }}" x-ref="input" type="search" name="search" role="combobox" aria-label="{{ $label }}"
        aria-autocomplete="list" aria-haspopup="listbox" aria-controls="{{ $id }}-results" :aria-expanded="open"
        :aria-activedescendant="open && active >= 0 ? '{{ $id }}-option-' + active : null"
        x-model="query" value="{{ $query }}" @input="edit(true)" @focus="edit()" @click="{{ $endpoint ? 'if (!open) edit()' : "open = query.trim() !== ''" }}"
        @if($endpoint)
            @compositionstart="composing = true; invalidate()" @compositionend="composing = false; query = $event.target.value; edit()" :aria-busy="loading"
        @endif
        @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="enter($event)"
        @keydown.escape.stop.prevent="{{ $endpoint ? 'close()' : 'open = false; active = -1' }}" @keydown.tab="{{ $endpoint ? 'close()' : 'open = false; active = -1' }}"
        autocomplete="off" spellcheck="false" maxlength="{{ $maxLength }}" placeholder="{{ $placeholder }}"
        class="w-full rounded-md border-gray-300 text-sm pr-10 focus:border-indigo-500 focus:ring-indigo-500 {{ $inputClass }}">
    <button type="submit" aria-label="Keresés" class="absolute right-3 top-3 text-gray-500"><x-icon name="search" /></button>
    <div x-show="open" style="display:none;" class="absolute left-0 right-0 top-full z-40 mt-1 rounded-md border border-gray-200 bg-white shadow-lg">
        @if($endpoint)
            <p x-show="loading" role="status" class="px-3 py-2 text-sm text-gray-500">Keresés…</p>
            <p x-show="error" x-text="error" role="status" class="px-3 py-2 text-sm text-gray-600"></p>
        @endif
        <ul id="{{ $id }}-results" x-ref="list" role="listbox" aria-label="{{ $resultsLabel }}" class="max-h-60 overflow-y-auto py-1">
            <template x-for="(item, index) in results" :key="item.key || item.value">
                <li :id="'{{ $id }}-option-' + index" role="option" :aria-selected="index === active"
                    @mouseenter="active = index" @mousedown.prevent @click="choose(item)"
                    :class="index === active ? 'bg-indigo-50 text-indigo-800' : 'text-gray-700'" class="cursor-pointer px-3 py-2.5 text-sm break-words">
                    <span class="block font-medium" x-text="item.name"></span>
                    <span class="{{ $detailClass }}" x-text="item.detail"></span>
                </li>
            </template>
        </ul>
        <p x-show="matches.length === 0{{ $endpoint ? ' && !loading && !error' : '' }}" class="px-3 py-2 text-sm text-gray-500">{{ $emptyHint }}</p>
        <p x-show="{{ $endpoint ? 'more && !loading && !error' : 'matches.length > 8' }}" class="px-3 py-2 text-xs text-gray-500">{{ $moreHint }}</p>
        <p class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500" x-text="active >= 0 ? {{ Illuminate\Support\Js::from($selectionHint) }} : 'Enter: szöveges keresés · ↓: találat kiválasztása'"></p>
        <p role="status" aria-live="polite" class="sr-only" x-text="matches.length + ' ' + {{ Illuminate\Support\Js::from($countLabel) }}"></p>
    </div>
</div>
