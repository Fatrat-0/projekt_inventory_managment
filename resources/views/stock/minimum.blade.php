@php
    $editingMinimum = is_scalar(old('_stock_minimum_id')) && (string)old('_stock_minimum_id') === (string)$stock->id;
    $minimumDraft = $editingMinimum && is_scalar(old('min_quantity')) ? old('min_quantity') : $stock->min_quantity;
    $editingMinimum = $editingMinimum || (is_scalar(request('edit_minimum')) && (string)request('edit_minimum') === (string)$stock->id);
@endphp
<div id="stock-minimum-{{ $stock->id }}-{{ $variant }}" data-stock-minimum="{{ $stock->id }}" class="min-w-0 text-sm" x-init="registerMinimum({{ $stock->id }}, {{ \Illuminate\Support\Js::from((string)$minimumDraft) }}, {{ $editingMinimum ? 'true' : 'false' }})" @stock-minimum-edit.window="if ($event.detail.id === {{ $stock->id }} && $event.detail.variant === '{{ $variant }}') { minimumEdits[{{ $stock->id }}].editing = true; $nextTick(() => { const input = $el.querySelector('input[name=min_quantity]'); input?.focus({ preventScroll: true }); input?.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }); }">
    <span class="font-semibold tabular-nums text-gray-900">{{ number_format($stock->min_quantity, 0, ',', ' ') }} db</span>
    @if($stock->available_quantity < $stock->min_quantity)<span class="mt-1 block text-xs font-medium text-amber-800">Minimum alatt</span>@endif
    @if($canManage)
        <div class="mt-2" x-show="minimumEdits[{{ $stock->id }}].editing" @if(!$editingMinimum) style="display:none;" @endif>
            <form method="POST" action="{{ route('stock.minimum', $stock) }}" class="mt-2 space-y-2" x-data="{ submitting: false }" @submit="if (submitting) $event.preventDefault(); else submitting = true" @pageshow.window="submitting = false">
                @csrf @method('PATCH')
                <input type="hidden" name="_stock_minimum_id" value="{{ $stock->id }}"><input type="hidden" name="expected_minimum" value="{{ $stock->min_quantity }}"><input type="hidden" name="context" value="{{ $listContext }}">
                <label class="dashboard-text block text-left text-xs text-gray-600">Helyi minimum ({{ $stock->product->sku }})<input type="number" name="min_quantity" x-model="minimumEdits[{{ $stock->id }}].value" value="{{ $minimumDraft }}" min="0" max="2147483647" step="1" required class="mt-1 block w-full min-w-0 rounded-md border-gray-300 text-sm"></label>
                <div class="flex flex-wrap items-center gap-2"><button :disabled="submitting" class="min-h-10 rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Mentés</button><a href="{{ route('stock.index', $filters + ['page' => $stocks->currentPage()]) }}" @click.prevent="minimumEdits[{{ $stock->id }}].value = '{{ $stock->min_quantity }}'; minimumEdits[{{ $stock->id }}].editing = false; $nextTick(() => $el.closest('[data-stock-row]')?.querySelector('[popovertarget]')?.focus({ preventScroll: true }))" class="text-xs text-gray-600 underline">Mégse</a></div>
            </form>
        </div>
    @endif
</div>
