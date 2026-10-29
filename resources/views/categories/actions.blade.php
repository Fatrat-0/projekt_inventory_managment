@php($menuId = 'category-actions-'.$category->category_id.'-'.$variant)
<div x-data="rowActions" class="flex justify-end" @resize.window="if (open) position()" @scroll.window.capture="if (open) position()">
    <button type="button" x-ref="trigger" popovertarget="{{ $menuId }}" aria-controls="{{ $menuId }}" aria-haspopup="menu" :aria-expanded="open" aria-label="Műveletek: {{ $category->category_name }}" title="Műveletek" @keydown.arrow-down.prevent="keyboardOpen()" @keydown.arrow-up.prevent="keyboardOpen(true)" class="inline-flex h-9 w-9 items-center justify-center rounded-md text-xl font-semibold text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"><span aria-hidden="true">⋮</span></button>
    <div id="{{ $menuId }}" x-ref="menu" popover="auto" role="menu" aria-label="{{ $category->category_name }} műveletei" @toggle="changed($event)" @keydown="navigate($event)" @focusout="leave($event)" class="m-0 max-h-[calc(100dvh-1rem)] overflow-y-auto rounded-lg border border-gray-200 bg-white p-1 text-sm text-gray-700 shadow-xl" style="position: fixed; inset: auto; width: min(15rem, calc(100vw - 1rem));">
        <a role="menuitem" href="{{ route('categories.edit', ['category' => $category, 'context' => $context]) }}" class="block rounded-md px-3 py-2 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none">Szerkesztés</a>
        <a role="menuitem" href="{{ route('products.index', ['category_id' => $category->category_id]) }}" class="block rounded-md px-3 py-2 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none">Termékek az ágban</a>
        <div class="mt-1 border-t border-gray-100 pt-1">
        @if($category->products_count > 0 || $category->children_count > 0)
            <button type="button" role="menuitem" disabled class="w-full cursor-not-allowed rounded-md px-3 py-2 text-left text-gray-400">Törlés</button>
            <p class="px-3 pb-2 text-xs text-gray-500">Termék vagy alkategória tartozik hozzá; előbb sorold át ezeket.</p>
        @else
        <form method="POST" action="{{ route('categories.destroy', $category) }}" onsubmit="if (!confirm('Biztosan törlöd ezt a kategóriát?')) return false; this.closest('[popover]').hidePopover();">
            @csrf @method('DELETE')
            <input type="hidden" name="context" value="{{ $context }}">
            <button role="menuitem" class="w-full rounded-md px-3 py-2 text-left text-red-600 hover:bg-red-50 focus:bg-red-50 focus:outline-none">Törlés</button>
        </form>
        @endif
        </div>
    </div>
</div>
