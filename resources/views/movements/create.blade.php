<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Új Mozgás Rögzítése') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <form action="{{ route('movements.store') }}" method="POST" class="grid grid-cols-1 gap-6">
                    @csrf
                    
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Mozgás Típusa</label>
                        <select name="type" class="w-full border-gray-300 rounded-md shadow-sm mt-1" required>
                            <option value="in">Bevételezés (IN) - Készlet növelése</option>
                            <option value="out">Kiadás (OUT) - Készlet csökkentése</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-medium text-sm text-gray-700">Termék</label>
                        <select name="product_id" class="w-full border-gray-300 rounded-md shadow-sm mt-1" required>
                            @foreach($products as $product)
                                <option value="{{ $product->product_id }}">{{ $product->product_name }} ({{ $product->sku }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-medium text-sm text-gray-700">Raktár</label>
                        <select name="warehouse_id" class="w-full border-gray-300 rounded-md shadow-sm mt-1" required>
                            @foreach($warehouses as $warehouse)
                                <option value="{{ $warehouse->warehouse_id }}">{{ $warehouse->warehouse_name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block font-medium text-sm text-gray-700">Mennyiség (db)</label>
                        <input type="number" name="quantity" min="1" value="1" class="w-full border-gray-300 rounded-md shadow-sm mt-1" required>
                    </div>

                    <div class="flex justify-end mt-4">
                        <x-primary-button>Rögzítés</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>