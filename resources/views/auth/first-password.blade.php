<x-guest-layout>
    <h1 class="mb-1 text-lg font-semibold text-gray-900">Saját jelszó beállítása</h1>
    <p class="mb-5 text-sm text-gray-600">Az első belépés előtt cseréld le az ideiglenes jelszót.</p>
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf @method('PUT')
        <x-input-label for="current_password" value="Ideiglenes jelszó" /><x-text-input id="current_password" name="current_password" type="password" class="w-full" autocomplete="current-password" required />
        <x-input-error :messages="$errors->updatePassword->get('current_password')" />
        <x-input-label for="password" value="Saját jelszó" /><x-text-input id="password" name="password" type="password" class="w-full" autocomplete="new-password" required />
        <x-input-error :messages="$errors->updatePassword->get('password')" />
        <x-input-label for="password_confirmation" value="Jelszó megerősítése" /><x-text-input id="password_confirmation" name="password_confirmation" type="password" class="w-full" autocomplete="new-password" required />
        <x-primary-button>Jelszó mentése</x-primary-button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf <button class="text-sm underline">Kijelentkezés</button></form>
</x-guest-layout>
