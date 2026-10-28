<nav aria-label="Szállítási nézetek" class="flex gap-4 border-b border-gray-200 text-sm">
    @foreach(['transfers.index' => 'Átmozgatások', 'shipments.index' => 'Szállítmányok és rakodás'] as $route => $label)
        @php($active = request()->routeIs(explode('.', $route)[0].'.*'))
        <a href="{{ route($route) }}" @if($active) aria-current="page" @endif class="border-b-2 px-1 pb-3 font-medium {{ $active ? 'border-indigo-500 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $label }}</a>
    @endforeach
</nav>
