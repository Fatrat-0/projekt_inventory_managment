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
