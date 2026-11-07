@props(['id', 'url', 'title', 'buttonLabel' => '', 'options', 'checkboxes', 'description' => '', 'forceWhen' => [], 'inline' => false])
@php($panel = $inline ? 'section' : 'dialog')
<div {{ $attributes->class(['sm:ml-auto' => !$inline]) }} x-data="pdfPreview({{ Illuminate\Support\Js::from(['url' => $url, 'options' => $options, 'forceWhen' => $forceWhen, 'inline' => $inline]) }})">
    @unless($inline)
        <button type="button" x-ref="trigger" @click="show()" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium hover:bg-gray-50">{{ $buttonLabel }}</button>
    @endunless
    <{{ $panel }} aria-labelledby="{{ $id }}-title"
        @if($inline)
            class="overflow-hidden rounded-lg border border-gray-200 bg-white"
            style="height: max(30rem, calc(100dvh - var(--app-topbar-height, 4rem) - 7rem));"
        @else
            x-ref="dialog" @close="closed()" aria-modal="true" class="rounded-lg border-0 p-0 shadow-2xl backdrop:bg-gray-900/60"
            style="width: calc(100% - 1rem); max-width: none; height: calc(100dvh - 1rem); max-height: none;"
        @endif>
        <div class="flex h-full flex-col">
            <header class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-b border-gray-200 bg-white px-3 py-2">
                <h3 id="{{ $id }}-title" class="sr-only text-sm font-semibold text-gray-900 lg:not-sr-only lg:flex-1">{{ $title }}</h3>
                @unless($inline)<button type="button" @click="$refs.dialog.close()" aria-label="Előnézet bezárása" class="order-last rounded-md px-2 py-1 text-xl leading-none text-gray-500 hover:bg-gray-100">×</button>@endunless
                <button type="button" x-cloak x-show.immediate="!settingsOpen" x-ref="settingsExpand" @click="toggleSettings()" aria-expanded="false" aria-controls="{{ $id }}-settings" aria-label="PDF testreszabása" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"><span class="sm:hidden">Beállítások</span><span class="hidden sm:inline">PDF testreszabása</span></button>
                <a :href="previewUrl" href="{{ $url.(str_contains($url, '?') ? '&' : '?').'preview=1' }}" target="_blank" rel="noopener" aria-label="Előnézet külön lapon" title="Előnézet külön lapon – akkor is használható, ha a böngésző nem támogatja a beágyazott PDF-et" class="rounded-md px-2 py-1.5 text-sm text-indigo-600 hover:bg-indigo-50">Külön lapon</a>
                <a :href="downloadUrl" href="{{ $url }}" aria-label="PDF letöltése" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">Letöltés</a>
            </header>
            <div class="flex min-h-0 flex-1 flex-col md:flex-row">
                <aside id="{{ $id }}-settings" x-show.immediate="settingsOpen" aria-labelledby="{{ $id }}-settings-title" class="flex max-h-[38vh] shrink-0 flex-col overflow-hidden border-b border-gray-200 bg-gray-50 md:max-h-none md:w-72 md:border-b-0 md:border-r">
                    <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4 py-1">
                        <h4 id="{{ $id }}-settings-title" class="text-sm font-semibold text-gray-800">PDF testreszabása</h4>
                        <button type="button" x-ref="settingsCollapse" @click="toggleSettings()" aria-expanded="true" aria-controls="{{ $id }}-settings" aria-label="Beállítások elrejtése" title="Beállítások elrejtése" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                            <x-icon name="sidebar-close" class="h-5 w-5 rotate-90 md:rotate-0" />
                        </button>
                    </div>
                        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-4">
                            <div><p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Lap tájolása</p><div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="options.orientation = 'portrait'; updateOptions()" :aria-pressed="options.orientation === 'portrait'" :class="options.orientation === 'portrait' ? 'bg-indigo-600 text-white' : 'border border-gray-300 bg-white text-gray-700'" class="rounded-md px-2 py-2 text-sm font-medium">Álló (A4)</button>
                                <button type="button" @click="options.orientation = 'landscape'; updateOptions()" :aria-pressed="options.orientation === 'landscape'" :class="options.orientation === 'landscape' ? 'bg-indigo-600 text-white' : 'border border-gray-300 bg-white text-gray-700'" class="rounded-md px-2 py-2 text-sm font-medium">Fekvő (A4)</button>
                            </div></div>
                            <fieldset @change="updateOptions()" class="space-y-3"><legend class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500">Megjelenő elemek</legend>
                                @foreach($checkboxes as $key => $label)
                                    <label class="flex items-start gap-2 text-sm text-gray-700"><input type="checkbox" x-model="options.{{ $key }}" @if(isset($forceWhen[$key])) :disabled="options.{{ $forceWhen[$key] }}" @endif class="mt-0.5 rounded border-gray-300 text-indigo-600"><span>{{ $label }}</span></label>
                                @endforeach
                            </fieldset>
                            <button type="button" @click="resetOptions()" class="text-xs font-medium text-indigo-600 underline">Alapbeállítások visszaállítása</button>
                            @if($description !== '')<p class="text-xs leading-relaxed text-gray-500">{{ $description }}</p>@endif
                        </div>
                </aside>
            <div class="flex min-h-0 min-w-0 flex-1 flex-col bg-gray-100">
                <template x-if="open">
                    <iframe :src="frameUrl" title="{{ $title }}" class="min-h-0 w-full flex-1 border-0 bg-white"></iframe>
                </template>
            </div>
            </div>
        </div>
    </{{ $panel }}>
</div>
