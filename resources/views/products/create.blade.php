<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Új Termék Hozzáadása') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-6 overflow-hidden shadow-sm sm:rounded-lg">
                <form action="{{ route('products.store') }}" method="POST">
                    @csrf
                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Terméknév</label>
                            <input type="text" name="product_name" class="w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Cikkszám (SKU)</label>
                            <input type="text" name="sku" class="w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Kategória</label>
                            <select name="category_id" class="w-full border-gray-300 rounded-md shadow-sm">
                                @foreach($categories as $category)
                                    <option value="{{ $category->category_id }}">{{ $category->category_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Ár (Ft)</label>
                            <input type="number" name="price" class="w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <!-- Egyedi minimum készlet -->
                        <div class="mt-4">
                            <label class="block font-medium text-sm text-gray-700">Egyedi minimum készlet (Opcionális)</label>
                            <input type="number" min="0" name="min_stock_override" value="{{ old('min_stock_override') }}" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-xs text-gray-500 mt-1">Hagyd üresen, ha a kategória alapértelmezett minimumát szeretnéd használni. Ha ide írsz egy számot, az felülbírálja a kategória szabályát.</p>
                            <x-input-error :messages="$errors->get('min_stock_override')" class="mt-2" />
                        </div>
                        <div class="flex items-center justify-end mt-4">
                            <!--<button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded">
                                Mentés
                            </button>-->
                            <x-primary-button>
                                {{ __('Mentés') }}
                            </x-primary-button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>