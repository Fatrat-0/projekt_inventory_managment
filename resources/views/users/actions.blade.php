@php
    $menuId = 'user-actions-'.$user->id.'-'.$variant;
    $lastAdmin = $onlyUsableAdmin && $user->isUsableAdministrator();
    $self = auth()->id() === $user->id;
    $stateAction = $user->isActive() ? 'Inaktiválás' : 'Újraaktiválás';
    $menuClass = 'block w-full rounded-md px-3 py-2 text-left hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none';
@endphp
<div x-data="{ passwordLink: false, saving: false }" data-account-actions class="shrink-0">
    <div x-data="rowActions" class="flex justify-end" @resize.window="if (open) position()" @scroll.window.capture="if (open) position()">
        <button type="button" x-ref="trigger" popovertarget="{{ $menuId }}" aria-controls="{{ $menuId }}" aria-haspopup="menu" :aria-expanded="open"
            aria-label="Műveletek: {{ $user->name }}" @keydown.arrow-down.prevent="keyboardOpen()" @keydown.arrow-up.prevent="keyboardOpen(true)"
            class="inline-flex h-9 w-9 items-center justify-center rounded-md text-xl font-semibold text-gray-600 hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-indigo-500"><span aria-hidden="true">⋮</span></button>
        <div id="{{ $menuId }}" x-ref="menu" popover="auto" role="menu" aria-label="Fiókműveletek" @toggle="changed($event)" @keydown="navigate($event)" @focusout="leave($event)"
            class="m-0 max-h-[calc(100dvh-1rem)] overflow-y-auto rounded-lg border border-gray-200 bg-white p-1 text-sm text-gray-700 shadow-xl" style="position:fixed; inset:auto; width:min(16rem,calc(100vw - 1rem))">
            @if($variant !== 'detail')
                <a role="menuitem" class="{{ $menuClass }}" href="{{ route('users.show', $user) }}">Adatlap</a>
                <a role="menuitem" class="{{ $menuClass }}" href="{{ route('users.edit', $user) }}">Szerkesztés</a>
                <a role="menuitem" class="{{ $menuClass }}" href="{{ route('users.edit', $user) }}#raktari-tagsagok">Raktári jogosultságok</a>
            @endif
            <a role="menuitem" class="{{ $menuClass }}" href="{{ route('users.show', $user) }}#jogosultsagi-elozmenyek">Jogosultsági előzmények</a>
            @if($mailAvailable && $user->isActive())
                <button type="button" role="menuitem" class="{{ $menuClass }}" @click="passwordLink = true; $refs.menu.hidePopover(); $nextTick(() => $el.closest('[data-account-actions]').querySelector('dialog').showModal())">Jelszóbeállító link küldése</button>
            @endif
            @if(!$lastAdmin && !$self)
                <button type="button" role="menuitem" class="{{ $menuClass }}" @click="passwordLink = false; $refs.menu.hidePopover(); $nextTick(() => $el.closest('[data-account-actions]').querySelector('dialog').showModal())">{{ $stateAction }}</button>
            @else
                <p class="border-t border-gray-100 px-3 py-2 text-xs text-gray-600">{{ $lastAdmin ? 'Az utolsó használható adminisztrátori fiók.' : 'Saját fiók · másik adminisztrátor inaktiválhatja.' }}</p>
            @endif
        </div>
    </div>
    <dialog aria-labelledby="{{ $menuId }}-confirm" class="m-auto max-h-[calc(100dvh-2rem)] w-[min(32rem,calc(100vw-2rem))] overflow-y-auto rounded-lg border border-gray-200 bg-white p-5 shadow-xl backdrop:bg-gray-950/40" @close="saving = false; $el.closest('[data-account-actions]').querySelector('[popovertarget]').focus()">
        <h2 id="{{ $menuId }}-confirm" class="font-semibold"><span x-show="!passwordLink">{{ $stateAction }}</span><span x-show="passwordLink">Jelszóbeállító link küldése</span></h2>
        <div class="mt-3 space-y-1 rounded-md bg-gray-50 p-3 text-sm [overflow-wrap:anywhere]">
            <p class="font-semibold">{{ $user->name }}</p>
            <p>{{ $user->email }}</p>
            <p>Globális szerepkör · {{ \App\Models\User::ROLES[$user->role] }}</p>
        </div>
        @if($user->isActive())
            <div x-show="!passwordLink" class="mt-3 space-y-2 text-sm text-gray-700">
                <p>A fiókkal nem lehet bejelentkezni, a meglévő munkamenetek megszűnnek.</p>
                <p>A korábbi üzleti műveletek és a napló megmaradnak. A fiók később újraaktiválható.</p>
            </div>
        @else
            <div x-show="!passwordLink" class="mt-3 space-y-2 text-sm text-gray-700">
                <p>Újra be lehet jelentkezni a mentett globális szerepkörrel. {{ \App\Models\User::ROLE_DESCRIPTIONS[$user->role] }}</p>
                @if($user->role !== 'admin')
                    @forelse($user->warehouseMemberships as $membership)
                        @if($loop->first)<ul class="list-disc space-y-1 pl-5">@endif
                        <li class="[overflow-wrap:anywhere]">{{ $membership->warehouse->warehouse_name }} · {{ \App\Models\WarehouseMembership::roleLabels($membership->roleCodes()) }}</li>
                        @if($loop->last)</ul>@endif
                    @empty
                        <p>A helyi raktári műveletekhez tagság szükséges.</p>
                    @endforelse
                @endif
                @if($user->password_change_required)<p>A hozzáférés használatához előbb saját jelszót kell beállítani.</p>@endif
            </div>
        @endif
        <p x-show="passwordLink" class="mt-3 text-sm text-gray-700">Egyszer használható hivatkozást küldünk a fiók e-mail-címére. A korábban elküldött link az új link kiadásakor érvényét veszti.</p>
        <div class="mt-4 flex flex-wrap justify-end gap-2">
            <form method="dialog"><x-secondary-button variant="neutral" type="submit" autofocus>Mégse</x-secondary-button></form>
            <form x-show="!passwordLink" method="POST" action="{{ route('users.access', $user) }}" @submit="if (saving) $event.preventDefault(); else saving = true">
                @csrf @method('PATCH')
                <input type="hidden" name="active" value="{{ $user->isActive() ? '0' : '1' }}">
                <input type="hidden" name="lock_version" value="{{ $user->lock_version }}">
                @if($user->isActive())
                    <button type="submit" :disabled="saving" class="inline-flex min-h-10 items-center rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 disabled:opacity-50">Fiók inaktiválása</button>
                @else
                    <x-primary-button variant="indigo" x-bind:disabled="saving">Fiók újraaktiválása</x-primary-button>
                @endif
            </form>
            @if($mailAvailable)
                <form x-show="passwordLink" method="POST" action="{{ route('users.password-link', $user) }}" @submit="if (saving) $event.preventDefault(); else saving = true">
                    @csrf
                    <input type="hidden" name="lock_version" value="{{ $user->lock_version }}">
                    <x-primary-button variant="indigo" x-bind:disabled="saving">Link küldése</x-primary-button>
                </form>
            @endif
        </div>
    </dialog>
</div>
