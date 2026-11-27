<x-app-layout>
    <div class="app-page min-w-0 space-y-4 py-4" data-listing-page="users" x-data="listingView('users')" :data-listing-view="viewMode">
        <x-page-toolbar title="Felhasználók" :count="$users->total()" unit="találat">
            <a href="{{ route('users.create') }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ Új felhasználó</a>
            <x-listing-view-toggle />
        </x-page-toolbar>
        <x-operation-feedback />
        @php($listOptions = \Illuminate\Support\Arr::only($filters, ['sort_by', 'sort_dir', 'per_page']))
        <section aria-labelledby="account-counts" class="space-y-2">
            <h2 id="account-counts" class="text-xs font-medium text-gray-600">Fiókösszesítők a teljes rendszerben</h2>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))] gap-3">
                @foreach(['' => ['Összes fiók', $totals->total], 'active' => ['Aktív fiókok', $totals->total - $totals->inactive], 'inactive' => ['Inaktív fiókok', $totals->inactive], 'unassigned' => ['Aktív, tagság nélküli munkatársak', $totals->unassigned]] as $state => [$label, $count])
                    @php($selected = (string) $filters['state'] === $state && count($filterLabels) === ($state === '' ? 0 : 1))
                    <a href="{{ route('users.index', $listOptions + ['state' => $state ?: null]) }}"
                        @if($selected) aria-current="page" @endif
                        class="min-w-0 rounded-lg border bg-white p-4 hover:border-indigo-400 focus-visible:ring-2 focus-visible:ring-indigo-500 {{ $selected ? 'border-indigo-300' : 'border-gray-200' }}">
                        <p class="text-xs text-gray-600">{{ $label }}</p>
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($count, 0, ',', ' ') }}</p>
                    </a>
                @endforeach
            </div>
        </section>
        <form id="user-filters" method="GET" action="{{ route('users.index') }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4">
            <div class="min-w-0 flex-1 basis-52">
                <x-input-label for="user-search" value="Név vagy e-mail-cím" />
                <x-text-input id="user-search" name="search" type="search" :value="$filters['search']" placeholder="Keresés név vagy e-mail-cím szerint…" maxlength="200" class="mt-1 w-full text-sm" />
            </div>
            <div class="min-w-0 max-w-full"><x-input-label for="user-role" value="Globális szerepkör" />
                <select id="user-role" name="role" class="mt-1 max-w-full rounded-md border-gray-300 text-sm"><option value="">Minden szerepkör</option>@foreach(\App\Models\User::ROLES as $role => $label)<option value="{{ $role }}" @selected($filters['role'] === $role)>{{ $label }}</option>@endforeach</select>
            </div>
            <div class="min-w-0 max-w-full"><x-input-label for="user-state" value="Állapot" />
                <select id="user-state" name="state" class="mt-1 max-w-full rounded-md border-gray-300 text-sm"><option value="">Minden állapot</option>@foreach(\App\Http\Controllers\UserController::STATES as $state => $label)<option value="{{ $state }}" @selected($filters['state'] === $state)>{{ $label }}</option>@endforeach</select>
            </div>
            <div class="min-w-0 max-w-full"><x-input-label for="user-warehouse" value="Raktári tagság" />
                <select id="user-warehouse" name="warehouse" class="mt-1 w-full max-w-56 rounded-md border-gray-300 text-sm"><option value="">Minden raktári tagság</option><option value="none" @selected($filters['warehouse'] === 'none')>Tagság nélkül</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->getKey() }}" @selected((string) $filters['warehouse'] === (string) $warehouse->getKey())>{{ $warehouse->warehouse_name }}</option>@endforeach</select>
            </div>
            <input type="hidden" name="sort_by" value="{{ $filters['sort_by'] }}">
            <input type="hidden" name="sort_dir" value="{{ $filters['sort_dir'] }}">
            <x-primary-button variant="indigo">Szűrés</x-primary-button>
        </form>
        @if($filterLabels)
            <div class="flex flex-wrap items-center gap-2" aria-label="Aktív felhasználói szűrők">
                @foreach($filterLabels as $key => $label)
                    <a href="{{ route('users.index', array_replace($filters, [$key => null])) }}" aria-label="{{ $label }} szűrő eltávolítása" class="max-w-full break-all rounded-md border border-indigo-100 bg-indigo-50 px-2 py-1 text-xs text-indigo-800">{{ $label }} ×</a>
                @endforeach
                <a href="{{ route('users.index', $listOptions) }}" class="py-1 text-xs text-gray-600 underline">Összes szűrő törlése</a>
            </div>
        @endif
        <x-listing-card-sort :sorts="\App\Http\Controllers\UserController::SORTS" :filters="$filters" form="user-filters" />
        <div data-listing-table class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-gray-200 bg-gray-50 text-xs text-gray-700"><tr>
                    @foreach(\App\Http\Controllers\UserController::SORTS as $key => $label)
                        @php($sorted = $filters['sort_by'] === $key)
                        @php($direction = $sorted && $filters['sort_dir'] === 'asc' ? 'desc' : 'asc')
                        <th data-listing-column="{{ $key }}" x-show="columnVisible('{{ $key }}')" scope="col" aria-sort="{{ $sorted ? ($filters['sort_dir'] === 'asc' ? 'ascending' : 'descending') : 'none' }}" class="px-3 py-3">
                            <a href="{{ route('users.index', array_replace($filters, ['sort_by' => $key, 'sort_dir' => $direction])) }}" class="inline-flex gap-1 font-semibold hover:text-indigo-700"
                                @click="if (!$event.ctrlKey && !$event.metaKey && !$event.shiftKey && !$event.altKey) { $event.preventDefault(); const form = document.getElementById('user-filters'); form.elements.namedItem('sort_by').value = '{{ $key }}'; form.elements.namedItem('sort_dir').value = '{{ $direction }}'; form.requestSubmit(); }">{{ $label }} <span aria-hidden="true">{{ $sorted ? ($filters['sort_dir'] === 'asc' ? '↑' : '↓') : '↕' }}</span></a>
                        </th>
                    @endforeach
                    <th data-listing-column="actions" scope="col" class="px-3 py-3"><span class="sr-only">Műveletek</span><x-listing-columns /></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($users as $user)
                        <tr data-user-id="{{ $user->id }}" class="hover:bg-gray-50">
                            <td data-listing-column="name" class="break-words px-3 py-3 font-semibold"><a href="{{ route('users.show', $user) }}" class="text-indigo-700 hover:underline">{{ $user->name }}</a></td>
                            <td data-listing-column="email" x-show="columnVisible('email')" class="break-all px-3 py-3">{{ $user->email }}</td>
                            <td data-listing-column="role" class="px-3 py-3">{{ \App\Models\User::ROLES[$user->role] }}</td>
                            <td data-listing-column="memberships_count" x-show="columnVisible('memberships_count')" class="break-words px-3 py-3">@include('users.warehouse-summary')</td>
                            <td data-listing-column="state" class="px-3 py-3">
                                @include('users.status')
                                @if($user->isActive() && $user->password_change_required)
                                    <p class="mt-1 text-xs text-gray-600">Saját jelszó beállítására vár.</p>
                                @endif
                            </td>
                            <td data-listing-column="created_at" x-show="columnVisible('created_at')" class="px-3 py-3">{{ $user->created_at?->format('Y.m.d. H:i') ?? '–' }}</td>
                            <td data-listing-column="actions" class="px-3 py-2">@include('users.actions', ['variant' => 'table'])</td>
                        </tr>
                    @empty
                        <tr><td :colspan="visibleColumnCount()" colspan="7" class="px-4 py-10 text-center text-gray-600">Nincs a feltételeknek megfelelő fiók.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('users.cards')
        <footer class="flex flex-wrap items-center justify-between gap-3 text-sm text-gray-600">
            <div class="flex flex-wrap items-center gap-3">
                <span>{{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }} / {{ number_format($users->total(), 0, ',', ' ') }} találat</span>
                <label for="user-page-size" class="sr-only">Találatok oldalanként</label><select id="user-page-size" name="per_page" form="user-filters" onchange="this.form.requestSubmit()" class="rounded-md border-gray-300 text-sm">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} / oldal</option>@endforeach</select>
            </div>
            {{ $users->links('pagination.inventory') }}
        </footer>
    </div>
</x-app-layout>
