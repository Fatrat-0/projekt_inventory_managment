<?php

namespace Tests\Feature;

use App\Models\{InventoryMovement, InventoryTransfer, Partner, Product, Stock, User, Warehouse};
use App\Queries\WarehouseStatistics;
use App\Services\InventoryService;
use App\Support\WarehouseStatisticsReport;
use Database\Seeders\{DatabaseSeeder, WarehouseCapacityDemoSeeder};
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
            'shipments' => 'id', 'shipment_stops' => 'id', 'shipment_items' => 'id', 'return_quality_actions' => 'id',
            'warehouse_locations' => 'id', 'warehouse_placements' => 'id'] as $table => $key) {
            $result[$table] = DB::table($table)->orderBy($key)->get()->map(fn($row) => (array) $row)->all();
        }
        return $result;
    }

    public function test_running_full_seed_twice_does_not_change_existing_rows(): void
    {
        $this->seed(DatabaseSeeder::class);
        $demo = Product::where('sku', 'DEMO-KAP-001')->sole();
        $this->assertDatabaseCount('warehouse_locations', 4);
        $this->assertSame(4, DB::table('warehouse_locations')->whereNotNull('floor_x')->count());
        $this->assertSame(2, InventoryTransfer::where('product_id', $demo->product_id)->where('status', 'pending')->whereNotNull('to_location_id')->count());
        $before = $this->snapshot();
        $this->travel(2)->hours();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_reseeding_does_not_restore_or_duplicate_archived_partners(): void
    {
        $this->seed(DatabaseSeeder::class);
        $partner = Partner::whereNotNull('email')->firstOrFail();
        $count = Partner::count();
        $this->actingAs(User::where('role', 'worker')->firstOrFail());
        $this->patch(route('partners.archive', $partner))->assertRedirect();
        $archivedAt = $partner->fresh()->archived_at;
        $this->travel(1)->hour();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($count, Partner::count());
        $this->assertTrue($partner->fresh()->isArchived());
        $this->assertTrue($archivedAt->equalTo($partner->fresh()->archived_at));
        $this->assertSame(1, Partner::where('email', $partner->email)->count());
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

    public function test_capacity_demo_shows_all_capacity_and_stock_states_without_reseeding_completed_work(): void
    {
        User::factory()->create(['email' => 'worker@raktar.hu', 'role' => 'worker']);
        $this->seed(WarehouseCapacityDemoSeeder::class);
        $report = new WarehouseStatisticsReport(WarehouseStatistics::query()->get(), false);
        $this->assertSame([2135000, 590000, 1275000], $report->charts['weight']['parts']);
        $this->assertSame([10, 4, 4], $report->charts['pallets']['parts']);
        $this->assertSame([645, 275, 60], $report->charts['stock']['parts']);

        $transfer = InventoryTransfer::where('status', 'pending')->firstOrFail();
        app(InventoryService::class)->finalizeTransfer($transfer, true);
        Warehouse::whereKey($transfer->to_warehouse_id)->update(['warehouse_name' => 'Átnevezett bemutató raktár']);
        $before = $this->snapshot();
        $this->seed(WarehouseCapacityDemoSeeder::class);
        $this->assertSame($before, $this->snapshot());
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
