@php
    $editingMinimum = is_scalar(old('_stock_minimum_id')) && (string)old('_stock_minimum_id') === (string)$stock->id;
    $minimumDraft = $editingMinimum && is_scalar(old('min_quantity')) ? old('min_quantity') : $stock->min_quantity;
@endphp
<div data-stock-minimum="{{ $stock->id }}" class="min-w-0 text-sm">
    <span class="font-semibold tabular-nums text-gray-900">{{ number_format($stock->min_quantity, 0, ',', ' ') }} db</span>
    @if($stock->available_quantity < $stock->min_quantity)<span class="mt-1 block text-xs font-medium text-amber-800">Minimum alatt</span>@endif
    @if($canManage)
        <details class="mt-1" @if($editingMinimum) open @endif>
            <summary class="cursor-pointer py-1 text-xs font-medium text-indigo-700">Módosítás</summary>
            <form method="POST" action="{{ route('stock.minimum', $stock) }}" class="mt-2 space-y-2" x-data="{ submitting: false }" @submit="if (submitting) $event.preventDefault(); else submitting = true" @pageshow.window="submitting = false">
                @csrf @method('PATCH')
                <input type="hidden" name="_stock_minimum_id" value="{{ $stock->id }}"><input type="hidden" name="expected_minimum" value="{{ $stock->min_quantity }}"><input type="hidden" name="context" value="{{ $listContext }}">
                <label class="dashboard-text block text-left text-xs text-gray-600">Helyi minimum ({{ $stock->product->sku }})<input type="number" name="min_quantity" value="{{ $minimumDraft }}" min="0" max="2147483647" step="1" required class="mt-1 block w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
                <button :disabled="submitting" class="min-h-10 rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Mentés</button>
            </form>
        </details>
    @endif
</div>
