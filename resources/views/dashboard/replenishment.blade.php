<section aria-labelledby="replenishment-title" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
    <x-dashboard-panel-header id="replenishment-title" title="Utánpótlási javaslatok" :count="$replenishmentCount"
        :href="route('stock.index')" link-label="Készlet és helyi minimumok"
        description="A helyi minimum eléréséhez még hiányzó kiadható készlet, a már úton lévő pótlás figyelembevételével. Csak meglévő készletsorokra számolunk; a helyi minimum alapértéke 5 db." />
    <p class="border-b border-gray-100 px-5 py-3 text-xs text-gray-500">A javaslatok külön értendők, nem foglalnak készletet. Indításkor a rendszer újra ellenőrzi a cél hiányát és a forrás helyi minimum feletti készletét.</p>
    <x-dashboard-list :items="$replenishments" kind="replenishment" :as-of="$asOf" />
</section>
