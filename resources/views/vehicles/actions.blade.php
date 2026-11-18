@php($menuId = '`vehicle-actions-${vehicle.id}-'.$variant.'`')
<div x-data="rowActions" class="flex shrink-0 justify-end" @scroll.window.capture="if (open) position()">
    <button type="button" x-ref="trigger" :popovertarget="{{ $menuId }}" :aria-controls="{{ $menuId }}"
            aria-haspopup="menu" :aria-expanded="open" :aria-label="`Műveletek: ${vehicle.name}${vehicle.registration_number ? ' · ' + vehicle.registration_number : ''}`"
            title="Műveletek" @keydown.arrow-down.prevent="keyboardOpen()" @keydown.arrow-up.prevent="keyboardOpen(true)"
            class="inline-flex h-11 w-11 items-center justify-center rounded-md text-xl font-semibold text-gray-500 hover:bg-indigo-50 hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500">
        <span aria-hidden="true">⋮</span>
    </button>
    <div :id="{{ $menuId }}" x-ref="menu" popover="auto" role="menu" aria-label="Jármű műveletei"
         @toggle="changed($event)" @keydown="navigate($event)" @focusout="leave($event)"
         class="app-action-menu fixed max-h-[calc(100dvh-2rem)] w-64 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg border border-gray-200 bg-white p-1 text-left text-sm text-gray-700 shadow-xl">
        <button type="button" role="menuitem"
                @click="$refs.menu.hidePopover(); $refs.trigger.focus({ preventScroll: true }); openRelated(vehicle)"
                class="block w-full rounded-md px-3 py-3 text-left hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none">Kapcsolódó fuvarok</button>
        @if ($canManageVehicles)
            <button type="button" role="menuitem"
                    @click="$refs.menu.hidePopover(); $refs.trigger.focus({ preventScroll: true }); openEditor(vehicle)"
                    class="block w-full rounded-md px-3 py-3 text-left hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none">Szerkesztés</button>
            <button type="button" role="menuitem"
                    @click="$refs.menu.hidePopover(); $refs.trigger.focus({ preventScroll: true }); openTransition(vehicle)"
                    :class="{ 'text-red-800': vehicle.active }"
                    class="block w-full rounded-md px-3 py-3 text-left hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none"
                    x-text="vehicle.active ? 'Inaktiválás' : 'Újraaktiválás'"></button>
        @endif
    </div>
</div>
