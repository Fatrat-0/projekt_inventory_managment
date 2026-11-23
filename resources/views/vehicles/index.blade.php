<x-transfer-layout>
    @php
        $sortColumns = [
            'name' => 'Megnevezés',
            'registration_number' => 'Rendszám',
            'payload_capacity_grams' => 'Hasznos teherbírás',
            'pallet_slots_capacity' => 'Raklaphelykapacitás',
            'active' => 'Állapot',
            'note' => 'Megjegyzés',
        ];
        $config = [
            'canManage' => $canManageVehicles,
            'sortColumns' => $sortColumns,
            'urls' => [
                'index' => route('shipment-plans.vehicles.index'),
                'update' => route('shipment-plans.vehicles.update', '__vehicle__'),
                'shipments' => route('shipment-plans.vehicles.shipments', '__vehicle__'),
            ],
        ];
        $field = 'block w-full min-w-0 rounded-md border-gray-300 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-gray-100';
        $secondary = 'inline-flex min-h-11 w-fit max-w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $primary = 'inline-flex min-h-11 w-fit max-w-full items-center justify-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50';
        $danger = 'inline-flex min-h-11 w-fit max-w-full items-center justify-center rounded-md bg-red-700 px-3 py-2 text-sm font-semibold text-white hover:bg-red-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700 disabled:opacity-50';
        $dialog = 'm-auto max-h-[calc(100dvh-1.5rem)] w-[calc(100%-1.5rem)] overflow-y-auto rounded-lg border border-gray-200 bg-white p-4 text-gray-900 shadow-xl backdrop:bg-gray-900/55 sm:p-5 [overflow-wrap:anywhere]';
    @endphp

    <div class="app-page min-w-0 space-y-4 py-4" data-vehicle-registry
         x-data="vehicleRegistry(@js($config))" @resize.window="closeRowMenus()">
        <x-page-toolbar title="Járművek">
            @if ($canManageVehicles)
                <button type="button" class="{{ $primary }}" @click="openEditor()">Új jármű</button>
            @endif
        </x-page-toolbar>
        @if ($canManageVehicles)
            <p class="text-sm text-gray-600">A járműnapló a létrehozástól védi a járműrekordot a törléstől. A használatból kivont járműveket a műveleti menüben inaktiválhatod. A kapcsolódó fuvarok megmaradnak.</p>
        @endif

        <div class="grid min-w-0 items-end gap-3 rounded-lg border border-gray-200 bg-white p-3 sm:grid-cols-[minmax(0,1fr)_minmax(8rem,12rem)_auto] sm:p-4">
            <div class="min-w-0">
                <label for="vehicle-search" class="mb-1 block text-sm font-medium text-gray-800">Jármű keresése</label>
                <input id="vehicle-search" type="search" x-ref="searchInput" x-model.fill="search" autocomplete="off"
                       placeholder="Megnevezés vagy rendszám" class="{{ $field }}">
            </div>
            @if ($canManageVehicles)
                <div class="min-w-0">
                    <label for="vehicle-filter-status" class="mb-1 block text-sm font-medium text-gray-800">Állapot</label>
                    <select id="vehicle-filter-status" x-model.fill="status" class="{{ $field }}">
                        <option value="all">Összes</option>
                        <option value="active" selected>Aktív</option>
                        <option value="inactive">Inaktív</option>
                    </select>
                </div>
            @endif
            <button type="button" x-cloak x-show="search || status !== 'active'"
                    @click="search = ''; status = 'active'; $refs.searchInput.focus()" class="{{ $secondary }}">Szűrők törlése</button>
        </div>

        <p x-cloak x-show="notice" role="status" x-text="notice"
           class="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 [overflow-wrap:anywhere]"></p>
        <p x-cloak x-show="loading" role="status" class="text-sm text-gray-600">Járművek betöltése…</p>
        <div x-cloak x-show="loadError" class="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 [overflow-wrap:anywhere]">
            <p role="alert" x-text="loadError"></p>
            <button type="button" @click="loadVehicles()" class="{{ $secondary }}">Betöltés újrapróbálása</button>
        </div>
        <p x-cloak x-show="!loading && !loadError && !results.length" role="status"
           class="rounded-lg border border-gray-200 bg-white p-5 text-sm text-gray-700"
           x-text="search || status !== 'all' ? 'Nincs a szűrésnek megfelelő jármű.' : 'Még nincs jármű a nyilvántartásban.'"></p>

        <section x-cloak x-show="!loading && !loadError && results.length" aria-label="Járműlista">
            <p class="mb-2 text-sm text-gray-600"><span x-text="results.length"></span> jármű</p>
            <div data-listing-card-sort="block" class="mb-3">
                <details class="rounded-lg border border-gray-200 bg-white p-3">
                    <summary class="cursor-pointer text-sm font-medium text-gray-700">
                        Rendezés <span class="text-gray-600" x-text="sortLabel"></span>
                    </summary>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($sortColumns as $key => $label)
                            @include('vehicles.sort-button', ['mobile' => true])
                        @endforeach
                    </div>
                </details>
            </div>
            @include('vehicles.table')
            @include('vehicles.cards')
        </section>

        @if ($canManageVehicles)
            @include('vehicles.editor')
            @include('vehicles.transition')
        @endif
        @include('vehicles.related')
    </div>
</x-transfer-layout>
