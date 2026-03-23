<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ __('Raktárközi Átmozgatások') }}
            </h2>
            <a href="{{ route('transfers.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                + Új Átmozgatás Indítása
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                        <tr>
                            <th class="px-6 py-3">Dátum</th>
                            <th class="px-6 py-3">Termék</th>
                            <th class="px-6 py-3">Honnan (Forrás)</th>
                            <th class="px-6 py-3">Hová (Cél)</th>
                            <th class="px-6 py-3 text-right">Mennyiség</th>
                            <th class="px-6 py-3 text-center">Állapot</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($transfers as $transfer)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-6 py-4">{{ $transfer->created_at->format('Y.m.d H:i') }}</td>
                            <td class="px-6 py-4 font-medium text-gray-900">{{ $transfer->product->product_name }}</td>
                            <td class="px-6 py-4">{{ $transfer->fromWarehouse->warehouse_name }}</td>
                            <td class="px-6 py-4">{{ $transfer->toWarehouse->warehouse_name }}</td>
                            <td class="px-6 py-4 text-right font-bold">{{ $transfer->quantity }} db</td>
                            <td class="px-6 py-4 text-center">
                                @if($transfer->status == 'pending')
                                    <div class="flex flex-col items-center space-y-2">
                                        <span class="bg-yellow-100 text-yellow-800 text-xs font-medium px-2.5 py-0.5 rounded border border-yellow-300">
                                            ⏳ Úton
                                        </span>
                                        <div class="flex space-x-2">
                                            <form action="{{ route('transfers.complete', $transfer) }}" method="POST" onsubmit="return confirm('Biztosan megérkezett a kamion?');">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" title="Megérkezett" class="text-white bg-green-600 hover:bg-green-700 px-2 py-1 rounded shadow">
                                                    ✓
                                                </button>
                                            </form>

                                            <form action="{{ route('transfers.cancel', $transfer) }}" method="POST" onsubmit="return confirm('Biztosan visszavonod? Az áru visszakerül az indító raktárba!');">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" title="Visszavonás" class="text-white bg-red-600 hover:bg-red-700 px-2 py-1 rounded shadow">
                                                    ✕
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                @elseif($transfer->status == 'completed')
                                    <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded border border-green-300">
                                        ✅ Megérkezett
                                    </span>
                                @else
                                    <span class="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded border border-red-300">
                                        ❌ Törölve
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                        
                        @if($transfers->isEmpty())
                        <tr>
                            <td colspan="6" class="px-6 py-4 text-center text-gray-500">Még nem történt raktárközi átmozgatás.</td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>