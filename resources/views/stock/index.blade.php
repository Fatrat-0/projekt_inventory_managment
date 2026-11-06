<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Aktuális Raktárkészlet (Leltár)') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-x-auto shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                        <tr>
                            <th class="px-6 py-3">Termék (SKU)</th>
                            <th class="px-6 py-3">Raktár</th>
                            <th class="px-6 py-3 text-right">Mennyiség</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stocks as $stock)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-6 py-4 font-medium text-gray-900">
                                {{ $stock->product->product_name }} <span class="text-gray-400">({{ $stock->product->sku }})</span>
                            </td>
                            <td class="px-6 py-4">{{ $stock->warehouse->warehouse_name }}</td>
                            <td class="px-6 py-4 text-right font-bold {{ $stock->available_quantity <= 5 ? 'text-red-600' : 'text-green-600' }}">
                                {{ $stock->quantity }} db
                                @if($stock->quarantined_quantity > 0)<p class="mt-1 text-xs font-normal text-gray-500">Kiadható: {{ number_format($stock->available_quantity, 0, ',', ' ') }} db · <a href="{{ route('quality-returns.index', ['status' => 'open', 'product_id' => $stock->product_id, 'warehouse_id' => $stock->warehouse_id]) }}" data-quality-link class="font-medium text-indigo-600 underline hover:text-indigo-800">Elkülönítve: {{ number_format($stock->quarantined_quantity, 0, ',', ' ') }} db</a></p>@endif
                            </td>
                        </tr>
                        @endforeach
                        
                        @if($stocks->isEmpty())
                        <tr>
                            <td colspan="3" class="px-6 py-4 text-center text-gray-500">Jelenleg üres a raktárkészlet.</td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
