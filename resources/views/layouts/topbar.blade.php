<header class="sticky top-0 z-30 border-b border-gray-200 bg-white" data-app-topbar>
    <div class="flex min-h-16 items-center justify-between gap-3 px-4 py-2 sm:px-6 lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
            <button type="button" x-cloak x-show="!desktop || desktopCollapsed" x-ref="navigationTrigger" data-navigation-toggle @click="toggleNavigation()" aria-label="Navigációs menü"
                :aria-label="desktop ? (desktopCollapsed ? 'Oldalsáv megjelenítése' : 'Oldalsáv elrejtése') : 'Navigációs menü'"
                :title="desktop ? (desktopCollapsed ? 'Oldalsáv megjelenítése' : 'Oldalsáv elrejtése') : 'Navigációs menü'"
                :aria-expanded="desktop ? !desktopCollapsed : mobileOpen" :aria-controls="desktop ? 'desktop-navigation' : 'mobile-navigation-dialog'"
                :aria-haspopup="desktop ? null : 'dialog'"
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"><x-icon name="menu" class="h-5 w-5" /></button>
            <nav aria-label="Aktuális hely" class="min-w-0 text-sm">
                <ol class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                    @foreach($breadcrumbs as $crumb)
                        <li class="flex min-w-0 items-center gap-2 @if(!$loop->last) hidden sm:flex @endif">
                            @if(!$loop->first)<x-icon name="chevron-right" class="hidden h-3 w-3 text-gray-400 sm:block" />@endif
                            @if($crumb['url'])<a href="{{ $crumb['url'] }}" class="dashboard-text min-w-0 rounded-sm text-gray-500 hover:text-indigo-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">{{ $crumb['label'] }}</a>
                            @else<span aria-current="page" class="dashboard-text min-w-0 font-medium text-gray-800">{{ $crumb['label'] }}</span>@endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        </div>
        <details x-ref="accountMenu" data-account-menu class="group relative shrink-0" @click.outside="$el.open = false" @keydown.escape.stop="closeAccount()">
            <summary aria-label="Fiókmenü" class="flex h-11 cursor-pointer list-none items-center gap-2 rounded-md px-2 text-sm text-gray-600 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600 [&::-webkit-details-marker]:hidden">
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-indigo-700"><x-icon name="user" class="h-4 w-4" /></span>
                <span class="hidden max-w-40 truncate sm:block">{{ auth()->user()->name }}</span><x-icon name="chevron-down" class="h-3 w-3 transition-transform group-open:rotate-180" />
            </summary>
            <div class="absolute right-0 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-lg border border-gray-200 bg-white p-1.5 shadow-lg">
                <div class="border-b border-gray-100 px-3 py-2"><p class="break-words text-sm font-semibold text-gray-900">{{ auth()->user()->name }}</p><p class="break-all text-xs text-gray-500">{{ auth()->user()->email }}</p></div>
                <a href="{{ route('profile.edit') }}" class="mt-1 block rounded-md px-3 py-2.5 text-sm text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Profil</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="block w-full rounded-md px-3 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">Kijelentkezés</button></form>
            </div>
        </details>
    </div>
</header>
<noscript>
    <details class="border-b border-gray-200 bg-white xl:hidden">
        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-indigo-700">Navigációs menü</summary>
        <nav aria-label="Főmenü JavaScript nélkül" class="max-h-[70vh] overflow-y-auto p-3">@include('layouts.navigation-items')</nav>
    </details>
</noscript>
