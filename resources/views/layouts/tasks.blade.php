@if($appTasks)
    <div x-data="rowActions" @resize.window="position()" @scroll.window.capture="position()">
        <button type="button" x-ref="trigger" popovertarget="app-tasks-menu" @click="$dispatch('app-topbar-menu')" @keydown.arrow-down.prevent="keyboardOpen()" @keydown.arrow-up.prevent="keyboardOpen(true)" aria-haspopup="menu" :aria-expanded="open" aria-controls="app-tasks-menu" aria-label="Nyitott feladatok, {{ $appTasks['total'] }} tétel" title="Nyitott feladatok" class="relative inline-flex h-11 w-11 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-indigo-500">
            <x-icon name="tasks" class="h-5 w-5" />
            @if($appTasks['total'] > 0)<span aria-hidden="true" class="absolute right-0 top-0 rounded-full bg-indigo-600 px-1 text-[10px] font-semibold text-white">{{ $appTasks['total'] > 99 ? '99+' : $appTasks['total'] }}</span>@endif
        </button>
        <div id="app-tasks-menu" x-ref="menu" popover="auto" role="menu" aria-label="Nyitott feladatok" @toggle="changed($event)" @keydown="navigate($event)" @focusout="leave($event)" class="app-tasks-menu fixed max-h-[calc(100dvh-2rem)] w-72 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg border border-gray-200 bg-white p-2 shadow-xl">
            <p class="px-3 py-2 text-xs font-semibold uppercase text-gray-500">Nyitott feladatok</p>
            <a role="menuitem" href="{{ route('transfers.index', ['status' => 'pending', 'sort_by' => 'created_at', 'sort_dir' => 'asc']) }}" class="block rounded-md px-3 py-3 text-sm hover:bg-indigo-50 focus-visible:ring-2 focus-visible:ring-indigo-500"><span class="block font-semibold text-gray-900">Átvételre váró átmozgatások</span><span class="text-xs text-gray-500">{{ number_format($appTasks['transfers'], 0, ',', ' ') }} tétel</span></a>
            <a role="menuitem" href="{{ route('quality-returns.index', ['status' => 'open']) }}" class="block rounded-md px-3 py-3 text-sm hover:bg-indigo-50 focus-visible:ring-2 focus-visible:ring-indigo-500"><span class="block font-semibold text-gray-900">Minősítésre váró visszáruk</span><span class="text-xs text-gray-500">{{ number_format($appTasks['returns'], 0, ',', ' ') }} tétel · {{ number_format($appTasks['returnQuantity'], 0, ',', ' ') }} db</span></a>
            <a role="menuitem" href="{{ route('dashboard') }}" class="block rounded-md border-t border-gray-100 px-3 py-3 text-sm font-medium text-indigo-700 hover:bg-indigo-50">Vezérlőpult megnyitása →</a>
            <p class="px-3 py-2 text-xs text-gray-400">Az oldal betöltésekor aktuális állapot.</p>
        </div>
    </div>
@endif
