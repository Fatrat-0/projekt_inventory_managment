<x-warehouse-layout :$warehouse :context="$params['context'] ?? ''" :detail-context="$params['detail_context'] ?? ''">
    @php
        $locationParams = $params + ['warehouseLocation' => $location];
        $kg = \App\Models\WarehouseLocation::formatWeight(...);
        $input = static fn ($key) => is_scalar(old($key)) ? (string) old($key) : '';
        $requestKey = $input('request_key');
        if ($errors->has('request_key') || !\Illuminate\Support\Str::isUuid($requestKey)) $requestKey = (string) \Illuminate\Support\Str::uuid();
    @endphp
    <div class="app-page space-y-4 py-4">
        <a href="{{ route('warehouses.locations.index', $params) }}" class="text-sm font-medium text-indigo-700 hover:underline">← Tárhelyek és kapacitás</a>
        <x-page-toolbar :title="$location->name" :description="$warehouse->warehouse_name.' · '.$location->code.' · '.\App\Models\WarehouseLocation::KINDS[$location->kind].($location->active ? '' : ' · Kikapcsolt')" />
        <x-operation-feedback />
        <section aria-label="Tárhelykapacitás" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            @include('warehouses.locations.capacity')
        </section>
        @if($canManage)
            <details @if($errors->hasAny(['code', 'name', 'kind', 'max_weight_kg', 'max_pallet_slots', 'active', 'lock_version'])) open @endif class="rounded-lg border border-gray-200 bg-white p-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-900">Tárhely szerkesztése</summary>
                <div class="mt-4">@include('warehouses.locations.form', ['action' => route('warehouses.locations.update', $locationParams)])</div>
            </details>
        @endif
        @if($location->active)
            <section class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="font-semibold text-gray-900">Készlet tárhelyhez rendelése</h2>
                @if($stocks->isEmpty())
                    <p class="text-sm text-gray-600">Jelenleg nincs kiadható, tárhely nélküli készlet a raktárban.</p>
                @else
                    <form method="POST" action="{{ route('warehouses.locations.place', $locationParams) }}" class="space-y-3" x-data="{ submitting: false }" @pageshow.window="if ($event.persisted) submitting = false" @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }">
                        @csrf
                        <input type="hidden" name="request_key" value="{{ $requestKey }}">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label class="min-w-0 text-sm font-medium">Termék
                                <select name="product_id" required class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                    <option value="">Válassz terméket…</option>
                                    @foreach($stocks as $stock)
                                        <option value="{{ $stock->product_id }}" @selected($input('product_id') === (string) $stock->product_id)>{{ $stock->product->product_name }} · {{ $stock->available_quantity - $stock->assigned_quantity }} db</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-sm font-medium">Mennyiség (db)
                                <input name="quantity" type="number" min="1" max="2147483647" step="1" required value="{{ $input('quantity') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            </label>
                            <label class="text-sm font-medium">Teljes tömeg (kg)
                                <input name="cargo_weight_kg" type="number" min="0.001" max="1000000" step="0.001" required value="{{ $input('cargo_weight_kg') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            </label>
                            @if($location->kind === 'pallet')
                                <label class="text-sm font-medium">Raklapok száma
                                    <input name="pallet_slots" type="number" min="1" max="1000000" step="1" required value="{{ $input('pallet_slots') }}" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                                </label>
                            @endif
                        </div>
                        <p class="text-xs text-gray-600">A teljes tömegbe a csomagolást és a raklapot is számítsd bele.</p>
                        <button :disabled="submitting" class="min-h-10 rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Elhelyezés</button>
                    </form>
                @endif
            </section>
        @endif
        <section aria-labelledby="placements-title" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4">
            <h2 id="placements-title" class="font-semibold text-gray-900">Tárhelyen tárolt tételek</h2>
            <ul class="divide-y divide-gray-100">
                @forelse($placements as $placement)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0"><p class="dashboard-text font-medium">{{ $placement->stock->product->product_name }}</p><p class="dashboard-text text-sm text-gray-600">{{ $placement->stock->product->sku }} · {{ $placement->quantity }} db · {{ $kg($placement->cargo_weight_grams) }} kg @if($placement->pallet_slots) · {{ $placement->pallet_slots }} raklap @endif</p></div>
                        <form method="POST" action="{{ route('warehouses.locations.release', $locationParams + ['placement' => $placement]) }}" onsubmit="return confirm('A teljes tétel kikerüljön a tárhelyről?')">
                            @csrf @method('DELETE')
                            <button class="min-h-10 rounded-md border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-50">Kivétel a tárhelyről</button>
                        </form>
                    </li>
                @empty
                    <li class="text-sm text-gray-600">A tárhely üres.</li>
                @endforelse
            </ul>
            {{ $placements->links('pagination.inventory') }}
        </section>
    </div>
</x-warehouse-layout>
