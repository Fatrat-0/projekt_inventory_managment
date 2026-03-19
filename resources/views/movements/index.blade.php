<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Készletmozgások Története') }}
            </h2>
            <a href="{{ route('movements.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                + Új Mozgás Rögzítése
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                        <tr>
                            <th class="px-6 py-3">Dátum</th>
                            <th class="px-6 py-3">Irány</th>
                            <th class="px-6 py-3">Termék</th>
                            <th class="px-6 py-3">Raktár</th>
                            <th class="px-6 py-3 text-right">Mennyiség</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($movements as $movement)
                        <tr class="border-b">
                            <td class="px-6 py-4">{{ $movement->created_at->format('Y.m.d H:i') }}</td>
                            <td class="px-6 py-4 font-bold {{ $movement->type == 'in' ? 'text-green-600' : 'text-red-600' }}">
                                {{ $movement->type == 'in' ? 'BEVÉTEZÉS (IN)' : 'KIADÁS (OUT)' }}
                            </td>
                            <td class="px-6 py-4">{{ $movement->product->product_name }}</td>
                            <td class="px-6 py-4">{{ $movement->warehouse->warehouse_name }}</td>
                            <td class="px-6 py-4 text-right font-bold">{{ $movement->quantity }} db</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>