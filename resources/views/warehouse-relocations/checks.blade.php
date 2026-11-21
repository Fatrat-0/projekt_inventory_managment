@php
    $targetStates = [
        'verified' => 'Méret- és kapacitásadatok alapján ellenőrzött',
        'site_verified' => 'Helyszíni ellenőrzéssel igazolt',
        'blocked' => 'A célrekesz nem alkalmas',
        'unknown' => 'Az elférés még nem igazolt',
    ];
    $remainingCheck = 'Utólagos terhelésellenőrzés szükséges';
    $remainingOk = 'Ellenőrzött, nincs további teendő';
    $remainingUnknown = 'A mennyiség ellenőrzése után látható';
@endphp
<dl class="grid min-w-0 gap-3 rounded-md bg-gray-50 p-3 text-sm {{ $phase === 'draft' ? '' : 'sm:grid-cols-2' }}"
    @if($phase === 'draft') aria-live="polite" @endif data-relocation-checks>
    <div class="min-w-0">
        <dt class="text-xs font-medium text-gray-600">Célrekesz elférése</dt>
        @if($phase === 'audit')
            <dd class="font-medium text-emerald-800" data-relocation-target-check>{{ $targetStates[$relocation->geometry_status] ?? $targetStates['unknown'] }}</dd>
        @else
            <dd class="font-medium" data-relocation-target-check :class="targetCheck === 'blocked' ? 'text-red-800' : (targetCheck === 'unknown' ? 'text-gray-700' : 'text-emerald-800')"
                x-text="@js($targetStates)[targetCheck]"></dd>
        @endif
    </div>
    <div class="min-w-0">
        <dt class="text-xs font-medium text-gray-600">Forrásrekesz fennmaradó terhelése</dt>
        @if($phase === 'audit')
            @php($needsCheck = collect($relocation->source_allocations)->contains('remaining_load_requires_verification', true))
            <dd class="font-medium {{ $needsCheck ? 'text-amber-900' : 'text-emerald-800' }}" data-relocation-source-check>{{ $needsCheck ? $remainingCheck : $remainingOk }}</dd>
        @else
            <dd class="font-medium" data-relocation-source-check :class="sourceCheck === true ? 'text-amber-900' : (sourceCheck === false ? 'text-emerald-800' : 'text-gray-700')"
                x-text="sourceCheck === null ? @js($remainingUnknown) : (sourceCheck ? @js($remainingCheck) : @js($remainingOk))"></dd>
        @endif
        <dd class="text-xs text-gray-600" @if($phase !== 'audit') x-show="sourceCheck !== null" @endif>Az áthelyezésben érintett forrástételekre vonatkozik.</dd>
        @if($phase !== 'audit')
            <dd x-show="sourceCheck" class="mt-1 text-xs text-gray-700">A korábbi foglaltság az újramérésig megmarad.</dd>
        @endif
    </div>
</dl>
