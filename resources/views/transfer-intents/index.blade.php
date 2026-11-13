<x-app-layout>
    @php
        $statuses = \App\Support\TransferIntentDisplay::STATUS;
        $statusStyles = \App\Support\TransferIntentDisplay::STATUS_STYLE;
        $priorities = \App\Support\TransferIntentDisplay::PRIORITY;
        $filtered = collect($filters)->filter()->isNotEmpty();
        $fieldClass = 'mt-1 min-h-11 w-full min-w-0 rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500';
        $desktopColumns = 'xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1.15fr)_minmax(0,0.95fr)_minmax(0,0.85fr)_6.5rem]';
    @endphp
    <div class="app-page space-y-4 py-4" data-transfer-intent-list>
        <x-page-toolbar title="Átmozgatási igények" :count="$intents->total()" unit="igény">
            @if ($canCreate)
                <a href="{{ route('transfer-intent-pages.create') }}" class="inline-flex min-h-11 items-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    <span aria-hidden="true">+</span> Új igény
                </a>
            @endif
        </x-page-toolbar>
        @include('transfers.tabs')

        <form method="GET" action="{{ route('transfer-intent-pages.index') }}" aria-label="Igények szűrése"
            class="rounded-lg border border-gray-200 bg-white p-4"
            x-data="{ loading: false }" @submit="loading = true" @pageshow.window="loading = false" :aria-busy="loading">
            <div class="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))_auto] xl:items-end">
                <div class="min-w-0">
                    <label for="intent-search" class="block text-xs font-medium text-gray-700">Keresés</label>
                    <input type="search" id="intent-search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="200"
                        placeholder="Termék, SKU, igényazonosító…" class="{{ $fieldClass }}">
                </div>
                <div class="min-w-0">
                    <label for="intent-filter-status" class="block text-xs font-medium text-gray-700">Állapot</label>
                    <select id="intent-filter-status" name="status" class="{{ $fieldClass }}">
                        <option value="">Minden állapot</option>
                        @foreach ($statuses as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['status'] ?? null) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-0">
                    <label for="intent-filter-priority" class="block text-xs font-medium text-gray-700">Prioritás</label>
                    <select id="intent-filter-priority" name="priority" class="{{ $fieldClass }}">
                        <option value="">Minden prioritás</option>
                        @foreach ($priorities as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['priority'] ?? null) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-0">
                    <label for="intent-filter-warehouse" class="block text-xs font-medium text-gray-700">Érintett raktár</label>
                    <select id="intent-filter-warehouse" name="warehouse_id" class="{{ $fieldClass }} truncate">
                        <option value="">Minden raktár</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->warehouse_id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $warehouse->warehouse_id)>{{ $warehouse->warehouse_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex min-w-0 flex-wrap items-center gap-3 sm:col-span-2 xl:col-span-1">
                    <button type="submit" :disabled="loading" class="min-h-11 rounded-md bg-indigo-600 px-3 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-60">
                        <span x-show="!loading">Szűrés</span>
                        <span x-cloak x-show="loading" role="status">Betöltés…</span>
                    </button>
                    @if ($filtered || $filterErrors->any())
                        <a href="{{ route('transfer-intent-pages.index') }}" class="text-sm font-medium text-indigo-700 underline-offset-2 hover:underline">Törlés</a>
                    @endif
                </div>
            </div>
            @if ($filterErrors->any())
                <ul role="alert" class="mt-3 list-inside list-disc text-sm text-red-700">
                    @foreach ($filterErrors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            @endif
        </form>

        @if ($intents->isEmpty())
            <div role="status" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm">
                <p class="font-semibold text-gray-900">{{ $filtered ? 'Nincs a szűrésnek megfelelő igény.' : 'Nincs megjeleníthető igény.' }}</p>
                @if ($filtered)
                    <a href="{{ route('transfer-intent-pages.index') }}" class="mt-2 inline-block text-indigo-700 hover:underline">Szűrők törlése</a>
                @elseif ($intents->total() > 0)
                    <a href="{{ $intents->url(1) }}" class="mt-2 inline-block text-indigo-700 hover:underline">Első oldal</a>
                @elseif ($canCreate)
                    <a href="{{ route('transfer-intent-pages.create') }}" class="mt-2 inline-block text-indigo-700 hover:underline">Új igény benyújtása</a>
                @endif
            </div>
        @else
            <div role="table" aria-label="Átmozgatási igények" class="min-w-0 rounded-lg border border-gray-200 bg-white text-sm">
                <div role="row" class="sr-only grid {{ $desktopColumns }} gap-3 rounded-t-lg bg-gray-50 text-xs font-semibold text-gray-600 xl:not-sr-only xl:px-4 xl:py-3">
                    <span role="columnheader">Igény és termék</span>
                    <span role="columnheader">Útvonal</span>
                    <span role="columnheader">Állapot és prioritás</span>
                    <span role="columnheader">Időpontok</span>
                    <span role="columnheader" class="sr-only">Részletek</span>
                </div>
                <div role="rowgroup" class="divide-y divide-gray-200 xl:border-t xl:border-gray-200">
                    @foreach ($intents as $intent)
                        <div role="row" class="grid min-w-0 grid-cols-1 gap-3 px-4 py-4 sm:grid-cols-2 {{ $desktopColumns }} xl:items-start">
                            <div role="cell" class="min-w-0">
                                <p class="break-all font-mono text-xs text-gray-600">{{ $intent->reference }}</p>
                                <p class="mt-0.5 break-words font-semibold text-gray-900">{{ $intent->product->product_name }}</p>
                                <p class="break-words text-xs text-gray-600">{{ $intent->product->sku }} · {{ number_format($intent->quantity, 0, ',', ' ') }} db</p>
                            </div>
                            <div role="cell" class="min-w-0 break-words text-gray-800">
                                <div><span class="text-xs text-gray-600">Innen</span> {{ $intent->fromWarehouse->warehouse_name }}</div>
                                <div><span class="text-xs text-gray-600">Ide</span> {{ $intent->toWarehouse->warehouse_name }}</div>
                            </div>
                            <div role="cell" class="min-w-0">
                                <span class="inline-block rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-inset {{ $statusStyles[$intent->status] }}">{{ $statuses[$intent->status] }}</span>
                                <p class="mt-1 text-xs {{ $intent->priority === 'normal' ? 'text-gray-600' : 'font-semibold text-red-800' }}">{{ $priorities[$intent->priority] }} prioritás</p>
                            </div>
                            <div role="cell" class="min-w-0 text-xs text-gray-600">
                                <div><span class="font-medium">Létrehozva</span> {{ $intent->created_at->timezone('Europe/Budapest')->format('Y.m.d. H:i') }}</div>
                                @if ($intent->requested_arrival_at)
                                    <div class="mt-1 text-gray-800"><span class="font-medium">Határidő</span> {{ $intent->requested_arrival_at->timezone('Europe/Budapest')->format('Y.m.d. H:i') }}</div>
                                @endif
                            </div>
                            <div role="cell">
                                <a href="{{ route('transfer-intent-pages.show', $intent) }}" aria-label="Részletek · {{ $intent->reference }}"
                                    class="inline-flex min-h-11 items-center gap-1 rounded-md px-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Részletek <span aria-hidden="true">→</span></a>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @if ($intents->hasPages())
            <div class="min-w-0">{{ $intents->links('pagination.inventory') }}</div>
        @endif
    </div>
</x-app-layout>
