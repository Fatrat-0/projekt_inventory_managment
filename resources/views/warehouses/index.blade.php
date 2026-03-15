<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Raktárak Kezelése') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <form action="{{ route('warehouses.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    @csrf
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Raktár Neve</label>
                        <input type="text" name="warehouse_name" class="w-full border-gray-300 rounded-md shadow-sm mt-1" required>
                    </div>
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Cím / Lokáció</label>
                        <input type="text" name="location" class="w-full border-gray-300 rounded-md shadow-sm mt-1" required>
                    </div>
                    <div>
                        <x-primary-button>Hozzáadás</x-primary-button>
                    </div>
                </form>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                        <tr>
                            <th class="px-6 py-3">Raktár Neve</th>
                            <th class="px-6 py-3">Lokáció</th>
                            <th class="px-6 py-3 text-right">Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($warehouses as $warehouse)
                        <tr class="border-b">
                            <td class="px-6 py-4 font-medium text-gray-900">{{ $warehouse->warehouse_name }}</td>
                            <td class="px-6 py-4">{{ $warehouse->location }}</td>
                            <td class="px-6 py-4 text-right">
                                <form action="{{ route('warehouses.destroy', $warehouse->warehouse_id) }}" method="POST" onsubmit="return confirm('Biztosan törlöd?');">
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