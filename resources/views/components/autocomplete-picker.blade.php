@props(['items', 'id', 'name', 'value' => '', 'form' => null, 'required' => false, 'placeholder' => 'Keresés…', 'eventName' => 'item-selected', 'clearLabel' => null, 'resultsLabel' => null, 'invalidValueLabel' => null, 'describedBy' => null, 'movementPartner' => false, 'availableValues' => null, 'itemsSource' => null, 'modelValue' => null, 'maxResults' => null, 'errorActive' => null])
@php
    $hasError = $name && $errors->has($name);
    $description = trim(($describedBy ?? '').' '.($hasError ? $id.'-error' : ''));
    $selectedValue = is_scalar($value) ? (string)$value : '';
    $selected = collect($items)->first(fn($item) => (string)$item['value'] === $selectedValue);
@endphp
<div class="relative" x-data="{{ $movementPartner ? 'movementPartnerPicker' : 'autocompletePicker' }}({{ Illuminate\Support\Js::from(['items' => $items, 'value' => $selectedValue, 'required' => (bool)$required, 'eventName' => $eventName, 'invalidValueLabel' => $invalidValueLabel, 'maxResults' => $maxResults]) }})"
    @if($itemsSource || $modelValue)
        x-init="
            @if($itemsSource) items = {{ $itemsSource }}; $watch('{{ $itemsSource }}', next => { items = next; active = -1; }); @endif
            @if($modelValue) syncValue({{ $modelValue }}); $watch('{{ $modelValue }}', next => syncValue(next)); @endif
        "
    @endif
    @if($availableValues) x-effect="setAvailable({{ $availableValues }})" @endif
    @click.outside="open = false" @focusout="if (!$el.contains($event.relatedTarget)) open = false">
    @if($name)<input type="hidden" name="{{ $name }}" :value="value" value="{{ $selectedValue }}" @if($form) form="{{ $form }}" @endif>@endif
    <div class="relative">
        <input id="{{ $id }}" x-ref="input" type="text" role="combobox" aria-autocomplete="list" aria-haspopup="listbox"
            :aria-expanded="open" aria-controls="{{ $id }}-results" :aria-activedescendant="open && active >= 0 ? '{{ $id }}-option-' + active : null"
            x-model="query" value="{{ $selected['label'] ?? '' }}" @input="edit()" @focus="open = true" @click="open = true"
            @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="enter($event)" @keydown.escape.stop.prevent="open = false; active = -1" @keydown.tab="open = false"
            @required($required) @if($form) form="{{ $form }}" @endif autocomplete="off" spellcheck="false" placeholder="{{ $placeholder }}"
            @if($hasError && $errorActive)
                :aria-invalid="String({{ $errorActive }})" :aria-describedby="{{ $errorActive }} ? @js($description) : @js($describedBy)"
                :class="{{ $errorActive }} ? 'border-red-500' : 'border-gray-300'"
            @else
                aria-invalid="{{ $hasError ? 'true' : 'false' }}" @if($description) aria-describedby="{{ $description }}" @endif
            @endif
            class="w-full rounded-md pr-10 text-sm focus:border-indigo-500 focus:ring-indigo-500 {{ $hasError && $errorActive ? '' : ($hasError ? 'border-red-500' : 'border-gray-300') }}">
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
        @if($maxResults)<p x-show="results.length === maxResults" class="px-3 py-2 text-xs text-gray-600">Az első {{ $maxResults }} találat. Szűkítsd a keresést a további helyekhez.</p>@endif
        <p role="status" aria-live="polite" class="sr-only" x-text="results.length + ' találat'"></p>
    </div>
    @if($hasError)<p id="{{ $id }}-error" @if($errorActive) x-show="{{ $errorActive }}" @endif class="mt-1 text-sm text-red-700">{{ $errors->first($name) }}</p>@endif
</div>
