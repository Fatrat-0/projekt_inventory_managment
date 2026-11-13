<nav aria-label="Átmozgatások modul" class="flex flex-wrap gap-x-5 gap-y-1 border-b border-gray-200 text-sm">
    @foreach(['transfer-intent-pages.index' => 'Igények', 'shipments.index' => 'Fuvarok és szállítmányok'] as $route => $label)
        @php($active = request()->routeIs(explode('.', $route)[0].'.*'))
        <a href="{{ route($route) }}" @if($active) aria-current="page" @endif
            class="inline-flex min-h-11 items-center border-b-2 px-1 font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 {{ $active ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-600 hover:text-gray-900' }}">{{ $label }}</a>
    @endforeach
</nav>
