@props(['items', 'id', 'name', 'value' => '', 'form' => null, 'required' => false, 'placeholder' => 'Keresés…', 'eventName' => 'item-selected', 'clearLabel' => null, 'resultsLabel' => null, 'invalidValueLabel' => null, 'describedBy' => null])
@php
    $selectedValue = is_scalar($value) ? (string)$value : '';
    $selected = collect($items)->first(fn($item) => (string)$item['value'] === $selectedValue);
@endphp
<div class="relative" x-data="autocompletePicker({{ Illuminate\Support\Js::from(['items' => $items, 'value' => $selectedValue, 'required' => (bool)$required, 'eventName' => $eventName, 'invalidValueLabel' => $invalidValueLabel]) }})"
    @click.outside="open = false" @focusout="if (!$el.contains($event.relatedTarget)) open = false">
    <input type="hidden" name="{{ $name }}" :value="value" value="{{ $selectedValue }}" @if($form) form="{{ $form }}" @endif>
    <div class="relative">
        <input id="{{ $id }}" x-ref="input" type="text" role="combobox" aria-autocomplete="list" aria-haspopup="listbox"
            :aria-expanded="open" aria-controls="{{ $id }}-results" :aria-activedescendant="open && active >= 0 ? '{{ $id }}-option-' + active : null"
            x-model="query" value="{{ $selected['label'] ?? '' }}" @input="edit()" @focus="open = true" @click="open = true"
            @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="enter($event)" @keydown.escape.stop.prevent="open = false; active = -1" @keydown.tab="open = false"
            @required($required) @if($form) form="{{ $form }}" @endif autocomplete="off" spellcheck="false" placeholder="{{ $placeholder }}"
            aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}" @if($describedBy || $errors->has($name)) aria-describedby="{{ trim(($describedBy ?? '').' '.($errors->has($name) ? $id.'-error' : '')) }}" @endif
            class="w-full rounded-md pr-10 text-sm focus:border-indigo-500 focus:ring-indigo-500 {{ $errors->has($name) ? 'border-red-500' : 'border-gray-300' }}">
        <button type="button" x-show="query" style="display:none;" @click="clear()" aria-label="{{ $clearLabel ?? ($name === 'product_id' ? 'Termék törlése a mezőből' : 'Raktár törlése a mezőből') }}" class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-gray-400 hover:text-gray-700">×</button>
    </div>
    <div x-show="open" style="display:none;" class="absolute left-0 right-0 top-full z-40 mt-1 rounded-md border border-gray-200 bg-white shadow-lg">
        <ul id="{{ $id }}-results" x-ref="list" role="listbox" aria-label="{{ $resultsLabel ?? ($name === 'product_id' ? 'Terméktalálatok' : 'Raktártalálatok') }}" class="max-h-60 overflow-y-auto py-1">
            <template x-for="(item, index) in results" :key="item.value">
                <li :id="'{{ $id }}-option-' + index" role="option" :aria-selected="index === active" @mouseenter="active = index" @mousedown.prevent @click="choose(item)"
                    :class="index === active ? 'bg-indigo-50 text-indigo-800' : 'text-gray-700'" class="cursor-pointer px-3 py-2.5 text-sm break-words" x-text="item.label"></li>
            </template>
        </ul>
        <p x-show="results.length === 0" class="px-3 py-3 text-sm text-gray-500">Nincs egyező találat.</p>
        <p role="status" aria-live="polite" class="sr-only" x-text="results.length + ' találat'"></p>
    </div>
    @error($name)<p id="{{ $id }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
</div>
