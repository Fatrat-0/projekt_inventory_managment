<section aria-labelledby="product-alerts-title" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
        <x-dashboard-panel-header id="product-alerts-title" title="Termékhiányok" :count="$lowStockProductCount"
            :href="route('products.index', ['stock_status' => 'low'])" link-label="Összes hiány"
            description="A kiadható készlet és a minimum minden raktárra összesítve értendő. A legnagyobb darabszámú hiányok jelennek meg elöl." />
        <x-dashboard-list :items="$lowStockProducts" kind="product" :as-of="$asOf" :can-manage="$canManage" />
    </section>
