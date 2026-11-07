<x-warehouse-layout :$warehouse :$context :$detailContext>
    <div class="app-page space-y-4 py-4">
        <x-page-toolbar title="PDF-leltárív" :description="$warehouse->warehouse_name" />
        <x-operation-feedback />
        <x-pdf-preview id="warehouse-inventory-pdf" :inline="true"
            :url="route('warehouses.inventory-pdf', ['warehouse' => $warehouse] + \Illuminate\Support\Arr::except($filters, ['per_page']))"
            title="Raktári leltárív előnézete"
            :options="\App\Support\WarehouseInventoryPdfOptions::DEFAULTS"
            :checkboxes="['blind_mode' => 'Vakleltár – mennyiségek elrejtése', 'show_sku' => 'Cikkszámok (SKU)', 'show_category' => 'Kategória', 'show_count_column' => 'Számolt mennyiség – üres oszlop', 'show_note_column' => 'Megjegyzés – üres oszlop', 'show_filters' => 'Szűrés és rendezés részletei', 'show_signatures' => 'Aláírási mezők']"
            :force-when="['show_count_column' => 'blind_mode']"
            description="A leltárív a raktári készletlista szűrését és rendezését követi, és minden találatot tartalmaz. Vakleltárnál a rendszer szerinti mennyiségek rejtve maradnak. A kitöltés önmagában nem módosít készletet." />
    </div>
</x-warehouse-layout>
