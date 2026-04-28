<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Új Felhasználó Hozzáadása') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-8 shadow-sm sm:rounded-lg border-t-4 border-blue-600">
                
                <form action="{{ route('users.store') }}" method="POST" class="space-y-6">
                    @csrf
                    
                    <div>
                        <label class="block font-medium text-sm text-gray-700">Teljes Név</label>
                        <input type="text" name="name" value="{{ old('name') }}" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500" required autofocus>
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <label class="block font-medium text-sm text-gray-700">E-mail cím</label>
                        <input type="email" name="email" value="{{ old('email') }}" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500" required>
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-sm text-gray-700">Jelszó (Min. 8 karakter)</label>
                            <input type="password" name="password" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500" required autocomplete="new-password">
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>

                        <div>
                            <label class="block font-medium text-sm text-gray-700">Jelszó megerősítése</label>
                            <input type="password" name="password_confirmation" class="w-full border-gray-300 rounded-md shadow-sm mt-1 focus:ring-blue-500 focus:border-blue-500" required autocomplete="new-password">
                            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end mt-6 gap-4 pt-4 border-t border-gray-100">
                        <a href="{{ route('users.index') }}" class="text-gray-500 hover:text-gray-700 font-medium">
                            Mégse
                        </a>
                        <x-primary-button>
                            Felhasználó Létrehozása
                        </x-primary-button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</x-app-layout>