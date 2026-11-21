<x-warehouse-layout :$warehouse :context="$moduleContext['context'] ?? ''" :detail-context="$moduleContext['detail_context'] ?? ''">
    @php
        $balances = $relocation->balances;
        $pieces = fn ($value) => number_format($value, 0, ',', ' ').' db';
    @endphp
    <div class="app-page space-y-4 py-4 [overflow-wrap:anywhere]">
        <x-page-toolbar :title="$relocation->reference" description="Raktáron belüli áthelyezés">
            <a href="{{ route('warehouses.relocations.index', ['warehouse' => $warehouse] + $moduleContext) }}" class="inline-flex min-h-11 items-center rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-700">Vissza az előzményekhez</a>
        </x-page-toolbar>
        <div class="grid min-w-0 gap-4 lg:grid-cols-2">
            <section aria-labelledby="relocation-audit" class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                <h2 id="relocation-audit" class="font-semibold text-gray-900">A művelet adatai</h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-600">Termék</dt><dd class="font-semibold">{{ $relocation->product_name }}</dd><dd class="text-xs text-gray-600">{{ $relocation->sku }}</dd></div>
                    <div><dt class="text-gray-600">Raktár</dt><dd>{{ $relocation->warehouse_name }}</dd></div>
                    <div><dt class="text-gray-600">Végrehajtó</dt><dd>{{ $relocation->actor_name }}</dd></div>
                    <div><dt class="text-gray-600">Időpont</dt><dd><time datetime="{{ $relocation->relocated_at->toISOString() }}">{{ $relocation->relocated_at->timezone('Europe/Budapest')->format('Y. m. d. H:i') }}</time></dd></div>
                    <div><dt class="text-gray-600">Áthelyezve</dt><dd class="font-semibold">{{ $pieces($relocation->quantity) }}</dd></div>
                    <div><dt class="text-gray-600">Raktári készletváltozás</dt><dd class="font-semibold text-emerald-800">0 db</dd></div>
                </dl>
                @if($relocation->reason !== null)<div class="border-t border-gray-100 pt-3 text-sm"><h3 class="font-medium">Indok</h3><p class="whitespace-pre-wrap">{{ $relocation->reason }}</p></div>@endif
                @if($relocation->note !== null)<div class="border-t border-gray-100 pt-3 text-sm"><h3 class="font-medium">Megjegyzés</h3><p class="whitespace-pre-wrap">{{ $relocation->note }}</p></div>@endif
            </section>
            <section aria-labelledby="relocation-route" class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                <h2 id="relocation-route" class="font-semibold text-gray-900">Forrás- és célrekesz</h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    @foreach(['from' => 'Forrásrekesz', 'to' => 'Célrekesz'] as $side => $title)
                        @php($location = $locations[$relocation->{$side.'_location_id'}] ?? null)
                        <div class="min-w-0 rounded-md bg-gray-50 p-3">
                            <dt class="text-gray-600">{{ $title }}</dt>
                            <dd class="font-semibold">{{ $relocation->{$side.'_location_code'} }}</dd>
                            @if($relocation->{$side.'_location_name'} !== $relocation->{$side.'_location_code'})
                                <dd class="text-gray-700">{{ $relocation->{$side.'_location_name'} }}</dd>
                            @endif
                            <dd class="mt-2 font-medium">{{ $pieces($balances[$side.'_before']) }} → {{ $pieces($balances[$side.'_after']) }}</dd>
                            @if($location)
                                <dd><a href="{{ $location['view_url'] }}" target="_blank" rel="noopener" class="mt-2 inline-flex min-h-8 items-center font-medium text-indigo-700 underline">
                                    {{ $location['floor_plan_url'] ? 'Megtekintés az alaprajzon' : 'Rekesz adatlapja' }}<span class="sr-only"> (új lapon)</span></a></dd>
                            @endif
                        </div>
                    @endforeach
                </dl>
            </section>
            <section aria-labelledby="relocation-cargo-audit" class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4 sm:p-5 lg:col-span-2">
                <h2 id="relocation-cargo-audit" class="font-semibold text-gray-900">Rakomány és helyszíni ellenőrzés</h2>
                <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-gray-600">Bruttó tömeg</dt><dd>{{ \App\Models\WarehouseLocation::formatWeight($relocation->cargo_weight_grams) }} kg · {{ $relocation->load_source === 'recorded' ? 'Nyilvántartott' : 'Mért' }}</dd></div>
                    <div><dt class="text-gray-600">Raklaphelyigény</dt><dd>{{ $relocation->pallet_slots === 0 ? '0 · Nem raklapos' : $relocation->pallet_slots }}</dd></div>
                    <div><dt class="text-gray-600">Külső méretek (szélesség × mélység × magasság)</dt><dd>{{ $relocation->dimensions_cm ? implode(' × ', array_map(fn ($axis) => $relocation->dimensions_cm[$axis] ?? '—', ['width', 'depth', 'height'])).' cm' : 'Nincs megadva' }}</dd></div>
                    <div><dt class="text-gray-600">Forrásterhelés helyszíni ellenőrzése a mozgatás előtt</dt><dd>{{ $relocation->source_load_verified ? 'Megerősítve' : 'Nem volt szükséges' }}</dd></div>
                </dl>
                @include('warehouse-relocations.checks', ['phase' => 'audit'])
            </section>
        </div>
    </div>
</x-warehouse-layout>
