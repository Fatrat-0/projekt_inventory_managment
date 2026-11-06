<div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            <section aria-label="Minimum alatti készletek" class="min-w-0 break-words rounded-lg border {{ $lowStockProductCount || $lowStockCategoryCount ? 'border-red-200 bg-red-50' : 'border-gray-200 bg-white' }} p-4 sm:p-5">
                <h2 class="text-sm font-medium text-gray-700">Termékminimum alatt</h2>
                <p class="mt-2 text-3xl font-bold tabular-nums text-gray-900" data-dashboard-card="low-products">{{ number_format($lowStockProductCount, 0, ',', ' ') }}</p>
                <a data-dashboard-customize href="{{ in_array('category_alerts', $dashboardLayout['visible'], true) ? '#category-alerts' : route('dashboard.customize', ['days' => $days, 'chart' => $chartType] + $listPageParams).'#layout-category_alerts' }}" title="{{ in_array('category_alerts', $dashboardLayout['visible'], true) ? 'Kategóriahiányok' : 'Kategóriahiányok blokk megjelenítésének beállítása' }}" class="mt-1 block text-sm text-gray-600 underline">{{ number_format($lowStockCategoryCount, 0, ',', ' ') }} kategóriahiány</a>
                <a href="{{ route('products.index', ['stock_status' => 'low']) }}" class="mt-4 inline-block text-sm font-semibold text-indigo-700 hover:underline">Hiányos termékek →</a>
            </section>
            <section aria-label="Minősítésre váró visszáruk" class="min-w-0 break-words rounded-lg border {{ $qualitySummary['returns_count'] ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white' }} p-4 sm:p-5">
                <h2 class="text-sm font-medium text-gray-700">Minősítésre váró visszáruk</h2>
                <p class="mt-2 text-3xl font-bold tabular-nums text-gray-900" data-quality-summary="returns">{{ number_format($qualitySummary['returns_count'], 0, ',', ' ') }} <span class="text-sm font-normal">tétel</span></p>
                <p class="mt-1 text-sm text-gray-600"><span data-quality-summary="quantity">{{ number_format($qualitySummary['quantity'], 0, ',', ' ') }} db</span> még elkülönítve</p>
                @if($qualitySummary['oldest_at'])
                    <p class="mt-1 text-xs text-gray-500"><span data-quality-waiting class="font-medium text-gray-700">Legrégebbi: {{ \App\Support\WaitingDuration::label($qualitySummary['oldest_at'], $asOf, 'minősítésre vár') }}</span><br>Beérkezett: <time datetime="{{ $qualitySummary['oldest_at']->toIso8601String() }}">{{ $qualitySummary['oldest_at']->format('Y.m.d. H:i') }}</time></p>
                @else<p class="mt-1 text-xs text-gray-500">Nincs minősítésre váró visszáru.</p>@endif
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
