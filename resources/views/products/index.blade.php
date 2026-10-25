<x-app-layout>
    <!-- Közös Alpine.js állapot a Nézetváltónak (Lista/Kanban) és az Oszlopválasztónak -->
    <div x-data="productListing" @keydown.escape.window="filtersOpen = false; colMenuOpen = false">

        <!-- FEJLÉC SÁV (Odoo Control Panel) -->
        <div class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 space-y-3">
                @php
                    $activeFilterCount = collect([$categoryId, $warehouseId, $stockStatus])->filter()->count();
                    $selectedWarehouseObj = $warehouseId ? $warehouses->firstWhere('warehouse_id', $warehouseId) : null;
                @endphp

                <!-- 1. FŐ SÁV: Cím + Kereső + Szűrők + Akciógombok + Nézetváltó -->
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">

                    <!-- Bal oldal: Oldalcím és találatszám -->
                    <div class="flex items-center gap-3">
                        <h2 class="font-bold text-xl text-gray-800 leading-tight">
                            <span class="inline-flex items-center gap-2"><x-icon name="box" class="w-5 h-5 text-indigo-600" /> Termékek</span>
                        </h2>
                        <span class="bg-gray-100 text-gray-700 text-xs font-bold px-2.5 py-1 rounded-full border border-gray-300">
                            {{ $products->total() }} tétel
                        </span>
                    </div>

                    <!-- Közép: Odoo-stílusú Keresőmező + Szűrők lenyitó gomb -->
                    <div class="flex-1 max-w-2xl">
                        <form id="product-filters" action="{{ route('products.index') }}" method="GET">


                            <div class="flex items-center gap-2">
                                <div class="relative flex-1 flex items-center" x-data="{ searchHelpOpen: false }"
                                    @mouseenter="searchHelpOpen = true" @mouseleave="searchHelpOpen = false"
                                    @focusin="searchHelpOpen = true" @focusout="searchHelpOpen = false"
                                    @keydown.escape.stop="searchHelpOpen = false" @input="searchHelpOpen = false">
                                    <input type="search" aria-label="Termékek keresése" aria-describedby="product-search-help" maxlength="200" name="search" value="{{ $search ?? '' }}"
                                        placeholder="Termék keresése…"
                                        class="w-full pl-3 pr-10 py-2 border-gray-300 rounded-md shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <div id="product-search-help" role="tooltip" x-show="searchHelpOpen" style="display: none;"
                                        class="absolute left-0 top-full z-30 w-full rounded-lg bg-gray-800 px-3 py-2 text-xs leading-relaxed text-white shadow-lg">
                                        Több szóval is kereshetsz név, SKU vagy kategória alapján. Minden szónak egyeznie kell.
                                    </div>
                                    <button type="submit" class="absolute right-2 text-gray-400 hover:text-indigo-600 transition" title="Keresés indítása">
                                        <x-icon name="search" />
                                    </button>
                                </div>

                                <button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" aria-controls="product-filter-panel"
                                    :class="(filtersOpen || {{ $activeFilterCount }} > 0) ? 'bg-indigo-50 border-indigo-300 text-indigo-700' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'"
                                    class="inline-flex items-center gap-2 px-3.5 py-2 border rounded-md text-sm font-semibold shadow-sm transition whitespace-nowrap">
                                    <x-icon name="filter" /><span>Szűrők</span>
                                    @if($activeFilterCount > 0)
                                        <span class="bg-indigo-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full">
                                            {{ $activeFilterCount }}
                                        </span>
                                    @endif
                                    <span x-text="filtersOpen ? '▲' : '▼'" class="text-xs"></span>
                                </button>
                            </div>

                            <!-- 2. LENYITHATÓ SZŰRŐPANEL -->
                            <div id="product-filter-panel" x-show="filtersOpen" x-transition style="display: none;" class="mt-3 pt-3 border-t border-gray-200 bg-gray-50 p-3.5 rounded-md grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Kategória</label>
                                    <select name="category_id" title="A kiválasztott kategória összes alkategóriájában is keres." aria-describedby="product-category-help" onchange="this.form.submit()" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                                        <option value="">Összes kategória</option>
                                        @foreach($categoryOptions as $option)
                                            <option value="{{ $option['id'] }}" @selected((string)$categoryId === (string)$option['id'])>
                                                {{ str_repeat('— ', $option['depth']) }}{{ $option['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <span id="product-category-help" class="sr-only">A kiválasztott kategória összes alkategóriájában is keres.</span>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Készleten itt</label>
                                    <select name="warehouse_id" onchange="this.form.submit()" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                                        <option value="">Minden raktár</option>
                                        @foreach($warehouses as $wh)
                                            <option value="{{ $wh->warehouse_id }}" {{ (string)$warehouseId === (string)$wh->warehouse_id ? 'selected' : '' }}>
                                                {{ $wh->warehouse_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-600 uppercase mb-1">Összkészlet állapota</label>
                                    <select name="stock_status" onchange="this.form.submit()" class="w-full border-gray-300 rounded-md shadow-sm text-sm">
                                        <option value="">Minden állapot</option>
                                        <option value="low" {{ $stockStatus === 'low' ? 'selected' : '' }}>Minimum alatt</option>
                                        <option value="out" {{ $stockStatus === 'out' ? 'selected' : '' }}>Kifogyott (0 db)</option>
                                        <option value="ok" {{ $stockStatus === 'ok' ? 'selected' : '' }}>Megfelelő készlet</option>
                                    </select>
                                </div>

                            </div>
                        </form>
                    </div>

                    <!-- Jobb oldal: Új termék + PDF Leltárív + Odoo Nézetváltó (Lista / Kanban) -->
                    <div class="flex items-center gap-2">
                        <a href="{{ route('products.create') }}" class="bg-indigo-600 text-white font-semibold text-sm px-4 py-2 rounded-md hover:bg-indigo-700 shadow-sm transition whitespace-nowrap">
                            + Új termék
                        </a>

                        <!-- PDF Gomb és Élő Előnézet Modal -->
                        <div x-data="{
                            open: false,
                            orientation: 'portrait',
                            showFilters: true,
                            showSku: true,
                            showCategory: true,
                            showWarehouses: true,
                            showPrices: false,
                            showCountCol: true,
                            blindMode: false,
                            showSignatures: true,
                            baseUrl: {{ Illuminate\Support\Js::from(route('export.inventory', array_merge(request()->except('page'), ['sort_by' => $sortBy, 'sort_dir' => $sortDir]))) }},
                            get previewUrl() {
                                const url = new URL(this.baseUrl);
                                const setParam = (key, val) => url.searchParams.set(key, val ? '1' : '0');

                                url.searchParams.set('orientation', this.orientation);
                                setParam('show_filters', this.showFilters);
                                setParam('show_sku', this.showSku);
                                setParam('show_category', this.showCategory);
                                setParam('show_warehouses', this.showWarehouses);
                                setParam('show_prices', this.showPrices);
                                setParam('show_count_col', this.showCountCol);
                                setParam('blind_mode', this.blindMode);
                                setParam('show_signatures', this.showSignatures);
                                url.searchParams.set('preview', '1');
                                return url.toString();
                            },
                            get downloadUrl() {
                                const url = new URL(this.previewUrl);
                                url.searchParams.delete('preview');
                                return url.toString();
                            }
                        }" class="inline-block">

                            <button @click="open = true" type="button" class="bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 text-sm font-semibold py-2 px-3.5 rounded-md shadow-sm inline-flex items-center gap-1.5 transition whitespace-nowrap">
                                <x-icon name="document" /> PDF-leltárív
                            </button>

                            <div x-show="open" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900 bg-opacity-60 flex items-center justify-center p-4">
                                <div @click.away="open = false" class="bg-white rounded-lg shadow-2xl w-full max-w-6xl overflow-hidden flex flex-col md:flex-row" style="height: 88vh;">
                                    <div class="w-full md:w-1/3 bg-gray-50 p-5 border-r border-gray-200 flex flex-col justify-between overflow-y-auto">
                                        <div class="space-y-4">
                                            <div class="flex justify-between items-center border-b pb-2">
                                                <h3 class="text-lg font-bold text-gray-800">PDF Testreszabása</h3>
                                                <button @click="open = false" class="text-gray-400 hover:text-gray-600 font-bold text-xl">&times;</button>
                                            </div>
                                            <div>
                                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Lap tájolása</p>
                                                <div class="grid grid-cols-2 gap-2">
                                                    <button type="button" @click="orientation = 'portrait'" :class="orientation === 'portrait' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 border'" class="py-1.5 px-3 rounded text-sm font-medium transition shadow-sm">▯ Álló (A4)</button>
                                                    <button type="button" @click="orientation = 'landscape'" :class="orientation === 'landscape' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 border'" class="py-1.5 px-3 rounded text-sm font-medium transition shadow-sm">▭ Fekvő (A4)</button>
                                                </div>
                                            </div>
                                            <div>
                                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Megjelenő oszlopok</p>
                                                <div class="space-y-1.5 bg-white p-3 rounded border border-gray-200">
                                                    <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" x-model="showSku" class="rounded border-gray-300 text-indigo-600"><span>Cikkszám (SKU)</span></label>
                                                    <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" x-model="showCategory" class="rounded border-gray-300 text-indigo-600"><span>Kategória</span></label>
                                                    <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" x-model="showWarehouses" class="rounded border-gray-300 text-indigo-600"><span>Raktári eloszlás részletezése</span></label>
                                                    <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer" :class="blindMode ? 'opacity-50' : ''"><input type="checkbox" x-model="showPrices" :disabled="blindMode" class="rounded border-gray-300 text-indigo-600"><span>Árak és készletérték (Ft)</span></label>
                                                </div>
                                            </div>
                                            <div>
                                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Leltározási mód</p>
                                                <div class="space-y-2 bg-white p-3 rounded border border-gray-200">
                                                    <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" x-model="showCountCol" class="rounded border-gray-300 text-indigo-600"><span>„Számolt mennyiség” üres oszlop</span></label>
                                                    <label class="flex items-center space-x-2 text-sm text-amber-800 bg-amber-50 p-1.5 rounded border border-amber-200 cursor-pointer"><input type="checkbox" x-model="blindMode" class="rounded border-amber-400 text-amber-600"><span class="font-semibold">Vakleltár (Mennyiségek elrejtése)</span></label>
                                                </div>
                                            </div>
                                            <div>
                                                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Dokumentum elemek</p>
                                                <div class="space-y-1.5 bg-white p-3 rounded border border-gray-200">
                                                    <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" x-model="showFilters" class="rounded border-gray-300 text-indigo-600"><span>Aktív szűrők fejlécben</span></label>
                                                    <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer"><input type="checkbox" x-model="showSignatures" class="rounded border-gray-300 text-indigo-600"><span>Aláírási mezők a lap alján</span></label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="space-y-2 pt-4 border-t mt-4">
                                            <a :href="downloadUrl" @click="open = false" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2.5 px-4 rounded shadow transition flex items-center justify-center gap-2">PDF Letöltése</a>
                                            <button @click="open = false" type="button" class="w-full bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-1.5 px-4 rounded text-sm transition">Bezárás</button>
                                        </div>
                                    </div>
                                    <div class="w-full md:w-2/3 bg-gray-200 p-2 flex flex-col">
                                        <template x-if="open">
                                            <iframe :src="previewUrl" class="w-full h-full rounded border border-gray-300 bg-white shadow-inner"></iframe>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ODOO NÉZETVÁLTÓ GOMBOK (Lista vs. Kanban) -->
                        <div class="inline-flex rounded-md shadow-sm border border-gray-300 bg-white overflow-hidden">
                            <button type="button" @click="setView('list')"
                                :class="viewMode === 'list' ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-100'"
                                class="px-3 py-2 text-sm font-bold transition" title="Táblázatos nézet" aria-label="Táblázatos nézet" :aria-pressed="viewMode === 'list'">
                                <x-icon name="list" />
                            </button>
                            <button type="button" @click="setView('kanban')"
                                :class="viewMode === 'kanban' ? 'bg-gray-800 text-white' : 'text-gray-600 hover:bg-gray-100'"
                                class="px-3 py-2 text-sm font-bold border-l border-gray-300 transition" title="Kártyás nézet" aria-label="Kártyás nézet" :aria-pressed="viewMode === 'kanban'">
                                <x-icon name="grid" />
                            </button>
                        </div>
                    </div>
                </div>

                <div x-show="viewMode === 'kanban'" style="display: none;" class="grid grid-cols-2 sm:flex sm:flex-wrap items-center gap-2 border-t border-gray-100 pt-3 text-sm">
                    <label for="product-sort" class="col-span-2 sm:col-span-1 text-gray-500">Rendezés</label>
                    <select id="product-sort" name="sort_by" form="product-filters" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm">
                        @foreach($sortOptions as $key => $label)
                            <option value="{{ $key }}" @selected($sortBy === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <select name="sort_dir" form="product-filters" aria-label="Rendezés iránya" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm">
                        <option value="asc" @selected($sortDir === 'asc')>Növekvő ↑</option>
                        <option value="desc" @selected($sortDir === 'desc')>Csökkenő ↓</option>
                    </select>
                </div>
                @if($errors->any())
                    <div role="alert" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                        Ellenőrizd a megadott szűrőket. <a class="underline" href="{{ route('products.index') }}">Szűrők visszaállítása</a>
                        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <!-- 3. ODOO-STÍLUSÚ AKTÍV SZŰRŐ CÍMKÉK (Search Facets) -->
                @if($search || $categoryId || $warehouseId || $stockStatus)
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-gray-100 text-xs">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-gray-400 font-semibold mr-1">Aktív szűrők:</span>

                            @if($search)
                                <span class="inline-flex items-center gap-1.5 bg-gray-100 text-gray-800 px-2.5 py-1 rounded-md border border-gray-300 font-medium">
                                    🔍 "{{ $search }}"
                                    <a href="{{ request()->fullUrlWithQuery(['page' => null, 'search' => null]) }}" class="text-gray-500 hover:text-red-600 font-bold">✕</a>
                                </span>
                            @endif

                            @if($selectedCategoryObj)
                                <span class="inline-flex items-center gap-1.5 bg-indigo-50 text-indigo-800 px-2.5 py-1 rounded-md border border-indigo-200 font-medium">
                                    {{ $selectedCategoryObj->category_name }}
                                    <a href="{{ request()->fullUrlWithQuery(['page' => null, 'category_id' => null]) }}" class="text-indigo-500 hover:text-red-600 font-bold">✕</a>
                                </span>
                            @endif

                            @if($selectedWarehouseObj)
                                <span class="inline-flex items-center gap-1.5 bg-blue-50 text-blue-800 px-2.5 py-1 rounded-md border border-blue-200 font-medium">
                                    {{ $selectedWarehouseObj->warehouse_name }}
                                    <a href="{{ request()->fullUrlWithQuery(['page' => null, 'warehouse_id' => null]) }}" class="text-blue-500 hover:text-red-600 font-bold">✕</a>
                                </span>
                            @endif

                            @if($stockStatus)
                                <span class="inline-flex items-center gap-1.5 bg-amber-50 text-amber-800 px-2.5 py-1 rounded-md border border-amber-200 font-medium">
                                    {{ $stockStatus === 'low' ? 'Kritikus hiány' : ($stockStatus === 'out' ? 'Kifogyott (0 db)' : 'Megfelelő készlet') }}
                                    <a href="{{ request()->fullUrlWithQuery(['page' => null, 'stock_status' => null]) }}" class="text-amber-600 hover:text-red-600 font-bold">✕</a>
                                </span>
                            @endif
                        </div>

                        <a href="{{ route('products.index') }}" class="text-red-600 hover:text-red-800 font-semibold hover:underline">
                            Összes visszaállítása
                        </a>
                    </div>
                @endif
            </div>
        </div>

        <!-- TARTALMI RÉSZ -->
        <div class="py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

                @php
                    $pageTotalQty = 0;
                    $pageTotalValue = 0;
                @endphp

                <!-- A) TÁBLÁZATOS (LISTA) NÉZET -->
                <div x-show="viewMode === 'list'" class="bg-white shadow-sm sm:rounded-lg border border-gray-200">
                    <div class="relative overflow-x-auto sm:rounded-lg">
                        <table class="w-full text-sm text-left text-gray-600">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-100 border-b border-gray-200">
                                <tr>
                                    <th x-show="cols.sku" scope="col" class="px-5 py-3.5">
                                        <a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => 'sku', 'sort_dir' => ($sortBy == 'sku' && $sortDir == 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-gray-900">
                                            Cikkszám
                                            <span class="{{ $sortBy == 'sku' ? 'text-blue-600 font-bold' : 'text-gray-400' }}">{{ $sortBy == 'sku' ? ($sortDir == 'asc' ? '↑' : '↓') : '↕' }}</span>
                                        </a>
                                    </th>

                                    <th scope="col" class="px-5 py-3.5">
                                        <a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => 'product_name', 'sort_dir' => ($sortBy == 'product_name' && $sortDir == 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-gray-900">
                                            Terméknév
                                            <span class="{{ $sortBy == 'product_name' ? 'text-blue-600 font-bold' : 'text-gray-400' }}">{{ $sortBy == 'product_name' ? ($sortDir == 'asc' ? '↑' : '↓') : '↕' }}</span>
                                        </a>
                                    </th>

                                    <th x-show="cols.category" scope="col" class="px-5 py-3.5">
                                        <a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => 'category_name', 'sort_dir' => ($sortBy == 'category_name' && $sortDir == 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-gray-900">
                                            Kategória
                                            <span class="{{ $sortBy == 'category_name' ? 'text-blue-600 font-bold' : 'text-gray-400' }}">{{ $sortBy == 'category_name' ? ($sortDir == 'asc' ? '↑' : '↓') : '↕' }}</span>
                                        </a>
                                    </th>

                                    <th x-show="cols.stock" scope="col" class="px-5 py-3.5 relative"
                                        x-data="{ helpOpen: false, helpPinned: false }"
                                        @mouseenter="helpOpen = true" @mouseleave="if (!helpPinned) helpOpen = false"
                                        @focusin="if ($event.target.matches(':focus-visible')) helpOpen = true"
                                        @focusout="if (!$el.contains($event.relatedTarget)) { helpOpen = false; helpPinned = false; }"
                                        @click.outside="helpOpen = false; helpPinned = false"
                                        @keydown.escape.stop="helpOpen = false; helpPinned = false">
                                        <div class="flex items-center gap-1.5">
                                            <a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => 'total_stock', 'sort_dir' => ($sortBy == 'total_stock' && $sortDir == 'asc') ? 'desc' : 'asc']) }}"
                                                aria-describedby="total-stock-help" class="flex items-center gap-1 hover:text-gray-900">
                                                Összkészlet
                                                <span class="{{ $sortBy == 'total_stock' ? 'text-blue-600 font-bold' : 'text-gray-400' }}">{{ $sortBy == 'total_stock' ? ($sortDir == 'asc' ? '↑' : '↓') : '↕' }}</span>
                                            </a>
                                            <button type="button" @click.stop="helpPinned = !helpPinned; helpOpen = helpPinned"
                                                :aria-expanded="helpOpen" aria-controls="total-stock-help" aria-label="Az összkészlet értelmezése"
                                                aria-describedby="total-stock-help" class="inline-flex items-center justify-center w-5 h-5 rounded-full border border-gray-300 text-gray-500 normal-case hover:text-indigo-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">i</button>
                                        </div>
                                        <div id="total-stock-help" role="tooltip" x-show="helpOpen" style="display: none;"
                                            class="absolute left-0 top-full z-30 w-64 rounded-lg bg-gray-800 px-3 py-2 text-xs font-normal normal-case leading-relaxed text-white shadow-lg">
                                            A készlet és a minimum minden raktárra összesítve értendő.
                                        </div>
                                    </th>

                                    <th x-show="cols.price" scope="col" class="px-5 py-3.5 text-right">
                                        <a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => 'price', 'sort_dir' => ($sortBy == 'price' && $sortDir == 'asc') ? 'desc' : 'asc']) }}" class="flex items-center justify-end gap-1 hover:text-gray-900">
                                            Egységár
                                            <span class="{{ $sortBy == 'price' ? 'text-blue-600 font-bold' : 'text-gray-400' }}">{{ $sortBy == 'price' ? ($sortDir == 'asc' ? '↑' : '↓') : '↕' }}</span>
                                        </a>
                                    </th>

                                    <th x-show="cols.value" scope="col" class="px-5 py-3.5 text-right">
                                        <a href="{{ request()->fullUrlWithQuery(['page' => null, 'sort_by' => 'stock_value', 'sort_dir' => ($sortBy === 'stock_value' && $sortDir === 'asc') ? 'desc' : 'asc']) }}" class="inline-flex items-center gap-1 hover:text-gray-900">
                                            Készletérték <span class="{{ $sortBy === 'stock_value' ? 'text-indigo-600' : 'text-gray-400' }}">{{ $sortBy === 'stock_value' ? ($sortDir === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                        </a>
                                    </th>

                                    <!-- Műveletek + Odoo Oszlopválasztó Gomb (⚙️) -->
                                    <th scope="col" class="px-5 py-3.5 text-right relative">
                                        <div class="inline-flex items-center justify-end gap-2">
                                            <span>Műveletek</span>
                                            <button type="button" @click="colMenuOpen = !colMenuOpen" class="p-1 rounded hover:bg-gray-200 text-gray-600 transition" title="Oszlopok testreszabása" aria-label="Látható oszlopok" :aria-expanded="colMenuOpen">
                                                <x-icon name="columns" />
                                            </button>
                                        </div>

                                        <!-- Lenyíló Oszlopválasztó Menü -->
                                        <div x-show="colMenuOpen" @click.away="colMenuOpen = false" style="display: none;"
                                            class="absolute right-4 top-11 z-30 w-52 bg-white rounded-md shadow-lg border border-gray-200 p-3 text-left normal-case font-normal space-y-2">
                                            <div class="text-xs font-bold text-gray-400 uppercase border-b pb-1">Látható oszlopok</div>
                                            <label class="flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                                                <input type="checkbox" x-model="cols.sku" class="rounded border-gray-300 text-indigo-600"> Cikkszám (SKU)
                                            </label>
                                            <label class="flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                                                <input type="checkbox" x-model="cols.category" class="rounded border-gray-300 text-indigo-600"> Kategória
                                            </label>
                                            <label class="flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                                                <input type="checkbox" x-model="cols.stock" class="rounded border-gray-300 text-indigo-600"> Készlet és Minimum
                                            </label>
                                            <label class="flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                                                <input type="checkbox" x-model="cols.price" class="rounded border-gray-300 text-indigo-600"> Egységár
                                            </label>
                                            <label class="flex items-center gap-2 text-xs text-gray-700 cursor-pointer">
                                                <input type="checkbox" x-model="cols.value" class="rounded border-gray-300 text-indigo-600"> Készletérték (Összesen)
                                            </label>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($products as $product)
                                    @php
                                        $totalStock = (int) ($product->total_stock ?? 0);
                                        $effectiveMin = $product->min_stock_override ?? ($product->category->default_item_min_stock ?? 0);
                                        $isLow = $totalStock < $effectiveMin;
                                        $stockWarning = $isLow || $totalStock === 0;
                                        $stockValue = $totalStock * $product->price;

                                        $pageTotalQty += $totalStock;
                                        $pageTotalValue += $stockValue;
                                    @endphp
                                    <tr class="bg-white border-b hover:bg-indigo-50/40 transition">
                                        <td x-show="cols.sku" class="px-5 py-3.5 font-mono font-normal text-gray-600 whitespace-nowrap">
                                            {{ $product->sku }}
                                        </td>
                                        <td class="px-5 py-3.5 font-semibold text-gray-900">
                                            {{ $product->product_name }}
                                        </td>
                                        <td x-show="cols.category" class="px-5 py-3.5">
                                            @if($product->category && $product->category->parent)
                                                <span class="text-xs text-gray-400 block">{{ $product->category->parent->category_name }} ➔</span>
                                            @endif
                                            <span class="text-gray-700 font-medium">{{ optional($product->category)->category_name ?? '-' }}</span>
                                        </td>

                                        <!-- KATTINTHATÓ RAKTÁRI KÉSZLETBONTÁS (Odoo Popover) -->
                                        <td x-show="cols.stock" class="px-5 py-3.5 whitespace-nowrap relative" x-data="{ stockOpen: false }">
                                            <button type="button" @click="stockOpen = !stockOpen"
                                                class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 rounded border transition shadow-sm {{ $stockWarning ? 'bg-red-100 text-red-800 border-red-300 hover:bg-red-200' : 'bg-green-50 text-green-800 border-green-200 hover:bg-green-100' }}"
                                                title="Kattints a raktárankénti eloszlás megtekintéséhez!">
                                                <span>{{ $totalStock === 0 ? 'Kifogyott ·' : ($isLow ? 'Hiány ·' : '') }} {{ $totalStock }} db</span>
                                                <span class="font-normal opacity-75">(Min: {{ $effectiveMin }})</span>
                                                <span class="text-[10px] opacity-60">▼</span>
                                            </button>

                                            <!-- Felugró Raktári Eloszlás Kártya -->
                                            <div x-show="stockOpen" @click.away="stockOpen = false" x-transition style="display: none;"
                                                class="absolute left-5 top-12 z-20 w-64 bg-white rounded-lg shadow-xl border border-gray-200 p-3 text-xs">
                                                <div class="font-bold text-gray-800 border-b pb-1.5 mb-2 flex justify-between items-center">
                                                    <span>Raktári eloszlás</span>
                                                    <span class="text-[11px] font-normal text-gray-500">
                                                        {{ !is_null($product->min_stock_override) ? 'Egyedi min.' : 'Kategória min.' }}
                                                    </span>
                                                </div>
                                                <div class="space-y-1.5">
                                                    @forelse($product->stocks as $st)
                                                        <div class="flex justify-between items-center text-gray-700">
                                                            <span class="truncate pr-2">{{ optional($st->warehouse)->warehouse_name ?? '-' }}:</span>
                                                            <span class="font-bold text-gray-900">{{ $st->quantity }} db</span>
                                                        </div>
                                                    @empty
                                                        <div class="text-gray-400 italic">Egyik raktárban sincs készleten.</div>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </td>

                                        <td x-show="cols.price" class="px-5 py-3.5 whitespace-nowrap text-right font-medium text-gray-700">
                                            {{ number_format($product->price, 0, ',', ' ') }} Ft
                                        </td>

                                        <td x-show="cols.value" class="px-5 py-3.5 whitespace-nowrap text-right font-semibold text-gray-900">
                                            {{ number_format($stockValue, 0, ',', ' ') }} Ft
                                        </td>

                                        <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center gap-3">
                                                <a href="{{ route('products.edit', $product) }}" class="font-semibold text-blue-600 hover:underline">Szerkesztés</a>
                                                <form action="{{ route('products.destroy', $product) }}" method="POST" class="inline" onsubmit="return confirm('Biztosan törölni szeretnéd ezt a terméket?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="font-semibold text-red-600 hover:underline">Törlés</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-6 py-10 text-center text-gray-500">
                                            Nincs a szűrési feltételeknek megfelelő termék.
                                            <a href="{{ route('products.index') }}" class="block mt-2 text-indigo-600 font-semibold hover:underline">Összes termék megjelenítése</a>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>

                            <!-- ODOO-STÍLUSÚ ÖSSZESÍTŐ LÁBLÉC (TFOOT) -->
                            @if($products->isNotEmpty())
                                <tfoot class="bg-gray-50 border-t-2 border-gray-200 text-xs font-bold text-gray-800">
                                    <tr>
                                        <td x-show="cols.sku" class="px-5 py-3">ÖSSZESEN (Oldalon):</td>
                                        <td class="px-5 py-3">{{ $products->count() }} tétel</td>
                                        <td x-show="cols.category" class="px-5 py-3"></td>
                                        <td x-show="cols.stock" class="px-5 py-3 text-indigo-700">{{ number_format($pageTotalQty, 0, ',', ' ') }} db</td>
                                        <td x-show="cols.price" class="px-5 py-3"></td>
                                        <td x-show="cols.value" class="px-5 py-3 text-right text-indigo-700">{{ number_format($pageTotalValue, 0, ',', ' ') }} Ft</td>
                                        <td class="px-5 py-3"></td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>

                <!-- B) KANBAN (KÁRTYÁS) NÉZET (Odoo Kanban View) -->
                <div x-show="viewMode === 'kanban'" style="display: none;" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @forelse($products as $product)
                        @php
                            $totalStock = (int) ($product->total_stock ?? 0);
                            $effectiveMin = $product->min_stock_override ?? ($product->category->default_item_min_stock ?? 0);
                            $isLow = $totalStock < $effectiveMin;
                                        $stockWarning = $isLow || $totalStock === 0;
                        @endphp
                        <div class="bg-white rounded-lg shadow-sm border {{ $stockWarning ? 'border-l-4 border-l-red-500 border-gray-200' : 'border-gray-200' }} p-4 flex flex-col justify-between hover:shadow-md transition">
                            <div>
                                <div class="flex flex-wrap justify-between items-start gap-2 mb-2">
                                    <span class="font-mono text-xs font-bold bg-gray-100 text-gray-800 px-2 py-0.5 rounded border">
                                        {{ $product->sku }}
                                    </span>
                                    <span class="text-xs font-bold px-2 py-0.5 rounded {{ $stockWarning ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">
                                        {{ $totalStock === 0 ? 'Kifogyott: ' : ($isLow ? 'Minimum alatt: ' : 'Készlet: ') }}{{ $totalStock }} db (Min: {{ $effectiveMin }})
                                    </span>
                                </div>

                                <h3 class="font-semibold text-gray-900 text-base mb-1">{{ $product->product_name }}</h3>
                                <p class="text-xs text-gray-500 mb-3 leading-relaxed">
                                    {{ optional(optional($product->category)->parent)->category_name ? $product->category->parent->category_name . ' ➔ ' : '' }}{{ optional($product->category)->category_name }}
                                </p>

                                <!-- Raktári bontás a kártyán -->
                                <details class="bg-gray-50 rounded-md p-2.5 text-xs space-y-2 border border-gray-100 mb-3">
                                    <summary class="cursor-pointer font-medium text-gray-600">Raktári eloszlás · {{ $product->stocks->count() }} raktár</summary>
                                    @forelse($product->stocks as $st)
                                        <div class="flex justify-between text-gray-700">
                                            <span>{{ optional($st->warehouse)->warehouse_name }}</span>
                                            <span class="font-bold">{{ $st->quantity }} db</span>
                                        </div>
                                    @empty
                                        <div class="text-gray-400 italic">0 db készleten</div>
                                    @endforelse
                                </details>
                            </div>

                            <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                                <div>
                                    <span class="text-xs text-gray-400 block">Egységár</span>
                                    <span class="font-bold text-gray-900 text-sm">{{ number_format($product->price, 0, ',', ' ') }} Ft</span>
                                </div>
                                <div class="flex items-center gap-3 text-xs">
                                    <a href="{{ route('products.edit', $product) }}" class="font-semibold text-blue-600 hover:underline">Szerkesztés</a>
                                    <form action="{{ route('products.destroy', $product) }}" method="POST" onsubmit="return confirm('Biztosan törölni szeretnéd ezt a terméket?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="font-semibold text-red-600 hover:underline">Törlés</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-3 bg-white rounded-lg p-8 text-center text-gray-500 border">
                            Nincs a szűrési feltételeknek megfelelő termék.
                                            <a href="{{ route('products.index') }}" class="block mt-2 text-indigo-600 font-semibold hover:underline">Összes termék megjelenítése</a>
                        </div>
                    @endforelse
                </div>

                <!-- Közös lábléc a táblázatos és kártyás nézethez; egyetlen oldalnál is látható. -->
                <div id="product-list-footer" class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                    <div class="flex flex-wrap items-center gap-3 text-sm text-gray-600">
                        <p aria-live="polite">{{ $products->firstItem() ?? 0 }}–{{ $products->lastItem() ?? 0 }} / {{ $products->total() }} találat</p>
                        <label for="product-per-page" class="sr-only">Találatok oldalanként</label>
                        <select id="product-per-page" name="per_page" form="product-filters" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach([10, 25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} / oldal</option>
                            @endforeach
                        </select>
                    </div>
                    {{ $products->onEachSide(1)->links('pagination.inventory') }}
                </div>

            </div>
        </div>
    </div>
</x-app-layout>
