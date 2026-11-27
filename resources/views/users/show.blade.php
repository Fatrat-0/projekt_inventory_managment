<x-app-layout>
    @php
        $roles = \App\Models\User::ROLES;
        $localRoles = \App\Models\WarehouseMembership::ROLES;
    @endphp
    <div class="app-page mx-auto max-w-5xl space-y-4 py-4">
        <a class="inline-block text-sm text-gray-500 hover:text-indigo-600" href="{{ route('users.index') }}">← Felhasználók</a>
        <x-page-toolbar title="Felhasználói adatlap">
            <a class="inline-flex min-h-10 items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500" href="{{ route('users.edit', $user) }}">Szerkesztés</a>
        </x-page-toolbar>
        <x-operation-feedback />
        <section aria-labelledby="account-title" class="rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
            <div class="mb-4 flex items-start justify-between gap-2">
                <h2 id="account-title" class="min-w-0 [overflow-wrap:anywhere] text-lg font-semibold">{{ $user->name }}</h2>
                @include('users.actions', ['variant' => 'detail'])
            </div>
            <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div class="min-w-0"><dt class="text-gray-600">E-mail-cím</dt><dd class="mt-1 break-all">{{ $user->email }}</dd></div>
                <div><dt class="text-gray-600">Globális szerepkör</dt><dd class="mt-1">{{ $roles[$user->role] }}</dd></div>
                <div><dt class="text-gray-600">Állapot</dt><dd class="mt-1">@include('users.status')@if($user->deactivated_at)<p class="mt-1">Inaktiválva {{ $user->deactivated_at->format('Y.m.d. H:i') }}</p>@endif @if($user->password_change_required)<p class="mt-1">Saját jelszó beállítására vár.</p>@endif</dd></div>
                <div><dt class="text-gray-600">Fiók létrehozása</dt><dd class="mt-1">{{ $user->created_at?->format('Y.m.d. H:i') ?? '–' }}</dd></div>
            </dl>
        </section>
        <section aria-labelledby="account-warehouses" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
            <div class="flex flex-wrap justify-between gap-2"><h2 id="account-warehouses" class="font-semibold">Raktári hozzáférések</h2><a class="text-sm font-medium text-indigo-700 underline" href="{{ route('users.edit', $user) }}#raktari-tagsagok">Tagságok szerkesztése</a></div>
            @if($user->role === 'admin')
                <p class="text-sm text-gray-700">Az adminisztrátori szerepkör minden raktárra kiterjed. A megtartott tagságok lefokozáskor ismét meghatározzák a helyi hozzáférést.</p>
            @elseif($user->warehouseMemberships->isEmpty())
                <p class="text-sm text-gray-600">Tagság nélkül a fiók elérheti a szerepköréhez tartozó közös nézeteket. A helyi raktári műveletekhez megfelelő tagság szükséges.</p>
            @endif
            @if($user->warehouseMemberships->isNotEmpty())
                <ul class="divide-y divide-gray-100 text-sm">@foreach($user->warehouseMemberships->sortBy(fn ($membership) => $membership->warehouse->warehouse_name) as $membership)
                    <li class="grid min-w-0 gap-x-6 gap-y-1 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                        <span class="[overflow-wrap:anywhere] font-medium">{{ $membership->warehouse->warehouse_name }}</span>
                        <ul class="space-y-1">@foreach($membership->roleCodes() as $role)
                            <li><span class="font-medium">{{ $localRoles[$role] }}</span><span class="block text-gray-600">{{ \App\Models\WarehouseMembership::ROLE_DESCRIPTIONS[$role] }}</span></li>
                        @endforeach</ul>
                    </li>
                @endforeach</ul>
            @endif
        </section>
        <section aria-labelledby="jogosultsagi-elozmenyek" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
            <h2 id="jogosultsagi-elozmenyek" class="font-semibold">Jogosultsági előzmények</h2>
            <p class="text-sm text-gray-600">A lista a jogosultsági napló bevezetése óta rögzített eseményeket mutatja.</p>
            <ol class="divide-y divide-gray-100">
                @forelse($history as $event)
                    <li class="space-y-1 py-3 text-sm">
                        <div class="flex flex-wrap justify-between gap-1"><strong>{{ \App\Models\UserAccessEvent::LABELS[$event->action] }}</strong><time datetime="{{ $event->occurred_at->toIso8601String() }}" class="text-gray-600">{{ $event->occurred_at->format('Y.m.d. H:i') }}</time></div>
                        <p class="break-words text-gray-700">Rögzítette {{ $event->actor->name }}</p>
                        @if($event->changes['role'] ?? null)
                            @php
                                $role = $event->changes['role'];
                            @endphp
                            <p class="text-gray-700">Globális szerepkör · {{ $roles[$role['before']] ?? '–' }} → {{ $roles[$role['after']] }}</p>
                        @endif
                        @foreach($event->changes['memberships'] ?? [] as $change)
                            @php
                                $before = \App\Models\WarehouseMembership::roleLabels($change['before']);
                                $after = \App\Models\WarehouseMembership::roleLabels($change['after']);
                            @endphp
                            <p class="[overflow-wrap:anywhere] text-gray-700"><strong>{{ $before === '' ? 'Hozzáadás' : ($after === '' ? 'Eltávolítás' : 'Módosítás') }}</strong> · {{ $warehouseNames[$change['warehouse_id']] ?? 'Raktár #'.$change['warehouse_id'] }} · {{ $before ?: 'Tagság nélkül' }} → {{ $after ?: 'Tagság nélkül' }}</p>
                        @endforeach
                        @if(!empty($event->changes['fields']))
                            <p class="text-gray-700">Módosított adatok · {{ collect($event->changes['fields'])->map(fn ($field) => ['name' => 'név', 'email' => 'e-mail-cím'][$field] ?? $field)->implode(', ') }}</p>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-gray-600">Ehhez a fiókhoz még nincs naplózott jogosultságváltozás.</li>
                @endforelse
            </ol>
            {{ $history->links('pagination.inventory') }}
        </section>
    </div>
</x-app-layout>
