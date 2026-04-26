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