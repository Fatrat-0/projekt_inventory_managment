@php($value = $partner->{$field})
@if(blank($value))
    <span class="text-gray-400">Nincs megadva</span>
@elseif($field === 'email')
    <a href="mailto:{{ rawurlencode($value) }}" class="break-all text-indigo-600 hover:underline">{{ $value }}</a>
@else
    <span class="break-all">{{ $value }}</span>
@endif
