<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-6 sm:px-6">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">{{ $fromDetails ? '← Raktáradatlap' : '← Raktárak' }}</a>
        <div><h2 class="text-xl font-bold text-gray-900">{{ $warehouse ? 'Raktár szerkesztése' : 'Új raktár' }}</h2><p class="mt-1 text-sm text-gray-500">A raktár neve és címe minden készletkezelési műveletnél azonosítja a helyszínt.</p></div>
        <x-operation-feedback />
        <form method="POST" action="{{ $warehouse ? route('warehouses.update', $warehouse) : route('warehouses.store') }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); } else { submitting = true; }" class="space-y-5 rounded-lg border border-gray-200 bg-white p-5">
            @csrf
            @if($warehouse) @method('PUT') @endif
            <input type="hidden" name="context" value="{{ $context }}">
            @if($fromDetails)<input type="hidden" name="from" value="details"><input type="hidden" name="detail_context" value="{{ $detailContext }}">@endif
            <div>
                <label for="warehouse-name" class="mb-1 block text-sm font-medium text-gray-700">Raktár neve</label>
                <input id="warehouse-name" name="warehouse_name" value="{{ is_scalar(old('warehouse_name', $warehouse?->warehouse_name ?? '')) ? old('warehouse_name', $warehouse?->warehouse_name ?? '') : '' }}" maxlength="100" required autofocus aria-describedby="warehouse-name-error" @error('warehouse_name') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                <p id="warehouse-name-error" class="mt-1 text-sm text-red-700">@error('warehouse_name'){{ $message }}@enderror</p>
            </div>
            <div>
                <label for="warehouse-location" class="mb-1 block text-sm font-medium text-gray-700">Cím / lokáció</label>
                <textarea id="warehouse-location" name="location" rows="3" maxlength="255" required aria-describedby="warehouse-location-error" @error('location') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">{{ is_scalar(old('location', $warehouse?->location ?? '')) ? old('location', $warehouse?->location ?? '') : '' }}</textarea>
                <p id="warehouse-location-error" class="mt-1 text-sm text-red-700">@error('location'){{ $message }}@enderror</p>
            </div>
            @if($warehouse)<p class="text-xs text-gray-500">A módosítás a meglévő raktár adatait frissíti; a készletek és bizonylatok kapcsolatai megmaradnak.</p>@endif
            <div class="flex flex-wrap items-center justify-end gap-4 border-t border-gray-100 pt-4">
                <a href="{{ $returnUrl }}" class="text-sm text-gray-600">Mégse</a>
                <button :disabled="submitting" x-text="submitting ? 'Mentés…' : 'Raktár mentése'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Raktár mentése</button>
            </div>
        </form>
    </div>
</x-app-layout>
