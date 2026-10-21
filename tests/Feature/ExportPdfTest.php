<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_pdf_exports(): void
    {
        $this->get(route('export.inventory'))->assertRedirect('/login');
        $this->get(route('export.movements'))->assertRedirect('/login');
    }

    public function test_authenticated_user_can_download_and_preview_inventory_pdf(): void
    {
        $user = User::factory()->create([
            'role' => 'worker',
        ]);

        // 1. Normál PDF letöltés
        $downloadResponse = $this->actingAs($user)->get(route('export.inventory'));
        $downloadResponse->assertStatus(200);
        $downloadResponse->assertHeader('content-type', 'application/pdf');

        // 2. Élő előnézeti (stream) mód fekvő tájolással
        $previewResponse = $this->actingAs($user)->get(route('export.inventory', [
            'preview' => 1,
            'orientation' => 'landscape',
        ]));
        $previewResponse->assertStatus(200);
        $previewResponse->assertHeader('content-type', 'application/pdf');
    }

    public function test_blind_mode_and_column_options_hide_sensitive_data(): void
    {
        $category = new Category(['category_name' => 'Teszt Elektronikai Kategória']);
        $product = new Product([
            'sku' => 'TITKOS-SKU-999',
            'product_name' => 'Prémium Teszt Laptop',
            'price' => 450000,
        ]);
        $product->setRelation('category', $category);
        $product->setRelation('stocks', collect([]));

        $products = collect([$product]);
        $filterInfo = ['Kategória: Teszt Elektronikai Kategória'];

        // 1. ESET: Minden oszlop bekapcsolva, nincs vakleltár
        $normalHtml = view('pdf.inventory', [
            'products' => $products,
            'filterInfo' => $filterInfo,
            'options' => [
                'show_filters'    => true,
                'show_sku'        => true,
                'show_category'   => true,
                'show_warehouses' => true,
                'show_prices'     => true,
                'show_count_col'  => true,
                'blind_mode'      => false,
                'show_signatures' => true,
            ],
        ])->render();

        $this->assertStringContainsString('TITKOS-SKU-999', $normalHtml);
        $this->assertStringContainsString('450 000 Ft', $normalHtml);
        $this->assertStringContainsString('Leltározást végezte', $normalHtml);

        // 2. ESET: Vakleltár mód BEKAPCSOLVA, Cikkszám és Aláírás KIKAPCSOLVA
        $blindHtml = view('pdf.inventory', [
            'products' => $products,
            'filterInfo' => $filterInfo,
            'options' => [
                'show_filters'    => false,
                'show_sku'        => false,
                'show_category'   => true,
                'show_warehouses' => true,
                'show_prices'     => true,
                'show_count_col'  => false,
                'blind_mode'      => true,
                'show_signatures' => false,
            ],
        ])->render();

        $this->assertStringContainsString('VAKLELTÁR MÓD', $blindHtml);
        $this->assertStringNotContainsString('TITKOS-SKU-999', $blindHtml);
        $this->assertStringNotContainsString('450 000 Ft', $blindHtml);
        $this->assertStringNotContainsString('Leltározást végezte', $blindHtml);
    }

    public function test_authenticated_user_can_download_and_preview_movements_pdf(): void
    {
        $user = User::factory()->create([
            'role' => 'worker',
        ]);

        // 1. Teljes forgalmi napló letöltése
        $downloadResponse = $this->actingAs($user)->get(route('export.movements'));
        $downloadResponse->assertStatus(200);
        $downloadResponse->assertHeader('content-type', 'application/pdf');

        // 2. Előnézet csak bevételezésekre ('in') szűrve, fekvő módban
        $previewResponse = $this->actingAs($user)->get(route('export.movements', [
            'preview' => 1,
            'type' => 'in',
            'orientation' => 'landscape',
        ]));
        $previewResponse->assertStatus(200);
        $previewResponse->assertHeader('content-type', 'application/pdf');
    }

    public function test_movements_pdf_template_respects_column_options_and_calculates_summary(): void
    {
        $product = new Product(['product_name' => 'Ipari Csavarhúzó', 'sku' => 'CSAV-01']);
        $warehouse = new Warehouse(['warehouse_name' => 'Északi Raktár']);
        $user = new User(['name' => 'Teszt Raktáros']);

        $movement = new InventoryMovement([
            'type' => 'in',
            'quantity' => 25,
            'reference' => 'SZAMLA-2026-001',
        ]);
        $movement->setRelation('product', $product);
        $movement->setRelation('warehouse', $warehouse);
        $movement->setRelation('user', $user);

        // 1. ESET: Minden oszlop és összesítő bekapcsolva
        $fullHtml = view('pdf.movements', [
            'movements' => collect([$movement]),
            'options' => [
                'type_filter'     => 'in',
                'show_warehouse'  => true,
                'show_reference'  => true,
                'show_user'       => true,
                'show_summary'    => true,
                'show_signatures' => true,
            ],
        ])->render();

        $this->assertStringContainsString('Északi Raktár', $fullHtml);
        $this->assertStringContainsString('SZAMLA-2026-001', $fullHtml);
        $this->assertStringContainsString('Teszt Raktáros', $fullHtml);
        $this->assertStringContainsString('+25 db', $fullHtml);

        // 2. ESET: Raktár, Hivatkozás és Felhasználó oszlopok kikapcsolva
        $minimalHtml = view('pdf.movements', [
            'movements' => collect([$movement]),
            'options' => [
                'type_filter'     => 'all',
                'show_warehouse'  => false,
                'show_reference'  => false,
                'show_user'       => false,
                'show_summary'    => false,
                'show_signatures' => false,
            ],
        ])->render();

        $this->assertStringContainsString('Ipari Csavarhúzó', $minimalHtml);
        $this->assertStringNotContainsString('Északi Raktár', $minimalHtml);
        $this->assertStringNotContainsString('SZAMLA-2026-001', $minimalHtml);
        $this->assertStringNotContainsString('Teszt Raktáros', $minimalHtml);
    }

    public function test_authenticated_user_can_generate_transfer_waybill_pdf(): void
    {
        $user = User::factory()->create([
            'role' => 'worker',
        ]);

        $category = Category::create([
            'category_name' => 'Szerszámok',
        ]);

        $fromWarehouse = Warehouse::create([
            'warehouse_name' => 'Központi Elosztó',
            'location' => 'Budapest',
        ]);

        $toWarehouse = Warehouse::create([
            'warehouse_name' => 'Déli Telephely',
            'location' => 'Pécs',
        ]);

        $product = Product::create([
            'product_name' => 'Ütvefúró Pro',
            'sku' => 'FUR-550',
            'price' => 35000,
            'min_stock' => 5,
            'category_id' => $category->category_id,
        ]);

        $transfer = InventoryTransfer::create([
            'product_id' => $product->product_id,
            'from_warehouse_id' => $fromWarehouse->warehouse_id,
            'to_warehouse_id' => $toWarehouse->warehouse_id,
            'quantity' => 12,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        // 1. Végpont tesztelése (HTTP 200 és PDF fejléc)
        $response = $this->actingAs($user)->get(route('export.transfer', [
            'transfer' => $transfer->getKey(),
            'preview' => 1,
        ]));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');

        // 2. Sablon tartalmának ellenőrzése
        $transfer->load(['product.category', 'fromWarehouse', 'toWarehouse']);
        $waybillHtml = view('pdf.transfer', compact('transfer'))->render();

        $this->assertStringContainsString('RAKTÁRKÖZI SZÁLLÍTÓLEVÉL', $waybillHtml);
        $this->assertStringContainsString('Központi Elosztó', $waybillHtml);
        $this->assertStringContainsString('Déli Telephely', $waybillHtml);
        $this->assertStringContainsString('Ütvefúró Pro', $waybillHtml);
        $this->assertStringContainsString('12 db', $waybillHtml);
    }
}