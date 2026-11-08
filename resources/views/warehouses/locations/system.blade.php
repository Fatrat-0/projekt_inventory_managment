@php
    use App\Support\WarehouseStorageOverview as Storage;
    $floor = $record->kind === 'floor';
    $title = $floor ? 'Tárolóterület' : 'Állvány elölnézete';
    $kg = \App\Models\WarehouseLocation::formatWeight(...);
    $tones = ['available' => 'border-emerald-300 bg-emerald-50 text-emerald-900', 'near' => 'border-amber-300 bg-amber-50 text-amber-900',
        'full' => 'border-rose-300 bg-rose-50 text-rose-900', 'blocked' => 'border-slate-300 bg-slate-100 text-slate-800'];
@endphp
<x-warehouse-layout :$warehouse :context="$params['context'] ?? ''" :detail-context="$params['detail_context'] ?? ''">
    <div class="app-page space-y-4 py-4">
        <x-page-toolbar :title="$title" :description="$warehouse->warehouse_name.' · '.$overview['zone'].' · '.$overview['code'].' · '.$record->name">
            <a href="{{ route('warehouses.floor-plan.show', $params + ['system' => $record->id]) }}" class="inline-flex min-h-11 items-center px-2 text-sm font-medium text-indigo-700 hover:underline">← Raktári tervrajz</a>
            <a href="{{ route('warehouses.locations.index', $params) }}" class="inline-flex min-h-11 items-center px-2 text-sm font-medium text-indigo-700 hover:underline">Tárhelyek és kapacitás</a>
        </x-page-toolbar>
        <x-operation-feedback />

        <section aria-label="A tárolórendszer kapacitása" class="space-y-2 rounded-lg border border-gray-200 bg-white p-4">
            <p class="text-sm text-gray-700">{{ $overview['kindLabel'] }} · {{ $record->frontWidth() }} × {{ $record->depth() }}@if($record->height_cm !== null) × {{ $record->height_cm }}@endif cm</p>
            @include('warehouses.locations.capacity-summary', ['data' => $overview])
        </section>

        <div class="grid min-w-0 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,26rem)]">
            <section aria-labelledby="rack-grid-title" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-200 p-4">
                    <h2 id="rack-grid-title" class="font-semibold">{{ $floor ? 'Padlóhelyek' : 'Szintek és rekeszek' }}</h2>
                    <p class="text-xs text-gray-600">Válassz egy tárolóhelyet a részleteihez.</p>
                </div>
                <div tabindex="0" aria-label="Tárolóhelyek vizuális elrendezése" class="overflow-x-auto bg-slate-50 p-3 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                    <div class="min-w-max space-y-2">
                        @foreach($overview['levels']->reverse() as $level)
                            <section data-rack-level aria-label="{{ $level['name'] }}" class="rounded-md border border-slate-300 bg-white p-2">
                                <h3 class="text-xs font-semibold text-gray-800">{{ $level['name'] }}</h3>
                                <p class="mb-2 text-xs text-gray-600">{{ $kg($level['occupied']) }} kg tárolt · {{ $kg($level['reserved']) }} kg lefoglalt · {{ $kg($level['free']) }} kg szabad / {{ $kg($level['limit']) }} kg</p>
                                <div class="grid gap-2" style="grid-template-columns: repeat({{ max(1, $level['bins']->count()) }}, minmax(4.75rem, 1fr))">
                                    @foreach($level['bins'] as $cell)
                                        @php
                                            $tone = Storage::tone($cell);
                                        @endphp
                                        <a data-rack-bin href="{{ route('warehouses.storage-systems.show', $systemParams + ['bin' => $cell['id']]) }}#rack-bin"
                                            @if($bin?->id === $cell['id']) aria-current="true" @endif
                                            @class(['min-h-16 rounded border p-2 text-left text-xs focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-700', $tones[$tone],
                                                'ring-2 ring-indigo-600' => $bin?->id === $cell['id']])>
                                            <strong class="block break-all">{{ $cell['code'] }}</strong>
                                            <span class="block">{{ Storage::STATES[$tone] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
                <ul aria-label="Rekeszállapotok" class="flex flex-wrap gap-2 border-t border-gray-200 p-3 text-xs">
                    @foreach(Storage::STATES as $tone => $label)
                        <li class="rounded border px-2 py-1 {{ $tones[$tone] }}">{{ $label }}</li>
                    @endforeach
                </ul>
            </section>

            @if($bin && $capacity)
                @php
                    $level = $overview['levels']->first(fn ($row) => $row['bins']->contains('id', $bin->id));
                    $defaults = Storage::defaultBinDimensions($record, $level['bins']->count(), $overview['levels']->count());
                    $tone = Storage::tone($capacity);
                    $stored = $products->get($bin->id, collect());
                @endphp
                <section id="rack-bin" aria-labelledby="rack-bin-title" class="min-w-0 space-y-3 rounded-lg border border-gray-200 bg-white p-4">
                    <h2 id="rack-bin-title" class="break-words font-semibold">{{ $bin->code }} · {{ $bin->name }}</h2>
                    <p class="text-sm text-gray-700">{{ Storage::STATES[$tone] }} · {{ $bin->width_cm ?? $defaults['width_cm'] }} × {{ $bin->depth_cm ?? $defaults['depth_cm'] }} × {{ $bin->height_cm ?? $defaults['height_cm'] }} cm</p>
                    @include('warehouses.locations.capacity-summary', ['data' => $capacity])
                    <div class="border-t border-gray-200 pt-3">
                        <h3 class="text-sm font-semibold">Megengedett termékkategóriák</h3>
                        <p class="break-words text-sm text-gray-700">{{ $bin->allowedCategories->isEmpty() ? 'Minden kategória' : $bin->allowedCategories->pluck('category_name')->sort()->implode(', ') }}</p>
                    </div>
                    @include('warehouses.locations.stored-products')
                    <a href="{{ route('warehouses.locations.show', $params + ['warehouseLocation' => $bin]) }}" class="inline-flex min-h-10 items-center text-sm font-medium text-indigo-700 hover:underline">Tárhely adatlapja</a>
                    @if($canManage)
                        <details @if($errors->any()) open @endif class="rounded border border-gray-200 p-3">
                            <summary class="cursor-pointer text-sm font-semibold">Rekesz beállításainak szerkesztése</summary>
                            @include('warehouses.locations.bin-form')
                        </details>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-warehouse-layout>
