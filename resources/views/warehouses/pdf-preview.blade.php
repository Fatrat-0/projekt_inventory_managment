<x-pdf-preview id="warehouse-inventory-pdf"
    :url="route('warehouses.inventory-pdf', ['warehouse' => $warehouse] + \Illuminate\Support\Arr::except($filters, ['per_page']))"
    title="Raktári leltárív előnézete" button-label="PDF-leltárív"
    :options="\App\Support\WarehouseInventoryPdfOptions::DEFAULTS"
    :checkboxes="['blind_mode' => 'Vakleltár – mennyiségek elrejtése', 'show_sku' => 'Cikkszámok (SKU)', 'show_category' => 'Kategória', 'show_count_column' => 'Számolt mennyiség – üres oszlop', 'show_note_column' => 'Megjegyzés – üres oszlop', 'show_filters' => 'Szűrés és rendezés részletei', 'show_signatures' => 'Aláírási mezők']"
    :force-when="['show_count_column' => 'blind_mode']"
    description="A megjelenített készletlista szűrése és rendezése szerint készül, minden találattal. Vakleltárnál a rendszer szerinti mennyiségek rejtve maradnak. A kitöltés önmagában nem módosít készletet." />
