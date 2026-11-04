<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-6 sm:px-6">
        <h2 class="text-xl font-bold text-gray-900">Selejtezés megerősítése</h2>
        <p class="rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-900">A megerősítés a fizikai és az elkülönített készletből is levonja az alábbi mennyiséget. A rögzített művelet nem szerkeszthető.</p>
        <dl class="grid gap-4 rounded-lg border border-gray-200 bg-white p-5 text-sm sm:grid-cols-2">
            <div class="min-w-0"><dt class="text-gray-500">Termék</dt><dd class="mt-1 break-words font-semibold">{{ $movement->product->product_name }}</dd><dd class="mt-1 break-all font-mono text-xs">{{ $movement->product->sku }}</dd></div>
            <div class="min-w-0"><dt class="text-gray-500">Raktár</dt><dd class="mt-1 break-words">{{ $movement->warehouse->warehouse_name }}</dd></div>
            <div><dt class="text-gray-500">Selejtezendő mennyiség</dt><dd class="mt-1 font-semibold text-red-800">{{ number_format((int)$data['quantity'], 0, ',', ' ') }} db</dd></div>
            <div><dt class="text-gray-500">Visszáru</dt><dd class="mt-1">{{ $movement->movement_number }}</dd></div>
            <div class="min-w-0 sm:col-span-2"><dt class="text-gray-500">Minősítés indoka</dt><dd class="mt-1 whitespace-pre-wrap break-words">{{ $data['reason'] }}</dd></div>
        </dl>
        <form method="POST" action="{{ route('movements.quality.store', $movement) }}" class="flex flex-wrap justify-end gap-3" x-data="{ submitting: false }" @submit="if (submitting) $event.preventDefault(); else submitting = true" @pageshow.window="if ($event.persisted) submitting = false">
            @csrf
            @foreach(['request_key', 'action', 'quantity', 'reason'] as $field)<input type="hidden" name="{{ $field }}" value="{{ $data[$field] }}">@endforeach
            <input type="hidden" name="context" value="{{ $context }}"><input type="hidden" name="confirmation" value="{{ $confirmation }}">
            <button type="submit" name="edit" value="1" formnovalidate class="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700">Vissza a szerkesztéshez</button>
            <button type="submit" :disabled="submitting" class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Selejtezés véglegesítése</button>
        </form>
    </div>
</x-app-layout>
