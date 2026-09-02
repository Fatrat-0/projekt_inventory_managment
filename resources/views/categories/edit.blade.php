<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Kategória Szerkesztése: <span class="text-blue-600">{{ $category->category_name }}</span>
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-8 shadow-sm sm:rounded-lg border-t-4 border-yellow-500">
                
                <form action="{{ route('categories.update', $category->category_id) }}" method="POST" class="space-y-6">
                    @csrf
                    @method('PUT')
                    
                    <!-- Kategória Neve -->
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Kategória neve *</label>
                        <input type="text" name="category_name" value="{{ old('category_name', $category->category_name) }}" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500" required>
                        <x-input-error :messages="$errors->get('category_name')" class="mt-2" />
                    </div>

                    <!-- Szülő Kategória -->
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Szülő kategória</label>
                        <select name="parent_id" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Ez egy főkategória (Nincs szülő) --</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->category_id }}" {{ old('parent_id', $category->parent_id) == $cat->category_id ? 'selected' : '' }}>
                                    {{ $cat->category_name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Ha kiválasztasz egyet, ez a kategória annak az alkategóriája lesz.</p>
                        <x-input-error :messages="$errors->get('parent_id')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t border-gray-100">
                        <!-- Alapértelmezett Egyedi Minimum -->
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Alapértelmezett termék minimum (db)</label>
                            <input type="number" min="0" name="default_item_min_stock" value="{{ old('default_item_min_stock', $category->default_item_min_stock) }}" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-xs text-gray-500 mt-1">Az ide tartozó termékek ezt az értéket öröklik, ha nincs egyedi minimumuk.</p>
                            <x-input-error :messages="$errors->get('default_item_min_stock')" class="mt-2" />
                        </div>

                        <!-- Összesített Minimum -->
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Összesített kategória minimum (db)</label>
                            <input type="number" min="0" name="aggregate_min_stock" value="{{ old('aggregate_min_stock', $category->aggregate_min_stock) }}" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500">
                            <p class="text-xs text-gray-500 mt-1">A rendszer a kategóriába tartozó összes termék együttes darabszámát figyeli.</p>
                            <x-input-error :messages="$errors->get('aggregate_min_stock')" class="mt-2" />
                        </div>
                    </div>

                    <!-- Gombok -->
                    <div class="flex items-center justify-end mt-6 gap-4 pt-4 border-t border-gray-100">
                        <a href="{{ route('categories.index') }}" class="text-gray-500 hover:text-gray-700 font-medium">Mégse</a>
                        <x-primary-button>Módosítások Mentése</x-primary-button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</x-app-layout>