<section aria-label="Visszáru minősítése" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5 text-sm">
    <div><h3 class="font-semibold text-gray-900">Visszáru minősítése</h3>
        @if($movement->quarantined_quantity > 0)
            <p class="mt-1 text-gray-600">Még elkülönítve: <strong>{{ number_format($qualityRemaining, 0, ',', ' ') }} db</strong> · Eredetileg elkülönítve: {{ number_format($movement->quarantined_quantity, 0, ',', ' ') }} db</p>
        @else
            <p class="mt-1 text-gray-600">Ez a korábbi visszáru szabad készletként került bevételezésre; utólag nem helyeztük elkülönítésbe.</p>
        @endif
    </div>
    @if($qualityRemaining > 0)
        <p class="rounded-md bg-amber-50 p-3 text-amber-900">Az elkülönített áru nem adható ki. Felszabadításkor kiadhatóvá válik; selejtezéskor a fizikai készletből is levonjuk. A rögzített minősítés nem szerkeszthető.</p>
        <form method="POST" action="{{ route('movements.quality.store', $movement) }}" class="space-y-4"
            x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault() } else { submitting = true }" @pageshow.window="if ($event.persisted) submitting = false">
            @csrf
            <input type="hidden" name="request_key" value="{{ $qualityKey }}"><input type="hidden" name="context" value="{{ $context }}">
            @error('request_key')<a href="{{ route('movements.show', ['movement' => $movement, 'context' => $context]) }}" class="block text-indigo-600 underline">Új minősítés indítása</a>@enderror
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label for="quality-action" class="mb-1 block font-medium text-gray-700">Művelet</label>
                    <select id="quality-action" name="action" required class="w-full rounded-md border-gray-300" @error('action') aria-invalid="true" @enderror>
                        <option value="" disabled @selected(!in_array(old('action'), ['release', 'scrap'], true))>Válassz műveletet…</option>
                        <option value="release" @selected(old('action') === 'release')>Felszabadítás</option><option value="scrap" @selected(old('action') === 'scrap')>Selejtezés</option>
                    </select>@error('action')<p class="mt-1 text-red-700">{{ $message }}</p>@enderror
                </div>
                <div><label for="quality-quantity" class="mb-1 block font-medium text-gray-700">Mennyiség</label>
                    <input id="quality-quantity" name="quantity" type="number" min="1" max="{{ $qualityRemaining }}" step="1" required value="{{ is_scalar(old('quantity', $qualityRemaining)) ? old('quantity', $qualityRemaining) : '' }}" class="w-full rounded-md border-gray-300" @error('quantity') aria-invalid="true" @enderror>
                    @error('quantity')<p class="mt-1 text-red-700">{{ $message }}</p>@enderror
                </div>
            </div>
            <div><label for="quality-reason" class="mb-1 block font-medium text-gray-700">Minősítés indoka</label>
                <textarea id="quality-reason" name="reason" rows="3" required maxlength="500" class="w-full rounded-md border-gray-300" @error('reason') aria-invalid="true" @enderror>{{ is_scalar(old('reason')) ? old('reason') : '' }}</textarea>
                @error('reason')<p class="mt-1 text-red-700">{{ $message }}</p>@enderror
            </div>
            <button type="submit" :disabled="submitting" x-text="submitting ? 'Mentés…' : 'Minősítés rögzítése'" class="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white disabled:opacity-50">Minősítés rögzítése</button>
        </form>
    @elseif($movement->quarantined_quantity > 0)
        <p class="text-gray-600">A teljes visszáru minősítve.</p>
    @endif
    @foreach($qualityHistory as $action)
        <article class="space-y-1 rounded-md border border-gray-200 p-3">
            <h4 class="font-semibold {{ $action->action === 'scrap' ? 'text-red-800' : 'text-green-800' }}">{{ $action->action_label }} · {{ number_format($action->quantity, 0, ',', ' ') }} db</h4>
            <p class="text-xs text-gray-500">{{ $action->created_at->format('Y.m.d H:i') }} · {{ $action->user->name }}</p>
            <p class="whitespace-pre-wrap break-words">{{ $action->reason }}</p>
            <p class="text-xs text-gray-600">Fizikai készlet: {{ $action->stock_before }} → {{ $action->stock_after }} db · Elkülönítve: {{ $action->quarantined_before }} → {{ $action->quarantined_after }} db</p>
            @if($action->movement_id)<a href="{{ route('movements.show', ['movement' => $action->movement_id, 'context' => $context]) }}" class="block text-indigo-600 underline">{{ $action->movement->movement_number }} · Selejtezési készletmozgás</a>@endif
        </article>
    @endforeach
    {{ $qualityHistory->links('pagination.inventory') }}
</section>
