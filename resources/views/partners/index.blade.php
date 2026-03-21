<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Partnerek (Vevők és Beszállítók) Kezelése') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Új Partner Űrlap -->
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <form action="{{ route('partners.store') }}" method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    @csrf
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Név / Cégnév</label>
                        <input type="text" name="name" class="w-full border-gray-300 rounded-md shadow-sm mt-1" required>
                    </div>
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Típus</label>
                        <select name="type" class="w-full border-gray-300 rounded-md shadow-sm mt-1" required>
                            <option value="customer">Vevő</option>
                            <option value="supplier">Beszállító</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Email</label>
                        <input type="email" name="email" class="w-full border-gray-300 rounded-md shadow-sm mt-1">
                    </div>
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Telefon</label>
                        <input type="text" name="phone" class="w-full border-gray-300 rounded-md shadow-sm mt-1">
                    </div>
                    <div>
                        <x-primary-button class="w-full justify-center">Hozzáadás</x-primary-button>
                    </div>
                </form>
            </div>

            <!-- Partnerek Listája -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                        <tr>
                            <th class="px-6 py-3">Név</th>
                            <th class="px-6 py-3">Típus</th>
                            <th class="px-6 py-3">Elérhetőségek</th>
                            <th class="px-6 py-3 text-right">Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($partners as $partner)
                        <tr class="border-b">
                            <td class="px-6 py-4 font-medium text-gray-900">{{ $partner->name }}</td>
                            <td class="px-6 py-4 font-bold {{ $partner->type == 'customer' ? 'text-blue-600' : 'text-purple-600' }}">
                                {{ $partner->type == 'customer' ? 'Vevő' : 'Beszállító' }}
                            </td>
                            <td class="px-6 py-4">
                                {{ $partner->email }} <br> <span class="text-gray-400">{{ $partner->phone }}</span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <form action="{{ route('partners.destroy', $partner->id) }}" method="POST" onsubmit="return confirm('Biztosan törlöd ezt a partnert?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 hover:underline">Törlés</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                        @if($partners->isEmpty())
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-gray-500">Még nincsenek partnerek rögzítve.</td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</x-app-layout>