@props(['variant' => 'default'])
<button {{ $attributes->merge(['type' => 'button', 'class' => $variant === 'neutral'
    ? 'inline-flex min-h-10 items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2'
    : 'inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
