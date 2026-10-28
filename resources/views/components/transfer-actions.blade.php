@props(['transfer', 'context' => ''])
@if($transfer->shipmentItem)
    <a class="text-indigo-600 hover:underline" href="{{ route('shipments.show', $transfer->shipmentItem->stop->shipment) }}">Szállítmány megnyitása</a>
@elseif($transfer->status === 'pending')
    <div class="flex flex-wrap gap-2">
        @foreach(['complete' => ['Átvétel', 'Az áru ténylegesen megérkezett a célraktárba?'], 'cancel' => ['Visszavonás', 'Az áru visszakerült a forrásraktárba? A visszavonás oda írja jóvá a készletet.']] as $action => [$label, $message])
            <form method="POST" action="{{ route('transfers.'.$action, $transfer) }}" onsubmit="return confirm(@js($message))" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); } else if (!$event.defaultPrevented) { submitting = true; }">
                @csrf @method('PATCH')<input type="hidden" name="context" value="{{ $context }}">
                <button :disabled="submitting" class="rounded-md border px-2.5 py-1.5 text-xs font-medium disabled:opacity-50 {{ $action === 'complete' ? 'border-green-200 text-green-800 hover:bg-green-50' : 'border-red-200 text-red-700 hover:bg-red-50' }}">{{ $label }}</button>
            </form>
        @endforeach
    </div>
@endif
