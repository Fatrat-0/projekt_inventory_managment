<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Product, ReturnQualityAction, Stock, User, Warehouse};
use Database\Seeders\ReturnQualityDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReturnQualitySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_contains_released_scrapped_and_pending_goods_with_real_audit_records(): void
    {
        $user = User::factory()->create(['email' => 'worker@raktar.hu', 'role' => 'worker']);
        $this->seed(ReturnQualityDemoSeeder::class);
        $product = Product::where('sku', 'DEMO-MINOSEG')->sole();
        $stock = Stock::where('product_id', $product->product_id)->sole();
        $this->assertSame(10, $stock->quantity);
        $this->assertSame(3, $stock->quarantined_quantity);
        $this->assertSame(7, $stock->available_quantity);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('warehouses', 1);
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertDatabaseCount('inventory_movement_requests', 3);
        $this->assertDatabaseCount('return_quality_actions', 2);
        $return = InventoryMovement::whereNotNull('return_of_id')->sole();
        $this->assertSame(8, $return->quarantined_quantity);
        $this->assertSame(8, $return->quantity);
        $actions = ReturnQualityAction::orderBy('id')->get();
        $this->assertSame(['release', 'scrap'], $actions->pluck('action')->all());
        $this->assertSame([3, 2], $actions->pluck('quantity')->all());
        $this->assertSame([[12, 12, 8, 5], [12, 10, 5, 3]], $actions->map(fn ($action) => [
            $action->stock_before, $action->stock_after, $action->quarantined_before, $action->quarantined_after,
        ])->all());
        $this->assertNull($actions[0]->movement_id);
        $scrap = InventoryMovement::findOrFail($actions[1]->movement_id);
        $this->assertSame($return->id, (int)$scrap->scrap_of_id);
        $this->actingAs($user)->get(route('movements.show', $return))->assertOk()
            ->assertSee('Még elkülönítve:')->assertSee('3 db')->assertSee('Felszabadítás')->assertSee('Selejtezés');
    }

    public function test_completed_demo_repeats_without_resetting_names_sku_user_email_or_later_stock_activity(): void
    {
        $user = User::factory()->create(['email' => 'worker@raktar.hu', 'role' => 'worker']);
        $this->seed(ReturnQualityDemoSeeder::class);
        $product = Product::sole();
        $product->update(['sku' => 'SAJAT-MODOSITOTT-SKU', 'product_name' => 'Átnevezett termék']);
        Warehouse::sole()->update(['warehouse_name' => 'Átnevezett raktár', 'location' => 'Másik cím']);
        Category::sole()->update(['category_name' => 'Átnevezett kategória']);
        $user->update(['email' => 'atnevezett-felhasznalo@example.test']);
        $return = InventoryMovement::whereNotNull('return_of_id')->sole();
        app(\App\Services\InventoryService::class)->processReturnQuality($return, [
            'user_id' => $user->id, 'action' => 'release', 'quantity' => 1, 'reason' => 'Utólagos vizsgálat után felszabadítva',
        ]);
        $before = $this->snapshot();
        $this->seed(ReturnQualityDemoSeeder::class);
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('return_quality_actions', 3);
        $this->assertSame(2, Stock::sole()->quarantined_quantity);
        $this->assertSame(8, Stock::sole()->available_quantity);
    }

    public function test_demo_does_not_modify_unrelated_stock_or_legacy_history(): void
    {
        $user = User::factory()->create(['email' => 'worker@raktar.hu', 'role' => 'worker']);
        $category = Category::create(['category_name' => 'Meglévő']);
        $product = Product::create(['product_name' => 'Meglévő termék', 'sku' => 'EXISTING-QUALITY', 'price' => 200, 'category_id' => $category->category_id]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Meglévő raktár', 'location' => 'Eredeti']);
        $stock = Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 37]);
        $movement = InventoryMovement::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id,
            'user_id' => $user->id, 'type' => 'in', 'quantity' => 37, 'reference' => 'MEGLEVO-MINOSEG']);
        $stockBefore = $stock->fresh()->getAttributes();
        $movementBefore = $movement->fresh()->getAttributes();
        $this->seed(ReturnQualityDemoSeeder::class);
        $this->assertSame($stockBefore, $stock->fresh()->getAttributes());
        $this->assertSame($movementBefore, $movement->fresh()->getAttributes());
        $this->assertDatabaseCount('inventory_movements', 5);
    }

    private function snapshot(): array
    {
        $tables = ['users', 'categories', 'products', 'warehouses', 'stocks', 'inventory_movements', 'inventory_movement_requests', 'return_quality_actions'];
        return array_map(fn ($table) => DB::table($table)->orderBy($table === 'products' ? 'product_id' : ($table === 'categories' ? 'category_id' : ($table === 'warehouses' ? 'warehouse_id' : 'id')))->get()->map(fn ($row) => (array)$row)->all(), $tables);
    }
}
