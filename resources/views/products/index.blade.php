<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termékek Listája</title>
</head>
<body style="font-family: Arial, sans-serif; padding: 20px;">

    <x-app-layout>
    <x-slot name="header">
        <div class="mb-6 flex justify-between items-center bg-white p-4 rounded-lg shadow-sm">
            <form action="{{ route('products.index') }}" method="GET" class="flex items-center gap-4">
                <div class="flex gap-2">
                    <input type="text" name="search" value="{{ $search ?? '' }}" 
                        placeholder="Keresés..." 
                        class="border-gray-300 rounded-md shadow-sm w-80">
                    <x-primary-button type="submit">Keresés</x-primary-button>
                </div>

                <div class="flex items-center gap-2 border-l pl-4 border-gray-200">
                    <label class="text-sm text-gray-600">Megjelenítés:</label>
                    <select name="per_page" onchange="this.form.submit()" class="border-gray-300 rounded-md shadow-sm text-sm">
                        <option value="10" {{ $perPage == 10 ? 'selected' : '' }}>10</option>
                        <option value="25" {{ $perPage == 25 ? 'selected' : '' }}>25</option>
                        <option value="50" {{ $perPage == 50 ? 'selected' : '' }}>50</option>
                        <option value="100" {{ $perPage == 100 ? 'selected' : '' }}>100</option>
                    </select>
                </div>

                @if($search || $sortBy != 'created_at' || $sortDir != 'desc')
                    <a href="{{ route('products.index') }}" class="text-sm text-gray-500 hover:text-red-500 underline decoration-dotted underline-offset-4">
                        Rendezés / Szűrők törlése
                    </a>
                @endif
            </form>

            <a href="{{ route('products.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
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
                baseUrl: '{{ route('export.inventory', request()->query()) }}',
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

                <button @click="open = true" type="button" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded shadow inline-flex items-center gap-2 transition">
                    PDF előnézet
                </button>

                <div x-show="open" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto bg-gray-900 bg-opacity-60 flex items-center justify-center p-4">
                    <div @click.away="open = false" class="bg-white rounded-lg shadow-2xl w-full max-w-6xl overflow-hidden flex flex-col md:flex-row" style="height: 88vh;">
                        
                        <!-- Bal oldal: Beállítások -->
                        <div class="w-full md:w-1/3 bg-gray-50 p-5 border-r border-gray-200 flex flex-col justify-between overflow-y-auto">
                            <div class="space-y-4">
                                <div class="flex justify-between items-center border-b pb-2">
                                    <h3 class="text-lg font-bold text-gray-800">PDF Testreszabása</h3>
                                    <button @click="open = false" class="text-gray-400 hover:text-gray-600 font-bold text-xl">&times;</button>
                                </div>

                                <!-- Tájolás választó -->
                                <div>
                                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Lap tájolása</p>
                                    <div class="grid grid-cols-2 gap-2">
                                        <button type="button" @click="orientation = 'portrait'" :class="orientation === 'portrait' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 border'" class="py-1.5 px-3 rounded text-sm font-medium transition shadow-sm">
                                            ▯ Álló (A4)
                                        </button>
                                        <button type="button" @click="orientation = 'landscape'" :class="orientation === 'landscape' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 border'" class="py-1.5 px-3 rounded text-sm font-medium transition shadow-sm">
                                            ▭ Fekvő (A4)
                                        </button>
                                    </div>
                                </div>

                                <!-- Megjelenő oszlopok -->
                                <div>
                                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Megjelenő oszlopok</p>
                                    <div class="space-y-1.5 bg-white p-3 rounded border border-gray-200">
                                        <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                            <input type="checkbox" x-model="showSku" class="rounded border-gray-300 text-indigo-600">
                                            <span>Cikkszám (SKU)</span>
                                        </label>
                                        <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                            <input type="checkbox" x-model="showCategory" class="rounded border-gray-300 text-indigo-600">
                                            <span>Kategória</span>
                                        </label>
                                        <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                            <input type="checkbox" x-model="showWarehouses" class="rounded border-gray-300 text-indigo-600">
                                            <span>Raktári eloszlás részletezése</span>
                                        </label>
                                        <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer" :class="blindMode ? 'opacity-50' : ''">
                                            <input type="checkbox" x-model="showPrices" :disabled="blindMode" class="rounded border-gray-300 text-indigo-600">
                                            <span>Árak és készletérték (Ft)</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Leltározási segédletek -->
                                <div>
                                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Leltározási mód</p>
                                    <div class="space-y-2 bg-white p-3 rounded border border-gray-200">
                                        <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                            <input type="checkbox" x-model="showCountCol" class="rounded border-gray-300 text-indigo-600">
                                            <span>„Számolt mennyiség” üres oszlop</span>
                                        </label>
                                        <label class="flex items-center space-x-2 text-sm text-amber-800 bg-amber-50 p-1.5 rounded border border-amber-200 cursor-pointer">
                                            <input type="checkbox" x-model="blindMode" class="rounded border-amber-400 text-amber-600">
                                            <span class="font-semibold">Vakleltár (Mennyiségek elrejtése)</span>
                                        </label>
                                    </div>
                                </div>

                                <!-- Egyéb elemek -->
                                <div>
                                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Dokumentum elemek</p>
                                    <div class="space-y-1.5 bg-white p-3 rounded border border-gray-200">
                                        <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                            <input type="checkbox" x-model="showFilters" class="rounded border-gray-300 text-indigo-600">
                                            <span>Aktív szűrők fejlécben</span>
                                        </label>
                                        <label class="flex items-center space-x-2 text-sm text-gray-700 cursor-pointer">
                                            <input type="checkbox" x-model="showSignatures" class="rounded border-gray-300 text-indigo-600">
                                            <span>Aláírási mezők a lap alján</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- Letöltés gombok -->
                            <div class="space-y-2 pt-4 border-t mt-4">
                                <a :href="downloadUrl" @click="open = false" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2.5 px-4 rounded shadow transition flex items-center justify-center gap-2">
                                    PDF Letöltése
                                </a>
                                <button @click="open = false" type="button" class="w-full bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-1.5 px-4 rounded text-sm transition">
                                    Bezárás
                                </button>
                            </div>
                        </div>

                        <!-- Jobb oldal: Élő PDF Előnézet -->
                        <div class="w-full md:w-2/3 bg-gray-200 p-2 flex flex-col">
                            <template x-if="open">
                                <iframe :src="previewUrl" class="w-full h-full rounded border border-gray-300 bg-white shadow-inner"></iframe>
                            </template>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    
                    <div class="relative overflow-x-auto shadow-md sm:rounded-lg">
                        <table class="w-full text-sm text-left text-gray-500">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                                <tr>
                                    <th scope="col" class="px-6 py-3">
                                        <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'sku', 'sort_dir' => ($sortBy == 'sku' && $sortDir == 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-gray-900 transition-colors">
                                            Cikkszám (SKU)
                                            @if($sortBy == 'sku')
                                                <span class="text-blue-600 font-bold text-sm">{{ $sortDir == 'asc' ? '↑' : '↓' }}</span>
                                            @else
                                                <span class="text-gray-400 text-sm">↕</span>
                                            @endif
                                        </a>
                                    </th>

                                    <th scope="col" class="px-6 py-3">
                                        <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'product_name', 'sort_dir' => ($sortBy == 'product_name' && $sortDir == 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-gray-900 transition-colors">
                                            Terméknév
                                            @if($sortBy == 'product_name')
                                                <span class="text-blue-600 font-bold text-sm">{{ $sortDir == 'asc' ? '↑' : '↓' }}</span>
                                            @else
                                                <span class="text-gray-400 text-sm">↕</span>
                                            @endif
                                        </a>
                                    </th>

                                    <th scope="col" class="px-6 py-3">Kategória</th>

                                    <th scope="col" class="px-6 py-3">
                                        <a href="{{ request()->fullUrlWithQuery(['sort_by' => 'price', 'sort_dir' => ($sortBy == 'price' && $sortDir == 'asc') ? 'desc' : 'asc']) }}" class="flex items-center gap-1 hover:text-gray-900 transition-colors">
                                            Ár
                                            @if($sortBy == 'price')
                                                <span class="text-blue-600 font-bold text-sm">{{ $sortDir == 'asc' ? '↑' : '↓' }}</span>
                                            @else
                                                <span class="text-gray-400 text-sm">↕</span>
                                            @endif
                                        </a>
                                    </th>

                                    <th scope="col" class="px-6 py-3 text-right">Műveletek</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Termékek listázása -->
                                @foreach($products as $product)
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-6 py-4 font-medium text-gray-900 whitespace-nowrap">
                                        {{ $product->sku }}
                                    </td>
                                    <td class="px-6 py-4">{{ $product->product_name }}</td>
                                    <td class="px-6 py-4">{{ $product->category->category_name }}</td>
                                    <td class="px-6 py-4">{{ number_format($product->price, 0, ',', ' ') }} Ft</td>
                                    <td class="px-6 py-4 flex justify-end space-x-3">
                                        <a href="{{ route('products.edit', $product) }}" class="font-medium text-blue-600 hover:underline">Szerkesztés</a>
                                        
                                        <form action="{{ route('products.destroy', $product) }}" method="POST" onsubmit="return confirm('Biztosan törölni szeretnéd ezt a terméket?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="font-medium text-red-600 hover:underline">Törlés</button>
                                        </form>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <!-- Lapozó gombok -->
                    <div class="mt-4">
                        {{ $products->appends(request()->query())->links() }}
                    </div>

                </div>
            </div>
        </div>
    </div>
</x-app-layout>

</body>
</html>