@props(['label', 'shortLabel', 'icon', 'href', 'current' => false])

<a href="{{ $href }}" aria-label="{{ $label }}" title="{{ $label }}" @if($current) aria-current="page" @endif
    {{ $attributes->class(['inline-flex min-h-11 min-w-11 items-center justify-center gap-1.5 rounded-md px-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 md:px-3', 'bg-indigo-50 text-indigo-700' => $current, 'text-gray-700 hover:bg-gray-100' => !$current]) }}>
    <x-icon :name="$icon" class="h-5 w-5" />
    <span aria-hidden="true" class="lg:hidden">{{ $shortLabel }}</span>
    <span aria-hidden="true" class="hidden lg:inline">{{ $label }}</span>
</a>
