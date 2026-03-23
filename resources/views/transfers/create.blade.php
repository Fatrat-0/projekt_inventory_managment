<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Új Átmozgatás Indítása</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <form action="{{ route('transfers.store') }}" method="POST" class="space-y-4">
                    @csrf
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Termék</label>
                        <select name="product_id" class="w-full border-gray-300 rounded-md shadow-sm mt-1">
                            @foreach($products as $product)
                                <option value="{{ $product->product_id }}">{{ $product->product_name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Honnan (Forrás)</label>
                            <select name="from_warehouse_id" class="w-full border-gray-300 rounded-md shadow-sm mt-1">
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->warehouse_id }}">{{ $warehouse->warehouse_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Hová (Cél)</label>
                            <select name="to_warehouse_id" class="w-full border-gray-300 rounded-md shadow-sm mt-1">
                                @foreach($warehouses as $warehouse)
                                    <option value="{{ $warehouse->warehouse_id }}">{{ $warehouse->warehouse_name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block font-medium text-sm text-gray-700">Mennyiség</label>
                        <input type="number" name="quantity" class="w-full border-gray-300 rounded-md shadow-sm mt-1" min="1" required>
                    </div>

                    <x-primary-button>Átmozgatás Indítása</x-primary-button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>