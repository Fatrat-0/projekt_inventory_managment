<div class="grid items-start gap-6 lg:grid-cols-2">
    <section aria-labelledby="product-alerts-title" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
        <x-dashboard-panel-header id="product-alerts-title" title="Termékhiányok" :count="$lowStockProductCount"
            :href="route('products.index', ['stock_status' => 'low'])" link-label="Összes hiány"
            description="A kiadható készlet és a minimum minden raktárra összesítve értendő. A legnagyobb darabszámú hiányok jelennek meg elöl." />
        <x-dashboard-list :items="$lowStockProducts" kind="product" :can-manage="$canManage" />
    </section>
    <section id="category-alerts" tabindex="-1" aria-labelledby="category-alerts-title" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
        <x-dashboard-panel-header id="category-alerts-title" title="Kategóriahiányok" :count="$lowStockCategoryCount"
            :href="$canManage ? route('categories.index') : route('products.index')" :link-label="$canManage ? 'Összes kategória' : 'Terméklista'"
            description="Csak a közvetlenül besorolt termékek kiadható készlete számít, minden raktárból; az alkategóriák nélkül. A legnagyobb darabszámú hiányok jelennek meg elöl." />
        <x-dashboard-list :items="$lowStockCategories" kind="category" :can-manage="$canManage" />
    </section>
</div>
