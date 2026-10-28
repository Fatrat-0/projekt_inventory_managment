@props(['products', 'query' => ''])
@php
    $items = $products->map(fn($product) => ['value' => (string)$product->product_id, 'name' => $product->product_name, 'sku' => $product->sku])->values();
@endphp
<div class="relative flex-1" x-data="movementSearch({{ Illuminate\Support\Js::from(['items' => $items, 'query' => $query]) }})"
    @click.outside="open = false; active = -1" @focusout="if (!$el.contains($event.relatedTarget)) { open = false; active = -1; }">
    <input id="movement-search" x-ref="input" type="search" name="search" role="combobox" aria-label="Mozgások keresése"
        aria-autocomplete="list" aria-haspopup="listbox" aria-controls="movement-search-results" :aria-expanded="open"
        :aria-activedescendant="open && active >= 0 ? 'movement-search-option-' + active : null"
        x-model="query" value="{{ $query }}" @input="edit()" @focus="edit()" @click="open = query.trim() !== ''"
        @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="enter($event)"
        @keydown.escape.stop.prevent="open = false; active = -1" @keydown.tab="open = false; active = -1"
        autocomplete="off" spellcheck="false" maxlength="200" placeholder="Termék, SKU, hivatkozás vagy MOZ-azonosító…"
        class="w-full rounded-md border-gray-300 text-sm pr-10 focus:border-indigo-500 focus:ring-indigo-500">
    <button type="submit" aria-label="Keresés" class="absolute right-3 top-3 text-gray-500"><x-icon name="search" /></button>
    <div x-show="open" style="display:none;" class="absolute left-0 right-0 top-full z-40 mt-1 rounded-md border border-gray-200 bg-white shadow-lg">
        <ul id="movement-search-results" x-ref="list" role="listbox" aria-label="Kereső termékjavaslatai" class="max-h-60 overflow-y-auto py-1">
            <template x-for="(item, index) in results" :key="item.value">
                <li :id="'movement-search-option-' + index" role="option" :aria-selected="index === active"
                    @mouseenter="active = index" @mousedown.prevent @click="choose(item)"
                    :class="index === active ? 'bg-indigo-50 text-indigo-800' : 'text-gray-700'" class="cursor-pointer px-3 py-2.5 text-sm break-words">
                    <span class="block font-medium" x-text="item.name"></span>
                    <span class="block text-xs font-mono text-gray-500" x-text="item.sku"></span>
                </li>
            </template>
        </ul>
        <p x-show="matches.length === 0" class="px-3 py-2 text-sm text-gray-500">Nincs egyező termékjavaslat. A megadott szövegre továbbra is kereshetsz.</p>
        <p x-show="matches.length > 8" class="px-3 py-2 text-xs text-gray-500">Az első 8 termék látható. Pontosítsd a keresést a további találatokhoz.</p>
        <p class="border-t border-gray-100 px-3 py-2 text-xs text-gray-500" x-text="active >= 0 ? 'Enter: a kijelölt termék mozgásai' : 'Enter: szöveges keresés · ↓: termék kiválasztása'"></p>
        <p role="status" aria-live="polite" class="sr-only" x-text="matches.length + ' termékjavaslat'"></p>
    </div>
</div>
