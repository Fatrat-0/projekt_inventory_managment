<section aria-labelledby="warehouse-stocks-title" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
    <x-dashboard-panel-header id="warehouse-stocks-title" title="Raktárankénti készlethelyzet" :count="$totalWarehouses"
        :href="route('stock.index')" link-label="Készletlista"
        description="Aktuális fizikai készlet darabszámban, közös skálán. Az úton lévő áru külön szerepel az összesítőben; ez nem raktárkapacitás-kimutatás." />
    <div class="flex flex-wrap gap-4 border-b border-gray-100 px-5 py-3 text-xs text-gray-600">
        <span class="inline-flex items-center gap-2"><span aria-hidden="true" class="h-3 w-3 rounded-sm bg-indigo-500"></span>Kiadható</span>
        <span class="inline-flex items-center gap-2"><span aria-hidden="true" class="h-3 w-3 rounded-sm bg-amber-400"></span>Elkülönített</span>
    </div>
    <x-dashboard-list :items="$warehouseStocks" kind="warehouse" :as-of="$asOf" :warehouse-peak="$warehousePeak" />
</section>
