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
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Termékek Kezelése') }}
            </h2>
            <a href="{{ route('products.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                + Új Termék
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
                                    <th scope="col" class="px-6 py-3">Cikkszám (SKU)</th>
                                    <th scope="col" class="px-6 py-3">Terméknév</th>
                                    <th scope="col" class="px-6 py-3">Kategória</th>
                                    <th scope="col" class="px-6 py-3">Ár</th>
                                    <th scope="col" class="px-6 py-3 text-right">Műveletek</th>
                                </tr>
                            </thead>
                            <tbody>
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

                </div>
            </div>
        </div>
    </div>
</x-app-layout>

</body>
</html>