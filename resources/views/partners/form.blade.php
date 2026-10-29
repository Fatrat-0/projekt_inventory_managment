<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-6 sm:px-6">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Partnerek</a>
        <div>
            <h2 class="text-xl font-bold text-gray-900">{{ $partner ? 'Partner szerkesztése' : 'Új partner' }}</h2>
            <p class="mt-1 text-sm text-gray-500">Vevők és beszállítók alapadatai és elérhetőségei.</p>
        </div>
        <x-operation-feedback />
        <form method="POST" action="{{ $partner ? route('partners.update', $partner) : route('partners.store') }}"
            x-data="{ submitting: false }" @pageshow.window="if ($event.persisted) submitting = false"
            @submit="if (submitting) $event.preventDefault(); else submitting = true"
            class="space-y-5 rounded-lg border border-gray-200 bg-white p-5">
            @csrf
            @if($partner) @method('PUT') @endif
            <input type="hidden" name="context" value="{{ $context }}">
            <div>
                <label for="partner-name" class="mb-1 block text-sm font-medium text-gray-700">Név / Cégnév</label>
                <input id="partner-name" name="name" maxlength="100" required autofocus autocomplete="organization"
                    value="{{ is_scalar(old('name', $partner?->name ?? '')) ? old('name', $partner?->name ?? '') : '' }}"
                    aria-describedby="partner-name-error" @error('name') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                <p id="partner-name-error" class="mt-1 text-sm text-red-700">@error('name'){{ $message }}@enderror</p>
            </div>
            <div>
                <label for="partner-type" class="mb-1 block text-sm font-medium text-gray-700">Partner típusa</label>
                <select id="partner-type" name="type" required aria-describedby="partner-type-error" @error('type') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                    @foreach(\App\Models\Partner::TYPES as $value => $label)<option value="{{ $value }}" @selected(old('type', $defaultType) === $value)>{{ $label }}</option>@endforeach
                </select>
                <p id="partner-type-error" class="mt-1 text-sm text-red-700">@error('type'){{ $message }}@enderror</p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="partner-email" class="mb-1 block text-sm font-medium text-gray-700">E-mail</label>
                    <input id="partner-email" name="email" type="email" autocomplete="email" maxlength="100" spellcheck="false"
                        value="{{ is_scalar(old('email', $partner?->email ?? '')) ? old('email', $partner?->email ?? '') : '' }}"
                        aria-describedby="partner-email-help partner-email-error" @error('email') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                    <p id="partner-email-help" class="mt-1 text-xs text-gray-500">Opcionális e-mail-cím.</p>
                    <p id="partner-email-error" class="mt-1 text-sm text-red-700">@error('email'){{ $message }}@enderror</p>
                </div>
                <div>
                    <label for="partner-phone" class="mb-1 block text-sm font-medium text-gray-700">Telefon</label>
                    <input id="partner-phone" name="phone" type="tel" autocomplete="tel" maxlength="20"
                        value="{{ is_scalar(old('phone', $partner?->phone ?? '')) ? old('phone', $partner?->phone ?? '') : '' }}"
                        aria-describedby="partner-phone-help partner-phone-error" @error('phone') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                    <p id="partner-phone-help" class="mt-1 text-xs text-gray-500">Opcionális, legfeljebb 20 karakter.</p>
                    <p id="partner-phone-error" class="mt-1 text-sm text-red-700">@error('phone'){{ $message }}@enderror</p>
                </div>
            </div>
            <div class="flex flex-wrap justify-end gap-4 border-t border-gray-100 pt-4">
                <a href="{{ $returnUrl }}" class="py-2 text-sm text-gray-600">Mégse</a>
                <button type="submit" :disabled="submitting" x-text="submitting ? 'Mentés…' : 'Partner mentése'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Partner mentése</button>
            </div>
        </form>
    </div>
</x-app-layout>
