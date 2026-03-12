<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Termék Szerkesztése: ') }} {{ $product->product_name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-6 overflow-hidden shadow-sm sm:rounded-lg">
                <form action="{{ route('products.update', $product) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Terméknév</label>
                            <input type="text" name="product_name" value="{{ $product->product_name }}" class="w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Cikkszám (SKU)</label>
                            <input type="text" name="sku" value="{{ $product->sku }}" class="w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Kategória</label>
                            <select name="category_id" class="w-full border-gray-300 rounded-md shadow-sm">
                                @foreach($categories as $category)
                                    <option value="{{ $category->category_id }}" 
                                        {{ $category->category_id == $product->category_id ? 'selected' : '' }}>
                                        {{ $category->category_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Ár (Ft)</label>
                            <input type="number" name="price" value="{{ $product->price }}" class="w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div class="flex items-center justify-end mt-4">
                            <x-primary-button>
                                {{ __('Frissítés') }}
                            </x-primary-button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>