<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Vezérlőpult (Dashboard)') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 font-bold text-lg">
                    Üdvözlünk a Raktárkezelő Rendszerben, {{ Auth::user()->name }}! 👋
                </div>
            </div>

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

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <div class="bg-white shadow-sm sm:rounded-lg border-l-4 border-red-500">
                    <div class="p-6 border-b border-gray-100 bg-red-50">
                        <h3 class="text-red-800 font-bold text-lg flex items-center">
                            ⚠️ Alacsony készlet figyelmeztetés
                        </h3>
                    </div>
                    <div class="p-0">
                        <table class="w-full text-sm text-left">
                            <tbody>
                                @forelse($lowStocks as $stock)
                                <tr class="border-b">
                                    <td class="px-6 py-3 font-medium text-gray-900">{{ $stock->product->product_name }}</td>
                                    <td class="px-6 py-3 text-gray-500">{{ $stock->warehouse->warehouse_name }}</td>
                                    <td class="px-6 py-3 text-right font-bold text-red-600">{{ $stock->quantity }} db</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="3" class="px-6 py-4 text-center text-green-600 font-medium">Minden termékből elegendő készlet áll rendelkezésre! ✅</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white shadow-sm sm:rounded-lg border-l-4 border-blue-500">
                    <div class="p-6 border-b border-gray-100 bg-blue-50">
                        <h3 class="text-blue-800 font-bold text-lg flex items-center">
                            ⏱️ Legutóbbi Raktár-mozgások
                        </h3>
                    </div>
                    <div class="p-0">
                        <table class="w-full text-sm text-left">
                            <tbody>
                                @forelse($recentMovements as $movement)
                                <tr class="border-b">
                                    <td class="px-6 py-3 font-bold {{ $movement->type == 'in' ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $movement->type == 'in' ? '+ IN' : '- OUT' }}
                                    </td>
                                    <td class="px-6 py-3">{{ $movement->product->product_name }}</td>
                                    <td class="px-6 py-3 text-right font-bold">{{ $movement->quantity }} db</td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="3" class="px-6 py-4 text-center text-gray-500">Még nem történt mozgás ma.</td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

        </div>
    </div>
</x-app-layout>