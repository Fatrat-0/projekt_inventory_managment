<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Kategóriák Kezelése') }}
            </h2>
            <a href="{{ route('categories.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded shadow">
                + Új Kategória
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Visszajelző üzenetek -->
            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative shadow-sm">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                        <tr>
                            <th class="px-6 py-3">ID</th>
                            <th class="px-6 py-3">Kategória Neve</th>
                            <th class="px-6 py-3">Szülő Kategória</th>
                            <th class="px-6 py-3 text-center">Alap Min. (db)</th>
                            <th class="px-6 py-3 text-center">Össz. Min. (db)</th>
                            <th class="px-6 py-3 text-right">Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($categories as $category)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-6 py-4">{{ $category->category_id }}</td>
                            <td class="px-6 py-4 font-medium text-gray-900">{{ $category->category_name }}</td>
                            
                            <!-- Szülő kategória megjelenítése (ha van) -->
                            <td class="px-6 py-4">
                                @if($category->parent)
                                    <span class="bg-gray-200 text-gray-800 text-xs px-2 py-1 rounded">{{ $category->parent->category_name }}</span>
                                @else
                                    <span class="text-gray-400 italic">Főkategória</span>
                                @endif
                            </td>

                            <td class="px-6 py-4 text-center">{{ $category->default_item_min_stock ?? '-' }}</td>
                            <td class="px-6 py-4 text-center">{{ $category->aggregate_min_stock ?? '-' }}</td>
                            
                            <td class="px-6 py-4 text-right flex justify-end gap-3">
                                <!-- Szerkesztés gomb -->
                                <a href="{{ route('categories.edit', $category->category_id) }}" class="text-blue-600 hover:underline">Szerkesztés</a>
                                
                                <form action="{{ route('categories.destroy', $category->category_id) }}" method="POST" onsubmit="return confirm('Biztosan törlöd ezt a kategóriát?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:underline">Törlés</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-app-layout>