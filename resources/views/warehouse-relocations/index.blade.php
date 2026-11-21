<x-warehouse-layout :$warehouse :context="$moduleContext['context'] ?? ''" :detail-context="$moduleContext['detail_context'] ?? ''">
    @php
        $index = $warehouse ? route('warehouses.relocations.index', $warehouse) : route('warehouse-relocation-pages.index');
        $entryWarehouse = $warehouse ?? $warehouses->firstWhere('warehouse_id', $filters['warehouse_id'] ?? null);
        $new = $entryWarehouse ? route('warehouses.relocations.create', ['warehouse' => $entryWarehouse] + $moduleContext) : route('warehouse-relocation-pages.create');
        $columns = $warehouse ? 'lg:grid-cols-[minmax(0,1.45fr)_minmax(0,1.1fr)_minmax(0,0.55fr)_minmax(0,1fr)]'
            : 'lg:grid-cols-[minmax(0,1.45fr)_minmax(0,0.9fr)_minmax(0,1.1fr)_minmax(0,0.55fr)_minmax(0,1fr)]';
    @endphp
    <div class="app-page space-y-4 py-4 [overflow-wrap:anywhere]">
        <x-page-toolbar title="Áthelyezési előzmények" :description="$warehouse?->warehouse_name" :count="$rows->total()" unit="áthelyezés">
            <a href="{{ $new }}" class="inline-flex min-h-11 items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ Új áthelyezés</a>
        </x-page-toolbar>

        <form id="relocation-filters" x-data method="GET" action="{{ $index }}" aria-label="Áthelyezések szűrése"
              class="grid min-w-0 gap-3 rounded-lg border border-gray-200 bg-white p-3 sm:p-4 {{ $warehouse ? 'sm:grid-cols-[minmax(0,1fr)_auto]' : 'sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]' }}">
            @foreach($moduleContext as $key => $token)<input type="hidden" name="{{ $key }}" value="{{ $token }}">@endforeach
            @if(isset($filters['per_page']))<input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">@endif
            @if(isset($filters['product_id']))<input type="hidden" name="product_id" value="{{ $filters['product_id'] }}">@endif
            <div class="min-w-0">
                <label for="relocation-history-search" class="mb-1 block text-sm font-medium text-gray-800">Keresés</label>
                <x-listing-search :endpoint="$index" :query="$filters['search'] ?? ''" id="relocation-history-search"
                    placeholder="REK-azonosító, termék, SKU vagy rekesz…" input-class="min-h-11" :max-length="100" />
            </div>
            @unless($warehouse)
            <div class="min-w-0">
                <label for="relocation-history-warehouse" class="mb-1 block text-sm font-medium text-gray-800">Raktár</label>
                <select id="relocation-history-warehouse" name="warehouse_id" class="min-h-11 w-full min-w-0 truncate rounded-md border-gray-300 text-sm">
                    <option value="">Minden jogosult raktár</option>
                    @foreach($warehouses as $option)
                        <option value="{{ $option->warehouse_id }}" @selected((string) ($filters['warehouse_id'] ?? '') === (string) $option->warehouse_id)>{{ $option->warehouse_name }}</option>
                    @endforeach
                </select>
            </div>
            @endunless
            <div class="flex flex-wrap items-center gap-2 self-end {{ $warehouse ? '' : 'sm:col-span-2 xl:col-span-1' }}">
                <x-primary-button class="min-h-11">Szűrés</x-primary-button>
                <x-listing-sort :sorts="$sorts" :filters="$filters" form="relocation-filters" />
                @if(filled($filters['search'] ?? null) || (!$warehouse && filled($filters['warehouse_id'] ?? null)) || filled($filters['product_id'] ?? null))
                    <a href="{{ $warehouse ? route('warehouses.relocations.index', ['warehouse' => $warehouse] + $moduleContext) : $index }}" class="inline-flex min-h-11 items-center px-2 text-sm font-medium text-indigo-700 underline">Alaphelyzet</a>
                @endif
            </div>
        </form>

        @if($rows->isEmpty())
            <p class="rounded-lg border border-gray-200 bg-white p-6 text-center text-sm text-gray-700">A megadott feltételekkel nincs megjeleníthető áthelyezés.</p>
        @else
            <div role="table" aria-label="Áthelyezési előzmények">
                <div role="row" class="hidden {{ $columns }} gap-3 rounded-t-lg border border-gray-200 bg-gray-50 p-4 text-xs font-semibold text-gray-700 lg:grid">
                    <span role="columnheader">Azonosító és termék</span>@unless($warehouse)<span role="columnheader">Raktár</span>@endunless<span role="columnheader">Forrás és cél</span>
                    <span role="columnheader">Mennyiség</span><span role="columnheader">Végrehajtó és időpont</span>
                </div>
                <div role="rowgroup" class="grid gap-3 lg:gap-0">
                    @foreach($rows as $row)
                        <div role="row" data-relocation-row class="grid min-w-0 gap-3 rounded-lg border border-gray-200 bg-white p-4 text-sm sm:grid-cols-2 lg:rounded-none lg:border-t-0 {{ $columns }}">
                            <div role="cell" class="min-w-0">
                                <a href="{{ route('warehouses.relocations.show', ['warehouse' => $row->warehouse_id, 'relocation' => $row] + $moduleContext) }}" class="inline-flex min-h-8 max-w-full items-center font-semibold text-indigo-700 underline decoration-indigo-300 underline-offset-2">{{ $row->reference }}</a>
                                <p>{{ $row->product_name }}</p><p class="text-xs text-gray-600">{{ $row->sku }}</p>
                            </div>
                            @unless($warehouse)
                                <div role="cell" class="min-w-0"><span class="block text-xs text-gray-500 lg:sr-only">Raktár</span>{{ $row->warehouse_name }}</div>
                            @endunless
                            <div role="cell" class="min-w-0"><span class="block text-xs text-gray-500 lg:sr-only">Forrás és cél</span>{{ $row->from_location_code }} → {{ $row->to_location_code }}</div>
                            <div role="cell" class="min-w-0 font-semibold tabular-nums"><span class="block text-xs font-normal text-gray-500 lg:sr-only">Mennyiség</span>{{ number_format($row->quantity, 0, ',', ' ') }} db</div>
                            <div role="cell" class="min-w-0"><span class="block text-xs text-gray-500 lg:sr-only">Végrehajtó és időpont</span>{{ $row->actor_name }}
                                <time datetime="{{ $row->relocated_at->toISOString() }}" class="block text-xs text-gray-600">{{ $row->relocated_at->timezone('Europe/Budapest')->format('Y. m. d. H:i') }}</time>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            {{ $rows->links('pagination.inventory') }}
        @endif
    </div>
</x-warehouse-layout>
