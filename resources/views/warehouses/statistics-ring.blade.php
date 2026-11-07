@php($angle = -90)
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" class="h-full w-full" fill="none" stroke-width="4.1">
    <circle cx="20" cy="20" r="15.9154943092" stroke="#f3f4f6" />
    @if($total > 0)
        @foreach($parts as $i => $amount)
            @continue($amount === 0)
            @php($share = 100 * $amount / $total)
            <circle cx="20" cy="20" r="15.9154943092" transform="rotate({{ $angle }} 20 20)" stroke="{{ $colors[$i] }}" stroke-dasharray="{{ $share }},100" />
            @php($angle += 3.6 * $share)
        @endforeach
    @endif
</svg>
