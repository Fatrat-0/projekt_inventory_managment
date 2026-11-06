<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Aktuális Raktárkészlet (Leltár)') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <x-operation-feedback />
            @if($warehouse)<p class="mb-4 rounded-md bg-indigo-50 p-4 text-sm text-indigo-900">Raktár: <strong>{{ $warehouse->warehouse_name }}</strong> · <a href="{{ route('stock.index') }}" class="font-semibold underline">Összes raktár</a></p>@endif
            <p class="mb-4 text-sm text-gray-600">A helyi minimum raktáranként és termékenként érvényes, alapból 5 db. Az utánpótlási javaslatokat vezérli; a globális termék- és kategóriaminimumoktól független. A 0 kikapcsolja az adott készletsor helyi pótlási igényét.</p>
            <div class="bg-white overflow-x-auto shadow-sm sm:rounded-lg p-6">
                <table class="w-full text-sm text-left text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                        <tr>
                            <th class="px-6 py-3">Termék (SKU)</th>
                            <th class="px-6 py-3">Raktár</th>
                            <th class="px-6 py-3 text-right">Mennyiség</th>
                            <th class="px-6 py-3 text-right">Helyi minimum</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stocks as $stock)
                        <tr class="border-b hover:bg-gray-50">
                            <td class="px-6 py-4 font-medium text-gray-900">
                                {{ $stock->product->product_name }} <span class="text-gray-400">({{ $stock->product->sku }})</span>
                            </td>
                            <td class="px-6 py-4">{{ $stock->warehouse->warehouse_name }}</td>
                            <td class="px-6 py-4 text-right font-bold {{ $stock->available_quantity < $stock->min_quantity ? 'text-red-600' : 'text-green-600' }}">
                                {{ $stock->quantity }} db
                                @if($stock->quarantined_quantity > 0)<p class="mt-1 text-xs font-normal text-gray-500">Kiadható: {{ number_format($stock->available_quantity, 0, ',', ' ') }} db · <a href="{{ route('quality-returns.index', ['status' => 'open', 'product_id' => $stock->product_id, 'warehouse_id' => $stock->warehouse_id]) }}" data-quality-link class="font-medium text-indigo-600 underline hover:text-indigo-800">Elkülönítve: {{ number_format($stock->quarantined_quantity, 0, ',', ' ') }} db</a></p>@endif
                            </td>
                            <td class="px-6 py-4 text-right" data-stock-minimum="{{ $stock->id }}">
                                {{ number_format($stock->min_quantity, 0, ',', ' ') }} db
                                @if(in_array(auth()->user()->role, ['admin', 'manager'], true))
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-sm font-medium text-indigo-700">Módosítás</summary>
                                        <form method="POST" action="{{ route('stock.minimum', $stock) }}" class="mt-2 flex flex-wrap items-end justify-end gap-2">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="expected_minimum" value="{{ $stock->min_quantity }}">
                                            <label class="text-left text-xs">Helyi minimum ({{ $stock->product->sku }})<input type="number" name="min_quantity" value="{{ $stock->min_quantity }}" min="0" max="2147483647" step="1" required class="mt-1 block w-28 rounded-md border-gray-300 text-sm"></label>
                                            <button class="min-h-10 rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Mentés</button>
                                        </form>
                                    </details>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                        
                        @if($stocks->isEmpty())
                        <tr>
                            <td colspan="4" class="px-6 py-4 text-center text-gray-500">Jelenleg üres a raktárkészlet.</td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
