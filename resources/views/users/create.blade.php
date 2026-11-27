<x-app-layout>
    @php
        $editing = $user->exists;
        $saved = $user->warehouseMemberships->map(fn ($membership) => [
            'warehouse_id' => (string) $membership->warehouse_id, 'roles' => $membership->roleCodes(),
        ])->values()->all();
        $draft = old('memberships_submitted') && in_array(old('memberships_complete'), [1, '1'], true) ? old('memberships', []) : $saved;
        $draft = collect(is_array($draft) ? $draft : [])->filter(fn ($row) => is_array($row) && is_scalar($row['warehouse_id'] ?? null))
            ->map(fn ($row) => ['warehouse_id' => (string) $row['warehouse_id'], 'roles' => collect($row['roles'] ?? [])
                ->filter(fn ($role) => is_string($role) && isset(\App\Models\WarehouseMembership::ROLES[$role]))->unique()->values()->all()])->values()->all();
        $choices = $warehouses->map(fn ($warehouse) => ['value' => (string) $warehouse->getKey(), 'name' => $warehouse->warehouse_name, 'label' => $warehouse->warehouse_name])->all();
        $roles = \App\Models\User::ROLES;
        $localRoles = \App\Models\WarehouseMembership::ROLES;
        $defaultDelivery = $mailAvailable ? 'link' : 'temporary';
        $passwordPolicy = \Illuminate\Validation\Rules\Password::defaults()->appliedRules();
        $returnUrl = $editing ? route('users.show', $user) : route('users.index');
        $state = [
            'accountName' => is_string(old('name', $user->name)) ? old('name', $user->name) : '',
            'email' => is_string(old('email', $user->email)) ? old('email', $user->email) : '',
            'role' => is_string(old('role', $user->role)) ? old('role', $user->role) : 'worker',
            'delivery' => old('password_delivery', $defaultDelivery) === 'link' && $mailAvailable ? 'link' : 'temporary',
            'initial' => ['role' => $user->role, 'memberships' => $saved, 'accountName' => $user->name ?? '', 'email' => $user->email ?? '', 'delivery' => $defaultDelivery],
            'memberships' => $draft, 'warehouses' => $choices, 'roles' => $roles, 'localRoles' => $localRoles,
            'roleHelp' => \App\Models\User::ROLE_DESCRIPTIONS, 'capabilities' => \App\Models\WarehouseMembership::CAPABILITIES,
            'editing' => $editing, 'isOwnAccount' => $editing && auth()->id() === $user->id,
        ];
    @endphp
    <div class="mx-auto max-w-3xl space-y-4 px-4 py-4 sm:px-6">
        <a class="text-sm text-gray-500 hover:text-indigo-600" href="{{ $returnUrl }}">← {{ $editing ? 'Adatlap' : 'Felhasználók' }}</a>
        <h1 class="text-xl font-bold text-gray-900">{{ $editing ? 'Felhasználó szerkesztése' : 'Új felhasználó' }}</h1>
        <x-operation-feedback />
        <form x-data="userForm(@js($state))" method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}"
            class="space-y-5 rounded-lg border border-gray-200 bg-white p-4 sm:p-6" @submit="confirm($event)" @pageshow.window="restore($event, $el)">
            @csrf
            @if($editing)
                @method('PATCH')
                <x-edit-conflict :record="$user" :reload-url="route('users.edit', $user)"
                    :values="['Név' => $user->name, 'E-mail-cím' => $user->email, 'Globális szerepkör' => $roles[$user->role],
                        'Raktári tagságok' => $user->warehouseMemberships->map(fn ($membership) => $membership->warehouse->warehouse_name.' · '.\App\Models\WarehouseMembership::roleLabels($membership->roleCodes()))->implode('; ')]"
                    :field-map="['Név' => 'name', 'E-mail-cím' => 'email']" />
            @endif
            <fieldset class="space-y-3">
                <legend class="font-semibold">Fiókadatok</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="min-w-0"><x-input-label for="account-name" value="Teljes név" /><x-text-input id="account-name" name="name" :value="$state['accountName']" x-model="accountName" maxlength="255" required class="mt-1 w-full" /><x-input-error :messages="$errors->get('name')" /></div>
                    <div class="min-w-0"><x-input-label for="account-email" value="E-mail-cím" /><x-text-input id="account-email" type="email" name="email" :value="$state['email']" x-model="email" maxlength="255" required class="mt-1 w-full" /><x-input-error :messages="$errors->get('email')" /></div>
                    <div class="min-w-0">
                        <x-input-label for="account-role" value="Globális szerepkör" />
                        <select id="account-role" name="role" x-model="role" aria-describedby="account-role-help" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                            @foreach($roles as $value => $label)<option value="{{ $value }}" @selected($state['role'] === $value)>{{ $label }}</option>@endforeach
                        </select>
                        <x-input-error :messages="$errors->get('role')" />
                    </div>
                </div>
                @if($editing)
                    <div x-show="emailEdited" class="max-w-sm">
                        <x-input-label for="account-email-password" value="Saját jelenlegi jelszó e-mail-cím módosításához" />
                        <x-text-input id="account-email-password" name="current_password" type="password" autocomplete="current-password"
                            x-bind:required="emailEdited" x-bind:disabled="!emailEdited" class="mt-1 w-full" />
                        <x-input-error :messages="$errors->get('current_password')" />
                    </div>
                @endif
                <p id="account-role-help" class="text-sm text-gray-600" aria-live="polite" x-text="roleHelp[role]">{{ \App\Models\User::ROLE_DESCRIPTIONS[$state['role']] ?? '' }}</p>
                @if($lastUsableAdmin)
                    <p class="text-sm text-gray-700">Ez az utolsó használható adminisztrátori fiók. A szerepkör akkor módosítható, ha egy másik aktív adminisztrátor már beállította saját jelszavát.</p>
                @endif
            </fieldset>
            <fieldset id="raktari-tagsagok" class="space-y-3 border-t border-gray-100 pt-4">
                <legend class="font-semibold">Raktári tagságok</legend>
                <input name="memberships_submitted" type="hidden" value="1">
                <p x-show="role === 'admin'" x-cloak class="text-sm text-gray-700">Az adminisztrátor minden raktárat elér. A megtartott tagságok lefokozáskor ismét meghatározzák a helyi hozzáférést.</p>
                <p x-show="role !== 'admin' && !memberships.length" x-cloak class="text-sm text-gray-600">Tagság nélkül a fiók elérheti a szerepköréhez tartozó közös nézeteket. A helyi raktári műveletekhez rendelj hozzá raktárat és szerepkört.</p>
                <div x-show="role !== 'admin'" class="max-w-sm" @warehouse-selected.stop="add($event.detail.value)">
                    <x-input-label for="account-warehouse-picker" value="Raktár hozzáadása" />
                    <x-autocomplete-picker :items="$choices" id="account-warehouse-picker" :name="null" event-name="warehouse-selected" model-value="warehouseSelection" available-values="availableWarehouses" placeholder="Keresés raktárnév alapján…" />
                </div>
                <template x-for="(membership, index) in memberships" :key="membership.key">
                    <div class="min-w-0 space-y-3 rounded-md border border-gray-200 p-3">
                        <input type="hidden" :name="'memberships[' + index + '][warehouse_id]'" :value="membership.warehouse_id">
                        <div class="flex flex-wrap items-center justify-between gap-x-3">
                            <p class="min-w-0 py-1 text-sm font-semibold [overflow-wrap:anywhere]" x-text="warehouseName(membership.warehouse_id)"></p>
                            <button type="button" @click="remove(index)" :aria-label="'Tagság eltávolítása · ' + warehouseName(membership.warehouse_id)" class="min-h-11 rounded-md px-3 text-sm text-red-700 hover:bg-red-50 focus-visible:ring-2 focus-visible:ring-red-500">Eltávolítás</button>
                        </div>
                        <fieldset class="min-w-0 space-y-2">
                            <legend class="text-sm font-medium text-gray-700">Helyi szerepkörök<span class="sr-only" x-text="' · ' + warehouseName(membership.warehouse_id)"></span></legend>
                            <template x-for="savedRole in (role === 'admin' ? membership.roles : [])" :key="savedRole">
                                <input type="hidden" :name="'memberships[' + index + '][roles][]'" :value="savedRole">
                            </template>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach($localRoles as $value => $label)
                                    <label class="flex min-h-11 min-w-0 cursor-pointer items-start gap-2 rounded-md bg-gray-50 p-2 text-sm [overflow-wrap:anywhere] focus-within:ring-2 focus-within:ring-indigo-500">
                                        <input type="checkbox" value="{{ $value }}" x-model="membership.roles" :id="'local-role-' + membership.key + '-{{ $value }}'"
                                            :name="'memberships[' + index + '][roles][]'" :disabled="role === 'admin'"
                                            :aria-labelledby="'role-name-' + membership.key + '-{{ $value }}'" :aria-describedby="'role-help-' + membership.key + '-{{ $value }}'"
                                            class="mt-0.5 shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="min-w-0">
                                            <span :id="'role-name-' + membership.key + '-{{ $value }}'" class="block font-medium text-gray-900">{{ $label }}</span>
                                            <span :id="'role-help-' + membership.key + '-{{ $value }}'" class="block text-xs text-gray-600">{{ \App\Models\WarehouseMembership::ROLE_DESCRIPTIONS[$value] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                        <p x-show="!membership.roles.length" class="text-sm text-amber-800">Válassz legalább egy helyi szerepkört, vagy távolítsd el a tagságot.</p>
                        <div x-show="membership.roles.length" role="group" :aria-labelledby="'local-access-' + membership.key" class="space-y-1 text-sm">
                            <p :id="'local-access-' + membership.key" class="font-medium"><span x-text="role === 'admin' ? 'Helyi jogosultságok lefokozás után' : 'Együttes helyi jogosultságok'"></span><span class="sr-only" x-text="' · ' + warehouseName(membership.warehouse_id)"></span></p>
                            <ul class="list-disc space-y-0.5 pl-5 text-gray-600">
                                <template x-for="capability in capabilitiesFor(membership.roles)" :key="capability.key">
                                    <li><span x-text="capability.label"></span><span x-show="capability.overlaps" class="text-gray-500"> · Több szerepkör is megadja</span></li>
                                </template>
                            </ul>
                        </div>
                    </div>
                </template>
                <x-input-error :messages="collect($errors->getMessages())->filter(fn ($messages, $key) => in_array($key, ['memberships', 'memberships_complete'], true) || str_starts_with($key, 'memberships.'))->flatten()->all()" />
            </fieldset>
            @unless($editing)
                <fieldset class="space-y-3 border-t border-gray-100 pt-4">
                    <legend class="font-semibold">Első bejelentkezés</legend>
                    @if($mailAvailable)
                        <div class="flex flex-wrap gap-3">
                            <label class="inline-flex items-center gap-2 text-sm"><input type="radio" name="password_delivery" value="link" x-model="delivery" @checked($state['delivery'] === 'link')> Jelszóbeállító link</label>
                            <label class="inline-flex items-center gap-2 text-sm"><input type="radio" name="password_delivery" value="temporary" x-model="delivery" @checked($state['delivery'] === 'temporary')> Ideiglenes jelszó</label>
                        </div>
                    @else
                        <input type="hidden" name="password_delivery" value="temporary">
                        <p class="text-sm text-gray-600">Ebben a környezetben ideiglenes jelszóval adható ki fiók. A jelszólinkhez kézbesítő levelező szükséges.</p>
                    @endif
                    <x-input-error :messages="$errors->get('password_delivery')" />
                    <p x-show="delivery === 'link'" x-cloak class="text-sm text-gray-600">Az egyszer használható hivatkozást a megadott e-mail-címre küldjük.</p>
                    <div class="grid gap-3 sm:grid-cols-2" x-show="delivery === 'temporary'" x-cloak>
                        <p id="temporary-password-help" class="text-sm text-gray-600 sm:col-span-2">
                            Legalább {{ $passwordPolicy['min'] }} karakter.
                            @if($passwordPolicy['max']) Legfeljebb {{ $passwordPolicy['max'] }} karakter. @endif
                            @if($passwordPolicy['mixedCase']) Kis- és nagybetű is szükséges. @elseif($passwordPolicy['letters']) Legalább egy betű szükséges. @endif
                            @if($passwordPolicy['numbers']) Legalább egy szám szükséges. @endif
                            @if($passwordPolicy['symbols']) Legalább egy különleges karakter szükséges. @endif
                            @if($passwordPolicy['uncompromised']) Ismerten kiszivárgott jelszó nem használható. @endif
                            Az első belépéskor a munkatársnak saját jelszót kell beállítania.
                        </p>
                        <div class="min-w-0"><x-input-label for="temporary-password" value="Ideiglenes jelszó" /><x-text-input id="temporary-password" type="password" name="password" :required="$state['delivery'] === 'temporary'" x-bind:required="delivery === 'temporary'" x-bind:disabled="delivery !== 'temporary'" autocomplete="new-password" aria-describedby="temporary-password-help" @input="passwordEntered = $el.value !== ''" class="mt-1 w-full" /><x-input-error :messages="$errors->get('password')" /></div>
                        <div class="min-w-0"><x-input-label for="temporary-confirmation" value="Jelszó megerősítése" /><x-text-input id="temporary-confirmation" type="password" name="password_confirmation" x-bind:required="delivery === 'temporary'" x-bind:disabled="delivery !== 'temporary'" autocomplete="new-password" @input="confirmationEntered = $el.value !== ''" class="mt-1 w-full" /></div>
                    </div>
                </fieldset>
            @endunless
            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 pt-4">
                <p role="status" class="mr-auto text-sm font-medium text-amber-800" x-show="dirty" x-cloak>Mentetlen módosítások.</p>
                <a class="inline-flex min-h-10 items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" href="{{ $returnUrl }}">Mégse</a>
                <x-primary-button variant="indigo" disabled x-bind:disabled="submitting || hasEmptyMembership">{{ $editing ? 'Módosítások ellenőrzése' : 'Felhasználó létrehozása' }}</x-primary-button>
            </div>
            <noscript><p class="text-sm text-gray-700">A fiókszerkesztéshez engedélyezd a JavaScriptet.</p></noscript>
            <dialog x-ref="review" @close="if (!confirmed) $el.closest('form').querySelector('[type=submit]').focus()" aria-labelledby="account-review-title"
                class="m-auto max-h-[calc(100dvh-2rem)] w-[min(34rem,calc(100vw-2rem))] overflow-y-auto rounded-lg border border-gray-200 bg-white p-5 shadow-xl backdrop:bg-gray-950/40">
                <h2 id="account-review-title" class="font-semibold">Fiók mentésének megerősítése</h2>
                <p class="mt-2 break-words text-sm"><strong x-text="accountName"></strong> · <span class="break-all" x-text="email"></span></p>
                <p x-show="!editing" class="mt-2 text-sm">Globális szerepkör <strong x-text="roleLabel"></strong></p>
                <ul x-show="changes.length" class="mt-3 space-y-2 text-sm">
                    <template x-for="change in changes" :key="change.key"><li class="rounded-md bg-gray-50 p-2 [overflow-wrap:anywhere]"><strong class="mr-1" x-text="change.label"></strong><span x-text="change.text"></span></li></template>
                </ul>
                <div x-show="changes.length" class="mt-3 space-y-1 text-sm [overflow-wrap:anywhere]">
                    <p class="font-medium">Tényleges hozzáférés változása</p>
                    <p x-show="!accessChanges.length" class="text-gray-600">A tényleges hozzáférés változatlan.</p>
                    <ul class="list-disc space-y-1 pl-5 text-gray-700">
                        <template x-for="change in accessChanges" :key="change.key"><li x-text="change.text"></li></template>
                    </ul>
                </div>
                <p x-show="editing && !dirty" class="mt-3 text-sm text-gray-600">A fiók adatai változatlanok.</p>
                <p x-show="revokesAccess" class="mt-3 rounded-md bg-amber-50 p-3 text-sm text-amber-900"
                    x-text="isOwnAccount ? 'A mentés után ki leszel jelentkeztetve. Az új szerepköröd és tagságaid a következő belépéskor érvényesülnek.' : 'Az érintett felhasználó korábbi munkamenetei megszűnnek. A folytatáshoz újra be kell jelentkeznie.'"></p>
                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <x-secondary-button variant="neutral" autofocus @click="$refs.review.close()">Mégse</x-secondary-button>
                    <x-primary-button variant="indigo" type="button" @click="save($event.currentTarget.form)" x-bind:disabled="submitting">Mentés megerősítése</x-primary-button>
                </div>
            </dialog>
            <input name="memberships_complete" type="hidden" value="1">
        </form>
    </div>
</x-app-layout>
