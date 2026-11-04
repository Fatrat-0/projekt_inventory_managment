@if($movement->isReturn())
    <section aria-label="Visszáru kapcsolata" class="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
        <h3 class="font-semibold">Visszáru</h3>
        <p>Eredeti mozgás: <a href="{{ route('movements.show', ['movement' => $movement->return_of_id, 'context' => $context]) }}" class="font-medium underline">{{ $movement->original_movement_number }}</a></p>
        <p class="break-words whitespace-pre-wrap">Indok: {{ $movement->return_reason }}</p>
    </section>
@else
    <section aria-label="Visszáruk" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h3 class="font-semibold text-gray-900">Visszáruk</h3><p class="mt-1 text-gray-600">Visszaküldve: {{ number_format($returned, 0, ',', ' ') }} db · Még visszaküldhető: {{ number_format($remaining, 0, ',', ' ') }} db</p></div>
            @if($remaining > 0)<a href="{{ route('movements.return.create', ['movement' => $movement, 'context' => $context]) }}" class="rounded-md bg-indigo-600 px-3 py-2 font-semibold text-white">Visszáru rögzítése</a>
            @else<p class="text-gray-500">Teljes mennyiség visszaküldve.</p>@endif
        </div>
        @foreach($returnHistory as $return)
            <article class="space-y-1 rounded-md border border-gray-200 p-3">
                <a href="{{ route('movements.show', ['movement' => $return, 'context' => $context]) }}" class="font-medium text-indigo-600 underline">{{ $return->movement_number }} · {{ number_format($return->quantity, 0, ',', ' ') }} db</a>
                <p class="text-xs text-gray-500">{{ $return->created_at->format('Y.m.d H:i') }} · {{ $return->user->name }}</p>
                <p class="break-words whitespace-pre-wrap">{{ $return->return_reason }}</p>
            </article>
        @endforeach
        {{ $returnHistory->links('pagination.inventory') }}
    </section>
@endif
