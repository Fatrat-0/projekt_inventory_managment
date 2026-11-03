<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-6 sm:px-6">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Partnerek</a>
        <div>
            <h2 class="text-xl font-bold text-gray-900">{{ $partner ? 'Partner szerkesztése' : 'Új partner' }}</h2>
            <p class="mt-1 text-sm text-gray-500">Vevők és beszállítók alapadatai és elérhetőségei.</p>
        </div>
        @if($partner?->isArchived())
            <div role="status" class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-gray-300 bg-gray-100 p-4 text-sm text-gray-800">
                <p>Archivált partner. Az aktív partnerlistában nem jelenik meg.</p>
                <form method="POST" action="{{ route('partners.restore', $partner) }}">
                    @csrf @method('PATCH')
                    <input type="hidden" name="context" value="{{ $context }}">
                    <button class="font-semibold text-indigo-700 underline">Visszaállítás</button>
                </form>
            </div>
        @endif
        <x-operation-feedback />
        @if($partner)
            <nav aria-label="Partner készletmozgásai" class="flex flex-wrap gap-2">
                @foreach(['' => ['Készletmozgások', $partner->movements_count], 'in' => ['Bevételezések', $partner->receipts_count], 'out' => ['Kiadások', $partner->issues_count]] as $type => [$label, $count])
                    <a href="{{ route('movements.index', array_filter(['partner_id' => $partner->id, 'type' => $type])) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-indigo-50">{{ $label }} ({{ $count }})</a>
                @endforeach
            </nav>
        @endif
        <form method="POST" action="{{ $partner ? route('partners.update', $partner) : route('partners.store') }}"
            x-ref="form" x-data="partnerForm({{ Illuminate\Support\Js::from(['url' => $partner ? route('partners.duplicates-edit', $partner) : route('partners.duplicates'), 'matches' => $duplicates['matches'], 'total' => $duplicates['total'], 'token' => $duplicates['token'], 'acknowledged' => $duplicateAcknowledged]) }})"
            @pageshow.window="restored($event)" @submit="submit($event)"
            class="space-y-5 rounded-lg border border-gray-200 bg-white p-5">
            @csrf
            @if($partner) @method('PUT') @endif
            <input type="hidden" name="context" value="{{ $context }}">
            <div>
                <label for="partner-name" class="mb-1 block text-sm font-medium text-gray-700">Név / Cégnév</label>
                <input id="partner-name" name="name" maxlength="100" required autofocus autocomplete="organization" @input="changed()"
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
                <p class="mt-1 text-xs text-gray-500">A „Vevő és beszállító” partner bevételezéshez és kiadáshoz is választható.</p>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="partner-email" class="mb-1 block text-sm font-medium text-gray-700">E-mail</label>
                    <input id="partner-email" name="email" type="email" autocomplete="email" maxlength="100" spellcheck="false" @input="changed()"
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
            <div>
                <label for="partner-contact-name" class="mb-1 block text-sm font-medium text-gray-700">Kapcsolattartó</label>
                <input id="partner-contact-name" name="contact_name" maxlength="100" autocomplete="name"
                    value="{{ is_scalar(old('contact_name', $partner?->contact_name)) ? old('contact_name', $partner?->contact_name) : '' }}"
                    aria-describedby="partner-contact-name-error" @error('contact_name') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                <p id="partner-contact-name-error" class="mt-1 text-sm text-red-700">@error('contact_name'){{ $message }}@enderror</p>
            </div>
            <fieldset class="space-y-3 border-t border-gray-100 pt-4">
                <legend class="text-sm font-semibold text-gray-700">Partner címe <span class="font-normal text-gray-400">(opcionális)</span></legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach(['country' => ['Ország', 100, 'country-name'], 'postal_code' => ['Irányítószám', 20, 'postal-code'], 'city' => ['Település', 100, 'address-level2'], 'street_address' => ['Utca, házszám', 255, 'address-line1']] as $field => [$label, $max, $autocomplete])
                        <div>
                            <label for="partner-{{ $field }}" class="mb-1 block text-sm font-medium text-gray-700">{{ $label }}</label>
                            <input id="partner-{{ $field }}" name="{{ $field }}" maxlength="{{ $max }}" autocomplete="{{ $autocomplete }}"
                                value="{{ is_scalar(old($field, $partner?->{$field})) ? old($field, $partner?->{$field}) : '' }}"
                                aria-describedby="partner-{{ $field }}-error" @error($field) aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                            <p id="partner-{{ $field }}-error" class="mt-1 text-sm text-red-700">@error($field){{ $message }}@enderror</p>
                        </div>
                    @endforeach
                </div>
            </fieldset>
            <div>
                <label for="partner-notes" class="mb-1 block text-sm font-medium text-gray-700">Belső megjegyzés</label>
                <textarea id="partner-notes" name="notes" rows="3" maxlength="2000" aria-describedby="partner-notes-help partner-notes-error"
                    @error('notes') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">{{ is_scalar(old('notes', $partner?->notes)) ? old('notes', $partner?->notes) : '' }}</textarea>
                <p id="partner-notes-help" class="mt-1 text-xs text-gray-500">Például áruátvételi idő vagy kapcsolattartási tudnivaló. A megjegyzés az alkalmazásban marad, a mozgásnapló PDF-jébe nem kerül bele. Legfeljebb 2000 karakter.</p>
                <p id="partner-notes-error" class="mt-1 text-sm text-red-700">@error('notes'){{ $message }}@enderror</p>
            </div>
            @include('partners.duplicate-warning')
            <div class="flex flex-wrap justify-end gap-4 border-t border-gray-100 pt-4">
                <a href="{{ $returnUrl }}" class="py-2 text-sm text-gray-600">Mégse</a>
                <button type="submit" :disabled="submitting" x-text="submitting ? 'Mentés…' : 'Partner mentése'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Partner mentése</button>
            </div>
        </form>
    </div>
</x-app-layout>
