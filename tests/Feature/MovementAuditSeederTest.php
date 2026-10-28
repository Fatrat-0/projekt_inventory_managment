<?php

namespace Tests\Feature;

use App\Models\{InventoryMovement, Product, Stock, User, Warehouse};
use Database\Seeders\{DatabaseSeeder, MovementAuditDemoSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MovementAuditSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_seed_contains_real_audit_examples_and_legacy_history(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = InventoryMovement::where('reference', 'like', 'DEMO-AUDIT-%')->orderBy('id')->get();
        $this->assertCount(5, $demo);
        $this->assertSame([[0,20], [20,15], [15,23], [0,10], [10,0]], $demo->map(fn($m) => [$m->stock_before, $m->stock_after])->all());
        $this->assertSame(45, InventoryMovement::whereNull('stock_before')->whereNull('stock_after')->count());
        $this->assertDatabaseCount('inventory_movement_requests', 5);
        $this->assertSame(0, DB::table('inventory_movement_requests')->whereNull('movement_id')->count());
        $warehouse = Warehouse::where('warehouse_name', 'Bemutató raktár – Audit')->sole();
        foreach (['DEMO-AUDIT-CSAVAR' => 23, 'DEMO-AUDIT-KESZTYU' => 0] as $sku => $expected) {
            $product = Product::where('sku', $sku)->sole();
            $this->assertEquals($expected, Stock::where('product_id', $product->product_id)->where('warehouse_id', $warehouse->warehouse_id)->value('quantity'));
        }
        $this->actingAs(User::where('email', 'worker@raktar.hu')->sole())
            ->get(route('movements.show', $demo->first()))->assertOk()->assertSee('20 db')->assertSee('Művelet előtt');
    }

    public function test_additive_seed_can_repeat_without_duplicates_or_stock_changes(): void
    {
        User::factory()->create(['email' => 'worker@raktar.hu', 'role' => 'worker']);
        $this->seed(MovementAuditDemoSeeder::class);
        $stocks = Stock::orderBy('id')->get()->toArray();
        $movements = InventoryMovement::orderBy('id')->get()->toArray();
        $requests = DB::table('inventory_movement_requests')->orderBy('id')->get()->toArray();
        $this->seed(MovementAuditDemoSeeder::class);
        $this->assertSame($stocks, Stock::orderBy('id')->get()->toArray());
        $this->assertSame($movements, InventoryMovement::orderBy('id')->get()->toArray());
        $this->assertEquals($requests, DB::table('inventory_movement_requests')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('warehouses', 1);
    }

    public function test_additive_seed_leaves_unrelated_inventory_and_history_intact(): void
    {
        $user = User::factory()->create(['email' => 'worker@raktar.hu', 'role' => 'worker']);
        $category = \App\Models\Category::create(['category_name' => 'Meglévő kategória']);
        $product = Product::create(['category_id' => $category->category_id, 'product_name' => 'Meglévő termék', 'sku' => 'EXISTING', 'price' => 100]);
        $warehouse = Warehouse::create(['warehouse_name' => 'Meglévő raktár', 'location' => 'Eredeti']);
        $stock = Stock::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'quantity' => 37]);
        $movement = InventoryMovement::create(['product_id' => $product->product_id, 'warehouse_id' => $warehouse->warehouse_id, 'user_id' => $user->id, 'type' => 'in', 'quantity' => 37, 'reference' => 'EREDETI']);
        $beforeStock = $stock->fresh()->toArray();
        $beforeMovement = $movement->fresh()->toArray();
        $this->seed(MovementAuditDemoSeeder::class);
        $this->assertSame($beforeStock, $stock->fresh()->toArray());
        $this->assertSame($beforeMovement, $movement->fresh()->toArray());
        $this->assertDatabaseCount('inventory_movements', 6);
        $this->assertDatabaseCount('inventory_movement_requests', 5);
    }
}
