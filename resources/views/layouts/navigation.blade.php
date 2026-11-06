<aside id="desktop-navigation" x-ref="desktopNavigation" class="app-sidebar fixed inset-y-0 left-0 z-40 w-60 flex-col border-r border-gray-200 bg-white" aria-label="Oldalsáv">
    <div class="flex h-16 shrink-0 items-center justify-between gap-1 border-b border-gray-200 px-3">
        <a href="{{ route('dashboard') }}" aria-label="Készletkezelő – Vezérlőpult" class="flex min-w-0 items-center gap-2 rounded-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
            <x-application-logo class="h-8 w-8 shrink-0" /><span class="text-sm font-bold text-gray-900">Készletkezelő</span>
        </a>
        <button type="button" x-cloak x-ref="sidebarCollapse" @click="toggleNavigation()" aria-label="Oldalsáv elrejtése" title="Oldalsáv elrejtése" aria-controls="desktop-navigation" :aria-expanded="!desktopCollapsed"
            class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"><x-icon name="sidebar-close" class="h-5 w-5" /></button>
    </div>
    <nav aria-label="Főmenü" class="min-h-0 flex-1 overflow-y-auto p-3 py-5">@include('layouts.navigation-items')</nav>
</aside>

<dialog id="mobile-navigation-dialog" x-ref="navigationDialog" aria-labelledby="mobile-navigation-title" class="app-navigation-dialog" @close="onNavigationClosed()" @click="closeOnBackdrop($event)" @keydown.tab="trapNavigationFocus($event)">
    <div class="flex h-full flex-col bg-white">
        <div class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-gray-200 px-4">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3 rounded-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600">
                <x-application-logo class="h-8 w-8 shrink-0" /><span id="mobile-navigation-title" class="font-bold text-gray-900">Készletkezelő</span>
            </a>
            <button type="button" autofocus @click="closeNavigation()" aria-label="Menü bezárása" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-600"><x-icon name="close" class="h-5 w-5" /></button>
        </div>
        <nav aria-label="Mobil főmenü" class="min-h-0 flex-1 overflow-y-auto p-3 py-5">@include('layouts.navigation-items')</nav>
    </div>
</dialog>
