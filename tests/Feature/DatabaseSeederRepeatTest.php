<?php

namespace Tests\Feature;

use App\Models\{InventoryMovement, InventoryTransfer, Product, Stock, User};
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash};
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseSeederRepeatTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(): array
    {
        $result = [];
        foreach (['users' => 'id', 'categories' => 'category_id', 'warehouses' => 'warehouse_id', 'partners' => 'id',
            'products' => 'product_id', 'stocks' => 'id', 'inventory_movements' => 'id',
            'inventory_transfers' => 'id', 'inventory_movement_requests' => 'id', 'inventory_transfer_requests' => 'id',
            'shipments' => 'id', 'shipment_stops' => 'id', 'shipment_items' => 'id'] as $table => $key) {
            $result[$table] = DB::table($table)->orderBy($key)->get()->map(fn($row) => (array) $row)->all();
        }
        return $result;
    }

    public function test_running_full_seed_twice_does_not_change_existing_rows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = $this->snapshot();
        $this->travel(2)->hours();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_full_seed_preserves_changed_stock_credentials_and_transfer_state(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'worker@raktar.hu')->sole();
        $user->update(['name' => 'Módosított név', 'password' => Hash::make('changed-password'), 'role' => 'manager']);
        $product = Product::where('sku', 'GEP-FUR-18V')->sole();
        $product->update(['product_name' => 'Átnevezett termék', 'price' => 12345]);
        Stock::where('product_id', $product->product_id)->update(['quantity' => 0]);
        $transfer = InventoryTransfer::where('status', 'pending')->firstOrFail();
        $transfer->update(['status' => 'completed']);
        $before = $this->snapshot();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue(Hash::check('changed-password', $user->fresh()->password));
    }

    public function test_failure_rolls_back_the_entire_seed(): void
    {
        // DatabaseSeeder intentionally disables model events, so inject the failure at SQL level.
        DB::statement("CREATE TRIGGER fail_product_seed BEFORE INSERT ON products BEGIN SELECT RAISE(ABORT, 'intentional failure'); END");
        try {
            $this->seed(DatabaseSeeder::class);
            $this->fail('Expected SQL failure.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('intentional failure', $e->getMessage());
        }
        foreach (['users', 'warehouses', 'categories', 'partners', 'products'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function transferActions(): array
    {
        return ['receipt' => [true], 'cancellation' => [false]];
    }

    #[DataProvider('transferActions')]
    public function test_seeded_pending_transfer_reserves_stock_and_finalizes_without_creating_stock(bool $complete): void
    {
        $this->seed(DatabaseSeeder::class);
        $product = Product::where('sku', 'GEP-FUR-18V')->sole();
        $transfer = InventoryTransfer::where('product_id', $product->product_id)->sole();
        $stocks = fn () => Stock::where('product_id', $product->product_id);
        // Catalog opening quantities: Budapest 14, Pécs 6, Győr 5, Debrecen 4.
        $this->assertEquals(10, $stocks()->where('warehouse_id', $transfer->from_warehouse_id)->value('quantity'));
        $this->assertEquals(6, $stocks()->where('warehouse_id', $transfer->to_warehouse_id)->value('quantity'));
        $this->assertEquals(29, $stocks()->sum('quantity') + $transfer->quantity);

        app(InventoryService::class)->finalizeTransfer($transfer, $complete);
        $this->assertEquals(29, $stocks()->sum('quantity'));
        $this->assertEquals($complete ? 10 : 14, $stocks()->where('warehouse_id', $transfer->from_warehouse_id)->value('quantity'));
        $this->assertEquals($complete ? 10 : 6, $stocks()->where('warehouse_id', $transfer->to_warehouse_id)->value('quantity'));
        $before = $this->snapshot();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->snapshot());
    }
}
