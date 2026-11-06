<section aria-labelledby="recent-title" class="min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white">
        <x-dashboard-panel-header id="recent-title" title="Legutóbbi készletmozgások" :href="route('movements.index')" link-label="Teljes napló"
            description="A legfrissebbek elöl, az időszakválasztótól függetlenül." />
        <x-dashboard-list :items="$recentMovements" kind="movement" :as-of="$asOf" />
    </section>
