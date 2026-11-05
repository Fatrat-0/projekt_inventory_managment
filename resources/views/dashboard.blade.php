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
                <a href="{{ route('dashboard', ['days' => $days, 'chart' => $chartType] + $listPageParams) }}" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Frissítés</a>
                <a href="{{ route('movements.create') }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Új készletmozgás</a>
            </div>
        </header>
        <x-operation-feedback />
        <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <section aria-label="Minimum alatti készletek" class="min-w-0 break-words rounded-lg border {{ $lowStockProductCount || $lowStockCategoryCount ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white' }} p-4 sm:p-5">
                <h2 class="text-sm font-medium text-gray-700">Termékminimum alatt</h2>
                <p class="mt-2 text-3xl font-bold tabular-nums text-gray-900" data-dashboard-card="low-products">{{ number_format($lowStockProductCount, 0, ',', ' ') }}</p>
                <a href="#category-alerts" class="mt-1 block text-sm text-gray-600 underline">{{ number_format($lowStockCategoryCount, 0, ',', ' ') }} kategóriahiány</a>
                <a href="{{ route('products.index', ['stock_status' => 'low']) }}" class="mt-4 inline-block text-sm font-semibold text-indigo-700 hover:underline">Hiányos termékek →</a>
            </section>
            <section aria-label="Minősítésre váró visszáruk" class="min-w-0 break-words rounded-lg border {{ $qualitySummary['returns_count'] ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }} p-4 sm:p-5">
                <h2 class="text-sm font-medium text-gray-700">Minősítésre váró visszáruk</h2>
                <p class="mt-2 text-3xl font-bold tabular-nums text-gray-900" data-quality-summary="returns">{{ number_format($qualitySummary['returns_count'], 0, ',', ' ') }} <span class="text-sm font-normal">tétel</span></p>
                <p class="mt-1 text-sm text-gray-600"><span data-quality-summary="quantity">{{ number_format($qualitySummary['quantity'], 0, ',', ' ') }} db</span> még elkülönítve</p>
                @if($qualitySummary['oldest_at'])<p class="mt-1 text-xs text-gray-500">Legkorábbi beérkezés: {{ $qualitySummary['oldest_at']->format('Y.m.d.') }}</p>@else<p class="mt-1 text-xs text-gray-500">Nincs minősítésre váró visszáru.</p>@endif
                <a href="{{ route('quality-returns.index', ['status' => 'open']) }}" class="mt-4 inline-block text-sm font-semibold text-indigo-700 hover:underline">Minősítési munkalista</a>
            </section>
            <section aria-label="Úton lévő átmozgatások" class="min-w-0 break-words rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                <h2 class="text-sm font-medium text-gray-700">Úton lévő átmozgatások</h2>
                <p class="mt-2 text-3xl font-bold tabular-nums text-gray-900" data-dashboard-card="transfers">{{ number_format($transitSummary['count'], 0, ',', ' ') }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ number_format($transitSummary['quantity'], 0, ',', ' ') }} db szállítás alatt</p>
                <p class="mt-1 text-xs text-gray-500">A szállítmányok tételeivel együtt.</p>
                <a href="{{ route('transfers.index', ['status' => 'pending', 'sort_by' => 'created_at', 'sort_dir' => 'asc']) }}" class="mt-4 inline-block text-sm font-semibold text-indigo-700 hover:underline">Átmozgatások megnyitása →</a>
            </section>
            <section aria-label="Szállítmányok állapota" class="min-w-0 break-words rounded-lg border border-gray-200 bg-white p-4 sm:p-5">
                <h2 class="text-sm font-medium text-gray-700">Úton lévő szállítmányok</h2>
                <p class="mt-2 text-3xl font-bold tabular-nums text-gray-900" data-dashboard-card="shipments">{{ number_format($shipmentSummary['in_transit'], 0, ',', ' ') }}</p>
                <a href="{{ route('shipments.index', ['status' => 'draft']) }}" class="mt-1 block text-sm text-gray-600 underline">{{ number_format($shipmentSummary['drafts'], 0, ',', ' ') }} tervezet</a>
                <a href="{{ route('shipments.index', ['status' => 'in_transit']) }}" class="mt-4 inline-block text-sm font-semibold text-indigo-700 hover:underline">Szállítmányok megnyitása →</a>
            </section>
        </div>
        <section aria-label="Készletösszesítő" class="rounded-lg border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-semibold text-gray-900">Készletösszesítő</h2><a href="{{ route('products.index') }}" class="text-sm font-medium text-indigo-700 hover:underline">Termékek megnyitása →</a></div>
            <dl class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach(['physical' => 'Raktárakban fizikailag', 'available' => 'Ebből kiadható', 'quarantined' => 'Ebből elkülönített'] as $key => $label)
                    <div class="min-w-0"><dt class="text-sm text-gray-500">{{ $label }}</dt><dd class="mt-1 break-words text-xl font-semibold tabular-nums text-gray-900" data-stock-summary="{{ $key }}">{{ number_format($stockSummary[$key], 0, ',', ' ') }} db</dd></div>
                @endforeach
                <div class="min-w-0"><dt class="text-sm text-gray-500">Raktárak között úton</dt><dd class="mt-1 break-words text-xl font-semibold tabular-nums text-gray-900" data-stock-summary="in-transit">{{ number_format($transitSummary['quantity'], 0, ',', ' ') }} db</dd></div>
            </dl>
            <p class="mt-3 text-xs text-gray-500">Az úton lévő mennyiség már nincs az induló raktárban, és csak átvételkor kerül a célraktár készletébe.</p>
        </section>
        @include('dashboard.alerts')
        @include('dashboard.activity')
        @include('dashboard.operations')
    </div>
</x-app-layout>
