<section aria-labelledby="pending-title" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
        <x-dashboard-panel-header id="pending-title" title="Átvételre váró átmozgatások"
            :href="route('transfers.index', ['status' => 'pending', 'sort_by' => 'created_at', 'sort_dir' => 'asc'])" link-label="Átvételi lista"
            description="A legrégebben indított tételek elöl. A szállítmány tételeit a megállónál lehet átvenni." />
        <x-dashboard-list :items="$pendingTransfers" kind="transfer" :as-of="$asOf" />
    </section>
