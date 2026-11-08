<header class="sticky top-0 z-30 border-b border-gray-200 bg-white" data-app-topbar>
    <div class="flex min-h-14 flex-wrap items-center justify-between gap-2 px-4 py-1 sm:gap-3 sm:px-6">
        <div class="flex shrink-0 items-center">
            <button type="button" x-cloak x-show="!desktop || desktopCollapsed" x-ref="navigationTrigger" data-navigation-toggle @click="toggleNavigation()" aria-label="Navigációs menü"
                :aria-label="desktop ? (desktopCollapsed ? 'Oldalsáv megjelenítése' : 'Oldalsáv elrejtése') : 'Navigációs menü'"
                :title="desktop ? (desktopCollapsed ? 'Oldalsáv megjelenítése' : 'Oldalsáv elrejtése') : 'Navigációs menü'"
                :aria-expanded="desktop ? !desktopCollapsed : mobileOpen" :aria-controls="desktop ? 'desktop-navigation' : 'mobile-navigation-dialog'"
                :aria-haspopup="desktop ? null : 'dialog'"
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"><x-icon name="menu" class="h-5 w-5" /></button>
        </div>
        @isset($pageActions)
            <div class="order-last min-w-0 max-w-full basis-full sm:order-none sm:flex-1">
                {{ $pageActions }}
            </div>
        @endisset
        <div class="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
            @include('layouts.quick-search')
            @include('layouts.tasks')
            <details x-ref="accountMenu" data-account-menu class="group relative shrink-0" @click.outside="$el.open = false" @keydown.escape.stop="closeAccount()">
                <summary @click="closeTasks()" aria-label="Fiókmenü" class="flex h-11 cursor-pointer list-none items-center gap-1 rounded-md px-1 text-sm sm:gap-2 sm:px-2 text-gray-600 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 [&::-webkit-details-marker]:hidden">
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-700"><x-icon name="user" class="h-4 w-4" /></span>
                    <span class="hidden max-w-40 truncate sm:block">{{ auth()->user()->name }}</span><x-icon name="chevron-down" class="hidden sm:block h-3 w-3 transition-transform group-open:rotate-180" />
                </summary>
                <div class="absolute right-0 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-lg border border-gray-200 bg-white p-1.5 shadow-lg">
                    <div class="border-b border-gray-100 px-3 py-2"><p class="break-words text-sm font-semibold text-gray-900">{{ auth()->user()->name }}</p><p class="break-all text-xs text-gray-500">{{ auth()->user()->email }}</p></div>
                    <a href="{{ route('profile.edit') }}" class="mt-1 block rounded-md px-3 py-2.5 text-sm text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Profil</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="block w-full rounded-md px-3 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Kijelentkezés</button></form>
                </div>
            </details>
        </div>
    </div>
</header>
<noscript>
    <details class="border-b border-gray-200 bg-white xl:hidden">
        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-indigo-700">Navigációs menü</summary>
        <nav aria-label="Főmenü JavaScript nélkül" class="max-h-[70vh] overflow-y-auto p-3">@include('layouts.navigation-items')</nav>
    </details>
</noscript>
