<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight break-all">
            {{ __('Termék Szerkesztése: ') }} {{ $product->product_name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-6 overflow-hidden shadow-sm sm:rounded-lg">
                <x-operation-feedback />
                <form action="{{ route('products.update', $product) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="mb-6"><x-edit-conflict :record="$product" :values="['Terméknév' => $product->product_name, 'Cikkszám (SKU)' => $product->sku, 'Kategória' => $product->category?->category_name, 'Ár (Ft)' => $product->price, 'Egyedi minimum' => $product->min_stock_override]"
                        :field-map="['Terméknév' => 'product_name', 'Cikkszám (SKU)' => 'sku', 'Kategória' => 'category_id', 'Ár (Ft)' => 'price', 'Egyedi minimum' => 'min_stock_override']" :reload-url="route('products.edit', $product)" /></div>

                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <label for="product-name" class="block font-medium text-sm text-gray-700">Terméknév</label>
                            <input id="product-name" type="text" name="product_name" value="{{ is_scalar(old('product_name', $product->product_name)) ? old('product_name', $product->product_name) : '' }}" maxlength="100" class="w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label for="product-sku" class="block font-medium text-sm text-gray-700">Cikkszám (SKU)</label>
                            <input id="product-sku" type="text" name="sku" value="{{ is_scalar(old('sku', $product->sku)) ? old('sku', $product->sku) : '' }}" maxlength="50" class="w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label for="product-category" class="block font-medium text-sm text-gray-700">Kategória</label>
                            <select id="product-category" name="category_id" class="w-full border-gray-300 rounded-md shadow-sm">
                                @foreach($categories as $category)
                                    <option value="{{ $category->category_id }}" 
                                        @selected((string) $category->category_id === (is_scalar(old('category_id', $product->category_id)) ? (string) old('category_id', $product->category_id) : ''))>
                                        {{ $category->category_name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="product-price" class="block font-medium text-sm text-gray-700">Ár (Ft)</label>
                            <input id="product-price" type="number" name="price" min="0" step="0.01" value="{{ is_scalar(old('price', $product->price)) ? old('price', $product->price) : '' }}" class="w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <!-- Egyedi minimum készlet -->
                        <div class="mt-4">
                            <label for="product-minimum" class="block font-medium text-sm text-gray-700">Egyedi minimum készlet (Opcionális)</label>
                            <input id="product-minimum" type="number" min="0" name="min_stock_override" value="{{ is_scalar(old('min_stock_override', $product->min_stock_override)) ? old('min_stock_override', $product->min_stock_override) : '' }}" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-xs text-gray-500 mt-1">Hagyd üresen, ha a kategória alapértelmezett minimumát szeretnéd használni. Ha ide írsz egy számot, az felülbírálja a kategória szabályát.</p>
                            <x-input-error :messages="$errors->get('min_stock_override')" class="mt-2" />
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
