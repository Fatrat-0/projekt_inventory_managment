<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Vezérlőpult (Dashboard)') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Üdvözlő üzenet -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 font-bold text-lg">
                    Üdvözlünk a Raktárkezelő Rendszerben, {{ Auth::user()->name }}! 👋
                </div>
            </div>

            <!-- Felső statisztikák (Ezt érintetlenül hagytuk) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-blue-100 p-6 rounded-lg shadow border border-blue-200 flex items-center justify-between">
                    <div>
                        <p class="text-blue-600 font-bold uppercase text-sm">Összes Termék</p>
                        <h3 class="text-3xl font-extrabold text-blue-900">{{ $totalProducts }} db</h3>
                    </div>
                    <div class="text-blue-300 text-5xl">📦</div>
                </div>
                
                <div class="bg-purple-100 p-6 rounded-lg shadow border border-purple-200 flex items-center justify-between">
                    <div>
                        <p class="text-purple-600 font-bold uppercase text-sm">Aktív Raktárak</p>
                        <h3 class="text-3xl font-extrabold text-purple-900">{{ $totalWarehouses }} db</h3>
                    </div>
                    <div class="text-purple-300 text-5xl">🏢</div>
                </div>
            </div>

            <!-- ÚJ: INTELLIGENS RIASZTÁSOK (Két oszlopos rács) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <!-- 1. Egyedi Termékhiányok (A "Csavar" logika) -->
                <div class="bg-white shadow-sm sm:rounded-lg border-l-4 border-red-500">
                    <div class="p-6 border-b border-gray-100 bg-red-50">
                        <h3 class="text-red-800 font-bold text-lg flex items-center gap-2">
                            <span>⚠️</span> Kritikus Termék Készletek
                        </h3>
                    </div>
                    <div class="p-0">
                        <table class="w-full text-sm text-left">
                            <tbody>
                                @forelse($lowStockProducts as $product)
                                @php
                                    $threshold = $product->min_stock_override ?? optional($product->category)->default_item_min_stock;
                                    $current = $product->stocks_sum_quantity ?? 0;
                                @endphp
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <span class="font-bold text-gray-900">{{ $product->product_name }}</span>
                                        <span class="text-xs text-gray-500 block">SKU: {{ $product->sku }}</span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <span class="font-bold text-red-600 text-lg">{{ $current }} db</span>
                                        <span class="text-xs text-gray-500 block">Min: {{ $threshold }} db</span>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="2" class="px-6 py-6 text-center text-green-600 font-medium">
                                        Minden egyedi termékből megfelelő mennyiség áll rendelkezésre! ✅
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 2. Kategória Szintű Hiányok (A "Laptop" logika) -->
                <div class="bg-white shadow-sm sm:rounded-lg border-l-4 border-orange-500">
                    <div class="p-6 border-b border-gray-100 bg-orange-50">
                        <h3 class="text-orange-800 font-bold text-lg flex items-center gap-2">
                            <span>📦</span> Kategória Szintű Hiányok
                        </h3>
                    </div>
                    <div class="p-0">
                        <table class="w-full text-sm text-left">
                            <tbody>
                                @forelse($lowStockCategories as $category)
                                @php
                                    $current = $category->products->sum(function($p) { return $p->stocks->sum('quantity'); });
                                @endphp
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <span class="font-bold text-gray-900">{{ $category->category_name }}</span>
                                        <span class="text-xs text-gray-500 block">Összesített kategória</span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <span class="font-bold text-orange-600 text-lg">{{ $current }} db</span>
                                        <span class="text-xs text-gray-500 block">Min: {{ $category->aggregate_min_stock }} db</span>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="2" class="px-6 py-6 text-center text-green-600 font-medium">
                                        Minden kategória készlet megfelelő szinten van! ✅
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- ALSÓ SZEKCIÓ: Legutóbbi Raktár-mozgások (Ezt megtartottuk, szélesebb lett) -->
            <div class="bg-white shadow-sm sm:rounded-lg border-l-4 border-blue-500 mt-6">
                <div class="p-6 border-b border-gray-100 bg-blue-50">
                    <h3 class="text-blue-800 font-bold text-lg flex items-center gap-2">
                        <span>⏱️</span> Legutóbbi Raktár-mozgások
                    </h3>
                </div>
                <div class="p-0 overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th class="px-6 py-3">Típus</th>
                                <th class="px-6 py-3">Termék</th>
                                <th class="px-6 py-3">Raktár</th>
                                <th class="px-6 py-3 text-right">Mennyiség</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentMovements as $movement)
                            <tr class="border-b hover:bg-gray-50">
                                <td class="px-6 py-4 font-bold {{ $movement->type == 'in' ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $movement->type == 'in' ? '+ BEVÉTEL' : '- KIADÁS' }}
                                </td>
                                <td class="px-6 py-4">{{ $movement->product->product_name }}</td>
                                <td class="px-6 py-4">{{ $movement->warehouse->warehouse_name }}</td>
                                <td class="px-6 py-4 text-right font-bold">{{ $movement->quantity }} db</td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="4" class="px-6 py-6 text-center text-gray-500">
                                    Még nem történt mozgás a rendszerben.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>