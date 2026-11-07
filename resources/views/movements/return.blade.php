<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-4 px-4 py-4 sm:px-6">
        <a href="{{ $returnUrl }}" class="text-sm text-gray-500 hover:text-indigo-600">← Eredeti készletmozgás</a>
        <div>
            <h1 class="text-xl font-bold text-gray-900">Visszáru rögzítése</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $movement->type === 'out' ? 'Visszavétel a raktárba' : 'Visszaküldés a raktárból' }} · {{ $movement->movement_number }}</p>
        </div>
        <x-operation-feedback />
        <dl class="grid gap-4 rounded-lg border border-gray-200 bg-white p-5 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">Termék</dt><dd class="mt-1 break-words font-semibold">{{ $movement->product->product_name }}</dd><dd class="mt-1 break-all text-xs text-gray-500">{{ $movement->product->sku }}</dd></div>
            <div><dt class="text-gray-500">Raktár</dt><dd class="mt-1 break-words">{{ $movement->warehouse->warehouse_name }}</dd></div>
            <div><dt class="text-gray-500">Partner az eredeti mozgáson</dt><dd class="mt-1 break-words">{{ $movement->partner_name ?? 'Nincs megadva' }}@if($movement->partner?->isArchived()) <span class="text-xs text-gray-500">· Archivált</span>@endif</dd></div>
            <div><dt class="text-gray-500">Eredeti mennyiség</dt><dd class="mt-1">{{ number_format($movement->quantity, 0, ',', ' ') }} db</dd></div>
        </dl>
        <div role="status" class="rounded-md border border-indigo-100 bg-indigo-50 p-4 text-sm text-indigo-900">
            Korábban visszaküldve: <strong>{{ number_format((int)$movement->returns_sum_quantity, 0, ',', ' ') }} db</strong> ·
            Még visszaküldhető: <strong>{{ number_format($remaining, 0, ',', ' ') }} db</strong>
        </div>
        @if($remaining === 0)
            <p class="rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700">Az eredeti mennyiséget már teljes egészében visszaküldték.</p>
        @else
            @if($movement->type === 'out')<p class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">A visszavett áru elkülönített készletbe kerül. Minősítés után felszabadítható vagy indoklással selejtezhető.</p>@endif
            <form method="POST" action="{{ route('movements.return.store', $movement) }}"
                x-data="movementForm({{ Illuminate\Support\Js::from(['endpoint' => route('movements.stock'), 'product' => (string)$movement->product_id, 'warehouse' => (string)$movement->warehouse_id, 'type' => $movement->type === 'out' ? 'in' : 'out', 'quantity' => is_scalar(old('quantity', 1)) ? old('quantity', 1) : 1]) }})"
                @submit="submit($event)" @pageshow.window="restored($event)" class="space-y-5 rounded-lg border border-gray-200 bg-white p-5 sm:p-6">
                @csrf
                <input type="hidden" name="request_key" value="{{ $requestKey }}"><input type="hidden" name="context" value="{{ $context }}">
                @error('request_key')<a href="{{ route('movements.return.create', ['movement' => $movement, 'context' => $context]) }}" class="block text-sm text-indigo-600 underline">Új visszáru-űrlap indítása</a>@enderror
                <div class="rounded-md bg-gray-50 p-3 text-sm text-gray-600">
                    <span x-text="stockText">Kiadható készlet a raktárban: {{ $stock }} db</span>
                    <button type="button" x-show="true" style="display:none" @click="loadStock()" class="ml-2 text-indigo-600 underline">Frissítés</button>
                    <p class="mt-1 text-xs">Mentéskor újra ellenőrizzük a készletet és a még visszaküldhető mennyiséget.</p>
                </div>
                <div>
                    <label for="return-quantity" class="mb-1 block text-sm font-medium text-gray-700">Visszáru mennyisége (db)</label>
                    <input id="return-quantity" name="quantity" type="number" min="1" max="{{ $remaining }}" step="1" required x-model="quantity"
                        value="{{ is_scalar(old('quantity', 1)) ? old('quantity', 1) : '' }}" aria-describedby="return-quantity-help return-quantity-error"
                        @error('quantity') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                    <p id="return-quantity-help" class="mt-1 text-xs text-gray-500">A raktári készlet ennyivel {{ $movement->type === 'out' ? 'nő' : 'csökken' }}. Legfeljebb {{ number_format($remaining, 0, ',', ' ') }} db.</p>
                    <p id="return-quantity-error" class="mt-1 text-sm text-red-700">@error('quantity'){{ $message }}@enderror</p>
                    <p x-show="type === 'out' && stock !== null && Number(quantity) > stock" style="display:none" class="mt-1 text-sm text-red-700">A visszaküldéshez nincs elegendő készlet a legutóbbi lekérdezés alapján.</p>
                </div>
                <div>
                    <label for="return-reason" class="mb-1 block text-sm font-medium text-gray-700">Visszáru indoka</label>
                    <textarea id="return-reason" name="return_reason" rows="3" maxlength="500" required aria-describedby="return-reason-error"
                        @error('return_reason') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">{{ is_scalar(old('return_reason')) ? old('return_reason') : '' }}</textarea>
                    <p id="return-reason-error" class="mt-1 text-sm text-red-700">@error('return_reason'){{ $message }}@enderror</p>
                </div>
                <div>
                    <label for="return-reference" class="mb-1 block text-sm font-medium text-gray-700">Bizonylatszám vagy megjegyzés (opcionális)</label>
                    <input id="return-reference" name="reference" maxlength="255" value="{{ is_scalar(old('reference')) ? old('reference') : '' }}"
                        aria-describedby="return-reference-error" @error('reference') aria-invalid="true" @enderror class="w-full rounded-md border-gray-300">
                    <p id="return-reference-error" class="mt-1 text-sm text-red-700">@error('reference'){{ $message }}@enderror</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-4 border-t border-gray-100 pt-4">
                    <a href="{{ $returnUrl }}" class="text-sm text-gray-600">Mégse</a>
                    <button type="submit" :disabled="submitting" x-text="submitting ? 'Mentés…' : 'Visszáru rögzítése'" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Visszáru rögzítése</button>
                </div>
            </form>
        @endif
    </div>
</x-app-layout>
