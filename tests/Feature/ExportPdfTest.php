<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_inventory_pdf(): void
    {
        // Vendégként próbáljuk megnyitni a PDF exportot
        $response = $this->get(route('export.inventory'));

        // Át kell irányítania a bejelentkező oldalra
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_download_and_preview_pdf(): void
    {
        // Megadjuk a 'worker' szerepkört, hogy átengedje a role:admin,manager,worker middleware
        $user = User::factory()->create([
            'role' => 'worker',
        ]);

        // 1. Teszt: Normál PDF letöltés
        $downloadResponse = $this->actingAs($user)->get(route('export.inventory'));
        
        $downloadResponse->assertStatus(200);
        $downloadResponse->assertHeader('content-type', 'application/pdf');

        // 2. Teszt: Élő előnézeti (stream) mód fekvő tájolással
        $previewResponse = $this->actingAs($user)->get(route('export.inventory', [
            'preview' => 1,
            'orientation' => 'landscape',
        ]));

        $previewResponse->assertStatus(200);
        $previewResponse->assertHeader('content-type', 'application/pdf');
    }

    public function test_blind_mode_and_column_options_hide_sensitive_data(): void
    {
        // Készítünk egy teszt terméket és kategóriát a memóriában
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

        // 1. ESET: Minden oszlop be van kapcsolva, nincs vakleltár
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
                'show_prices'     => true, // Vakleltárnál hiába kér árat, el kell rejtenie!
                'show_count_col'  => false,
                'blind_mode'      => true,
                'show_signatures' => false,
            ],
        ])->render();

        // Ellenőrizzük, hogy a Vakleltár felirat megjelenik:
        $this->assertStringContainsString('VAKLELTÁR MÓD', $blindHtml);
        // A kikapcsolt cikkszám, az ár és az aláírás viszont NEM szerepelhet a lapon:
        $this->assertStringNotContainsString('TITKOS-SKU-999', $blindHtml);
        $this->assertStringNotContainsString('450 000 Ft', $blindHtml);
        $this->assertStringNotContainsString('Leltározást végezte', $blindHtml);
    }
}