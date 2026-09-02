<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\Stock;

class DashboardAlertTest extends TestCase
{
    // Ez a varázsszó gondoskodik róla, hogy a teszt után minden 
    // "teszt adat" törlődjön, és ne szemetelje tele az éles adatbázisodat!
    use RefreshDatabase; 

    public function test_low_stock_product_appears_on_dashboard_alert()
    {
        // 1. ARRANGE (Előkészítés)
        // Csinálunk egy kamu felhasználót
        $user = User::factory()->create();

        // Csinálunk egy kategóriát (50 db-os minimummal)
        $category = Category::create([
            'category_name' => 'Teszt Csavarok',
            'default_item_min_stock' => 50
        ]);

        // Csinálunk egy terméket ebbe a kategóriába
        $product = Product::create([
            'product_name' => 'Speciális Teszt Facsavar',
            'sku' => 'TESZT-001',
            'price' => 1500,
            'category_id' => $category->category_id,
        ]);

        // Csinálunk egy raktárat
        $warehouse = Warehouse::create([
            'warehouse_name' => 'Fő Raktár',
            'location' => 'Budapest'
        ]);

        // Létrehozunk egy készletet: Csak 10 db-ot adunk neki! (10 < 50, tehát riasztania kell)
        Stock::create([
            'product_id' => $product->product_id,
            'warehouse_id' => $warehouse->warehouse_id,
            'quantity' => 10
        ]);


        // 2. ACT (Cselekvés)
        // Bejelentkezünk, és lekérjük a Dashboard oldalt
        $response = $this->actingAs($user)->get('/dashboard');


        // 3. ASSERT (Ellenőrzés)
        // Ellenőrizzük, hogy az oldal sikeresen betöltött-e (200 OK státuszkód)
        $response->assertStatus(200);

        // Ellenőrizzük, hogy a képernyőn szerepel-e a termékünk neve a riasztások között!
        $response->assertSee('Speciális Teszt Facsavar');
        
        // Ellenőrizzük, hogy a készlet darabszáma (10 db) is ki van-e írva
        $response->assertSee('10 db');
    }
}