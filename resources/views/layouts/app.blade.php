<!DOCTYPE html>
<html lang="hu">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Készletkezelő</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="app-shell min-h-screen bg-gray-100" x-data="appShell" :data-sidebar-collapsed="desktopCollapsed" @pageshow.window="if ($event.persisted) restorePage()">
            <a href="#main-content" class="app-skip-link sr-only fixed left-4 top-2 z-50 rounded-md bg-indigo-700 px-4 py-3 text-sm font-semibold text-white focus:not-sr-only">Ugrás a tartalomra</a>
            @include('layouts.navigation')
            <div class="app-content min-w-0">
                @include('layouts.topbar')
                <main id="main-content" tabindex="-1" class="min-w-0 scroll-mt-20">
                    @isset($header)
                        <header class="bg-white shadow">
                            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">{{ $header }}</div>
                        </header>
                    @endisset
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
