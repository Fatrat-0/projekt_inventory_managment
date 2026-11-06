<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-6 sm:px-6" x-data="dashboardCustomization({{ \Illuminate\Support\Js::from(['order' => $layout['order'], 'defaults' => $defaults, 'readOnly' => $conflict]) }})" @pageshow.window="restorePage()" @keydown.escape.window="cancelDrag()" @blur.window="cancelDrag()">
        <header>
            <a href="{{ route('dashboard', $context) }}" class="text-sm font-medium text-indigo-700 hover:underline">← Vezérlőpult</a>
            <h1 id="layout-form-title" class="mt-3 text-2xl font-bold text-gray-900">Vezérlőpult testreszabása</h1>
            <p class="mt-2 text-sm text-gray-600">Válaszd ki a megjelenő blokkokat, majd állítsd be a sorrendjüket. A beállítások a saját fiókodhoz tartoznak.</p>
            <p class="mt-1 text-xs text-gray-500">A listák nagy képernyőn kettesével, mobilon egymás alatt jelennek meg, a megadott sorrendben.</p>
            <p x-cloak x-show="ready && !readOnly" class="mt-2 text-sm text-gray-600">Húzd a blokkokat a sor elején lévő fogantyúval, vagy használd a fel/le nyilakat. A módosításokat a Mentés gomb alkalmazza.</p>
        </header>
        <x-operation-feedback />
        @if($conflict)
            <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">{{ $reloadReason === 'invalid_version' ? 'A mentés verzióadata hiányos vagy hibás.' : 'Az elrendezés közben megváltozott egy másik lapon.' }}</p>
                <p class="mt-1">A módosításaid nem írták felül az újabb beállításokat. A folytatáshoz töltsd be a mentett elrendezést.</p>
                <a href="{{ route('dashboard.customize', $context) }}" class="mt-3 inline-flex rounded-md border border-amber-400 bg-white px-3 py-2 font-semibold">Mentett elrendezés betöltése</a>
            </div>
        @endif
        <form action="{{ route('dashboard.layout.update') }}" method="POST" x-ref="form" @submit="submit($event)" class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            @csrf @method('PATCH')
            <input type="hidden" name="_layout_form" value="1">
            <input type="hidden" name="version" value="{{ $layoutVersion }}">
            @foreach($context as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            <fieldset aria-label="Vezérlőpult blokkbeállításai" class="min-w-0" @disabled($conflict)>
            <ul x-ref="rows" aria-label="Vezérlőpult blokkjainak sorrendje" class="divide-y divide-gray-100">
                @foreach($layout['order'] as $panelId)
                    <li id="layout-{{ $panelId }}" data-layout-row="{{ $panelId }}" data-label="{{ $panels[$panelId]['label'] }}" class="flex scroll-mt-20 flex-wrap items-center gap-2 px-4 py-2 sm:gap-4 sm:px-5">
                        <input type="hidden" name="order[]" value="{{ $panelId }}">
                        <span x-cloak x-show="ready && !readOnly" data-layout-handle aria-hidden="true" title="Átrendezés húzással" class="inline-flex h-11 w-7 shrink-0 touch-none cursor-grab select-none items-center justify-center rounded text-gray-400 hover:bg-indigo-50 hover:text-indigo-700">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.5" /><circle cx="15" cy="5" r="1.5" /><circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" /><circle cx="9" cy="19" r="1.5" /><circle cx="15" cy="19" r="1.5" /></svg>
                        </span>
                        <label class="flex min-h-12 min-w-0 basis-40 grow cursor-pointer items-center gap-3 py-2">
                            <input type="checkbox" name="visible[]" value="{{ $panelId }}" @checked(in_array($panelId, $layout['visible'], true)) aria-label="{{ $panels[$panelId]['label'] }} megjelenítése" class="h-5 w-5 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="dashboard-text min-w-0 text-sm font-semibold text-gray-800"><span class="mr-1 font-normal tabular-nums text-gray-500"><span x-text="order.indexOf('{{ $panelId }}') + 1">{{ $loop->iteration }}</span>.</span>{{ $panels[$panelId]['label'] }}</span>
                        </label>
                        <div class="ml-auto flex shrink-0 gap-1">
                            <button type="submit" :type="'button'" name="move" value="up:{{ $panelId }}" @click.prevent="move('{{ $panelId }}', -1, $event.currentTarget)" @disabled($loop->first) :disabled="submitting || dragging || order.indexOf('{{ $panelId }}') === 0" aria-label="{{ $panels[$panelId]['label'] }} feljebb" title="Feljebb" class="inline-flex h-11 w-10 items-center justify-center rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:cursor-default disabled:opacity-30">↑</button>
                            <button type="submit" :type="'button'" name="move" value="down:{{ $panelId }}" @click.prevent="move('{{ $panelId }}', 1, $event.currentTarget)" @disabled($loop->last) :disabled="submitting || dragging || order.indexOf('{{ $panelId }}') === order.length - 1" aria-label="{{ $panels[$panelId]['label'] }} lejjebb" title="Lejjebb" class="inline-flex h-11 w-10 items-center justify-center rounded-md border border-gray-200 text-gray-600 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 disabled:cursor-default disabled:opacity-30">↓</button>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p x-text="announcement" role="status" aria-live="polite" class="sr-only"></p>
            <noscript><p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-600">A fel/le gombok és az alaphelyzet visszaállítása azonnal mentik az elrendezést.</p></noscript>
            <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 bg-gray-50 p-4 sm:p-5">
                <button type="submit" :type="'button'" name="reset" value="1" @click.prevent="resetDefaults()" :disabled="submitting || dragging" class="min-h-11 rounded-md px-2 text-sm font-medium text-indigo-700 hover:underline disabled:opacity-50">Alapértelmezett elrendezés</button>
                <div class="flex gap-2">
                    <a href="{{ route('dashboard', $context) }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">Mégse</a>
                    <button type="submit" @disabled($conflict) :disabled="submitting || dragging || {{ $conflict ? 'true' : 'false' }}" class="min-h-11 rounded-md bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Mentés</button>
                </div>
            </footer>
            </fieldset>
        </form>
    </div>
</x-app-layout>
