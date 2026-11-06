<x-app-layout>
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8" x-data="dashboardView({{ \Illuminate\Support\Js::from(['chartType' => $chartType, 'days' => $days]) }})" @pageshow.window="if ($event.persisted) window.location.reload()">
        @php $canManage = in_array(auth()->user()->role, ['admin', 'manager'], true); @endphp
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-gray-900">Vezérlőpult</h1>
                <p class="mt-1 text-sm text-gray-500">Minden raktár aktuális készlete és nyitott feladatai.</p>
                <p class="mt-2 text-xs text-gray-500">{{ number_format($totalProducts, 0, ',', ' ') }} termék · {{ number_format($totalWarehouses, 0, ',', ' ') }} raktár · Frissítve: <time datetime="{{ $asOf->toIso8601String() }}">{{ $asOf->format('Y.m.d. H:i') }}</time></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a data-dashboard-customize href="{{ route('dashboard.customize', ['days' => $days, 'chart' => $chartType] + $listPageParams) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Testreszabás</a>
                <a href="{{ route('dashboard', ['days' => $days, 'chart' => $chartType] + $listPageParams) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Frissítés</a>
                <a href="{{ route('movements.create') }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Új készletmozgás</a>
            </div>
        </header>
        <x-operation-feedback />
        @if(!$dashboardLayout['visible'])
            <section data-dashboard-empty class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center">
                <h2 class="font-semibold text-gray-900">Jelenleg minden blokk el van rejtve</h2>
                <p class="mt-2 text-sm text-gray-600">Válaszd ki, mely információkat szeretnéd látni a vezérlőpulton.</p>
                <a data-dashboard-customize href="{{ route('dashboard.customize', ['days' => $days, 'chart' => $chartType]) }}" class="mt-4 inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Blokkok kiválasztása</a>
            </section>
        @else
            <div class="grid items-start gap-6 lg:grid-cols-2">
                @foreach($dashboardLayout['order'] as $panelId)
                    @if(in_array($panelId, $dashboardLayout['visible'], true))
                        <div data-dashboard-panel="{{ $panelId }}" class="min-w-0 {{ $dashboardPanels[$panelId]['wide'] ? 'lg:col-span-2' : '' }}">
                            @include($dashboardPanels[$panelId]['view'])
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
