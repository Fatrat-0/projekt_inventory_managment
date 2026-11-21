<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, TransferIntent, User, Warehouse, WarehouseLocation, WarehousePlacement};
use App\Queries\StockListing;
use App\Services\{InventoryService, SmartPicking, TransferIntentService, WarehouseCapacity, WarehouseStockAvailability};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WarehouseStockAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;
    private Product $product;
    private Warehouse $source;
    private Warehouse $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['category_name' => 'Kötőelemek']);
        $this->product = Product::create([
            'category_id' => $this->category->getKey(),
            'product_name' => 'Csavar',
            'sku' => 'STOCK-AVAILABILITY',
            'price' => 1,
        ]);
        $this->source = Warehouse::create(['warehouse_name' => 'Központi raktár']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Regionális raktár']);
    }

    public function test_totals_count_stock_once_and_group_multiple_placements_at_the_same_location(): void
    {
        $stock = $this->stock($this->source, 325);
        $first = $this->location($this->source, 'A-01');
        $second = $this->location($this->source, 'B-02');
        $this->place($stock, $first, 175);
        $this->place($stock, $first, 25);
        $this->place($stock, $second, 100);

        $result = $this->snapshot();

        $this->assertSame($stock->id, $result['stock_id']);
        $this->assertSame(325, $result['physical_quantity']);
        $this->assertSame(300, $result['assigned_quantity']);
        $this->assertSame(25, $result['unassigned_quantity']);
        $this->assertSame(0, $result['quarantined_quantity']);
        $this->assertSame(0, $result['source_reserved_quantity']);
        $this->assertSame(325, $result['free_quantity']);
        $this->assertSame(25, $result['direct_issue_quantity']);
        $this->assertSame(325, $result['pickable_quantity']);
        $this->assertTrue($result['consistent']);
        $this->assertSame([], $result['issues']);
        $this->assertCount(2, $result['locations']);

        $locations = array_column($result['locations'], null, 'location_id');
        $this->assertSame('A-01', $locations[$first->id]['code']);
        $this->assertSame('Polc A-01', $locations[$first->id]['name']);
        $this->assertTrue($locations[$first->id]['active']);
        $this->assertSame(200, $locations[$first->id]['stored_quantity']);
        $this->assertSame(200, $locations[$first->id]['available_quantity']);
        $this->assertNull($locations[$first->id]['reserved_quantity']);
        $this->assertSame(100, $locations[$second->id]['stored_quantity']);
        $this->assertSame(100, $locations[$second->id]['available_quantity']);
    }

    public function test_stock_and_location_totals_are_scoped_to_both_product_and_warehouse(): void
    {
        $sourceBin = $this->location($this->source, 'S-01');
        $destinationBin = $this->location($this->destination, 'D-01');
        $other = Product::create([
            'category_id' => $this->category->getKey(), 'product_name' => 'Alátét', 'sku' => 'OTHER-STOCK', 'price' => 1,
        ]);
        $this->place($this->stock($this->source, 12), $sourceBin, 9);
        $this->place($this->stock($this->destination, 100), $destinationBin, 80);
        $this->place($this->stock($this->source, 200, product: $other), $sourceBin, 190);

        $source = $this->snapshot();
        $destination = $this->snapshot($this->destination);

        $this->assertSame(12, $source['physical_quantity']);
        $this->assertSame(9, $source['assigned_quantity']);
        $this->assertSame(3, $source['unassigned_quantity']);
        $this->assertSame([$sourceBin->id], array_column($source['locations'], 'location_id'));
        $this->assertSame(100, $destination['physical_quantity']);
        $this->assertSame(80, $destination['assigned_quantity']);
        $this->assertSame(20, $destination['unassigned_quantity']);
        $this->assertSame([$destinationBin->id], array_column($destination['locations'], 'location_id'));
    }

    public function test_missing_stock_is_a_known_zero_balance_without_a_source_reservation(): void
    {
        $this->stock($this->destination, 20);
        $this->location($this->source, 'ÜRES');

        $result = $this->snapshot();

        $this->assertNull($result['stock_id']);
        $this->assertSame(0, $result['source_reserved_quantity']);
        foreach (['physical_quantity', 'quarantined_quantity', 'assigned_quantity', 'unassigned_quantity',
            'free_quantity', 'direct_issue_quantity', 'pickable_quantity'] as $field) {
            $this->assertSame(0, $result[$field], $field);
        }
        $this->assertTrue($result['consistent']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([], $result['locations']);
    }

    public function test_quarantine_and_inactive_bins_have_distinct_effects_on_free_direct_and_pickable_stock(): void
    {
        $stock = $this->stock($this->source, 100, 10);
        $active = $this->location($this->source, 'AKTÍV');
        $inactive = $this->location($this->source, 'INAKTÍV', active: false);
        $this->place($stock, $active, 30);
        $this->place($stock, $inactive, 50);

        $result = $this->snapshot();

        $this->assertTrue($result['consistent']);
        $this->assertSame(100, $result['physical_quantity']);
        $this->assertSame(10, $result['quarantined_quantity']);
        $this->assertSame(80, $result['assigned_quantity']);
        $this->assertSame(20, $result['unassigned_quantity']);
        $this->assertSame(40, $result['free_quantity']);
        $this->assertSame(10, $result['direct_issue_quantity']);
        $this->assertSame(40, $result['pickable_quantity']);
        $locations = array_column($result['locations'], null, 'location_id');
        $this->assertSame(30, $locations[$active->id]['available_quantity']);
        $this->assertFalse($locations[$inactive->id]['active']);
        $this->assertSame(50, $locations[$inactive->id]['stored_quantity']);
        $this->assertSame(0, $locations[$inactive->id]['available_quantity']);

        $this->actingAs(User::factory()->create(['role' => 'worker']));
        $this->getJson(route('movements.stock', ['product_id' => $stock->product_id, 'warehouse_id' => $stock->warehouse_id, 'details' => 1]))
            ->assertOk()->assertJsonPath('physical_quantity', 100)->assertJsonPath('direct_issue_quantity', 10)
            ->assertJsonPath('pickable_quantity', 40)->assertJsonPath('inactive_bin_quantity', 50);
        $this->get(route('stock.index'))->assertOk()->assertSee('Közvetlenül kiadható')
            ->assertSee('Kitárolható összesen 40 db')->assertSee('Inaktív rekeszben');
    }

    public function test_approved_intent_does_not_change_any_availability_or_create_a_source_reservation(): void
    {
        $stock = $this->stock($this->source, 325);
        $this->place($stock, $this->location($this->source, 'A-01'), 300);
        $admin = User::factory()->create(['role' => 'admin']);
        $before = $this->snapshot();
        $service = app(TransferIntentService::class);
        $created = $service->create($admin, [
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->destination->getKey(), 'quantity' => 200, 'operation_key' => (string) Str::uuid(),
        ]);
        $intent = TransferIntent::findOrFail($created['intent']['id']);
        $approved = $service->approve($admin, $intent, ['operation_key' => (string) Str::uuid(), 'version' => $intent->version]);

        $this->assertSame('approved', $approved['intent']['status']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, $before['source_reserved_quantity']);
        $this->assertSame(325, $before['free_quantity']);
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public function test_legacy_transit_and_destination_capacity_are_distinct_from_source_reservations(): void
    {
        $this->stock($this->source, 100);
        $this->stock($this->destination, 30);
        $target = $this->location($this->destination, 'D-01');
        $worker = User::factory()->create(['role' => 'worker']);
        $transfer = app(InventoryService::class)->startTransfer([
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->destination->getKey(), 'user_id' => $worker->id,
            'quantity' => 20, 'to_location_id' => $target->id, 'cargo_weight_kg' => 10,
        ]);

        $source = $this->snapshot();
        $destination = $this->snapshot($this->destination);
        $this->assertSame('pending', $transfer->status);
        $this->assertSame(10000, (int) $target->reservations()->sum('cargo_weight_grams'));
        $this->assertSame(80, $source['physical_quantity']);
        $this->assertSame(80, $source['free_quantity']);
        $this->assertSame(0, $source['source_reserved_quantity']);
        $this->assertSame(30, $destination['physical_quantity']);
        $this->assertSame(30, $destination['free_quantity']);
        $this->assertSame(0, $destination['source_reserved_quantity']);
        $this->assertSame([], $destination['locations']);
    }

    public static function inconsistentBalances(): array
    {
        return [
            'negative physical' => [-1, 0, null, 'negative_physical'],
            'quarantine exceeds physical' => [10, 11, null, 'invalid_quarantine'],
            'assigned exceeds physical' => [10, 0, 11, 'assigned_exceeds_usable'],
            'assigned overlaps quarantine' => [10, 3, 8, 'assigned_exceeds_usable'],
            'empty placement' => [10, 0, 0, 'invalid_placement_quantity'],
        ];
    }

    #[DataProvider('inconsistentBalances')]
    public function test_inconsistent_balances_expose_issues_and_never_offer_unsafe_stock(
        int $physical, int $quarantined, ?int $placed, string $issue,
    ): void {
        $stock = $this->stock($this->source, $physical, $quarantined);
        if ($placed !== null) {
            $this->place($stock, $this->location($this->source, 'A-01'), $placed);
        }
        $result = $this->snapshot();

        $this->assertFalse($result['consistent']);
        $this->assertContains($issue, $result['issues']);
        $this->assertSame($physical, $result['physical_quantity']);
        $this->assertNull($result['free_quantity']);
        $this->assertNull($result['direct_issue_quantity']);
        $this->assertNull($result['pickable_quantity']);
        $invalid = (new StockListing(Request::create('/stock', 'GET', ['stock_status' => 'unknown'])))->query()->sole();
        $this->assertNull($invalid->direct_issue_quantity);
        $this->assertNull($invalid->pickable_quantity);
        foreach ($result['locations'] as $location) {
            $this->assertNull($location['available_quantity']);
        }
        $this->assertSame($physical, $stock->refresh()->quantity);
    }

    public function test_placement_linked_to_another_warehouse_is_reported_without_exposing_a_foreign_pick_source(): void
    {
        $stock = $this->stock($this->source, 100);
        $local = $this->location($this->source, 'S-01');
        $foreign = $this->location($this->destination, 'D-01');
        $this->place($stock, $local, 50);
        $this->place($stock, $foreign, 10);

        $result = $this->snapshot();
        $this->assertFalse($result['consistent']);
        $this->assertContains('invalid_placement_location', $result['issues']);
        $this->assertSame([$local->id], array_column($result['locations'], 'location_id'));
        $this->assertNull($result['locations'][0]['available_quantity']);
        $this->assertNull($result['pickable_quantity']);
        $legacy = app(SmartPicking::class)->options($this->source->getKey(), $this->product->getKey(), 10);
        $this->assertFalse($legacy['consistent']);
        $this->assertSame(0, $legacy['available_quantity']);
        $this->assertSame([], $legacy['suggestion']);

        $worker = User::factory()->create(['role' => 'worker']);
        try {
            app(InventoryService::class)->startTransfer([
                'product_id' => $this->product->getKey(), 'user_id' => $worker->id, 'quantity' => 10,
                'from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => $this->destination->getKey(),
                'to_location_id' => $foreign->id, 'cargo_weight_kg' => 1,
                'source_allocations' => [['location_id' => $local->id, 'quantity' => 10]],
            ]);
            $this->fail('An inconsistent snapshot was accepted for a stock withdrawal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_allocations', $exception->errors());
        }
        $this->assertSame(100, $stock->refresh()->quantity);
        $this->assertSame(50, $local->placements()->sole()->quantity);
        $this->assertDatabaseCount('inventory_transfers', 0);
    }

    public function test_snapshot_and_picking_lookup_leave_physical_stock_placements_and_history_untouched(): void
    {
        $stock = $this->stock($this->source, 30, 5);
        $bin = $this->location($this->source, 'A-01');
        $this->place($stock, $bin, 20);
        $tables = ['stocks', 'warehouse_placements', 'inventory_movements', 'inventory_transfers', 'inventory_transfer_source_allocations'];
        $capture = fn () => array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->all(), $tables);
        $before = $capture();

        $result = $this->snapshot();
        $legacy = app(SmartPicking::class)->options($this->source->getKey(), $this->product->getKey(), 20);

        $this->assertSame(25, $result['pickable_quantity']);
        $this->assertSame($result['pickable_quantity'], $legacy['available_quantity']);
        $this->assertSame([['location_id' => null, 'quantity' => 5], ['location_id' => $bin->id, 'quantity' => 15]], $legacy['suggestion']);
        $this->assertEquals($before, $capture());
    }

    public function test_manual_issue_uses_direct_stock_and_a_picked_transfer_can_use_the_remaining_bin_quantity(): void
    {
        $stock = $this->stock($this->source, 100, 10);
        $bin = $this->location($this->source, 'A-01');
        $placement = $this->place($stock, $bin, 70);
        $worker = User::factory()->create(['role' => 'worker']);
        $inventory = app(InventoryService::class);
        $movement = [
            'warehouse_id' => $this->source->getKey(), 'product_id' => $this->product->getKey(),
            'user_id' => $worker->id, 'type' => 'out',
        ];

        $this->assertSame(20, $this->snapshot()['direct_issue_quantity']);
        try {
            $inventory->recordMovement($movement + ['quantity' => 21]);
            $this->fail('Manual issue exceeded the quantity without a bin.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        $this->assertSame(100, $stock->refresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);

        $inventory->recordMovement($movement + ['quantity' => 20]);
        $this->assertSame(0, $this->snapshot()['direct_issue_quantity']);
        $this->assertSame(70, $this->snapshot()['pickable_quantity']);
        $this->assertSame(70, $placement->refresh()->quantity);
        $inventory->startTransfer([
            'from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => $this->destination->getKey(),
            'product_id' => $this->product->getKey(), 'user_id' => $worker->id, 'quantity' => 15,
            'source_allocations' => [['location_id' => $bin->id, 'quantity' => 15]],
        ]);

        $result = $this->snapshot();
        $this->assertSame(65, $result['physical_quantity']);
        $this->assertSame(55, $result['assigned_quantity']);
        $this->assertSame(10, $result['unassigned_quantity']);
        $this->assertSame(0, $result['direct_issue_quantity']);
        $this->assertSame(55, $result['pickable_quantity']);
        $this->assertSame(55, $placement->refresh()->quantity);
    }

    public function test_releasing_a_bin_changes_direct_availability_but_preserves_the_physical_balance(): void
    {
        $stock = $this->stock($this->source, 30, 5);
        $bin = $this->location($this->source, 'A-01');
        $placement = $this->place($stock, $bin, 20);
        $before = $this->snapshot();

        app(WarehouseCapacity::class)->release($bin, $placement);
        $after = $this->snapshot();

        $this->assertSame(5, $before['direct_issue_quantity']);
        $this->assertSame(25, $after['direct_issue_quantity']);
        $this->assertSame(0, $after['assigned_quantity']);
        $this->assertSame($before['physical_quantity'], $after['physical_quantity']);
        $this->assertSame($before['free_quantity'], $after['free_quantity']);
        $this->assertSame($before['pickable_quantity'], $after['pickable_quantity']);
        $this->assertSame([], $after['locations']);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_locked_snapshot_uses_the_same_balances_and_rechecks_the_transactional_state(): void
    {
        $stock = $this->stock($this->source, 30, 5);
        $placement = $this->place($stock, $this->location($this->source, 'A-01'), 20);
        $initial = $this->snapshot();

        DB::transaction(function () use ($stock, $placement, $initial) {
            $product = Product::whereKey($this->product->getKey())->lockForUpdate()->firstOrFail();
            app(WarehouseCapacity::class)->lockWarehouses([$this->source->getKey()]);
            $service = app(WarehouseStockAvailability::class);
            $read = fn () => $service->snapshotForUpdate($this->source->getKey(), $product->getKey());

            $this->assertSame($initial, $read());
            $placement->update(['quantity' => 15]);
            $stock->update(['quantity' => 25]);
            $current = $read();
            $this->assertSame(25, $current['physical_quantity']);
            $this->assertSame(15, $current['assigned_quantity']);
            $this->assertSame(5, $current['direct_issue_quantity']);
            $this->assertSame(20, $current['pickable_quantity']);
        });
    }

    private function snapshot(?Warehouse $warehouse = null): array
    {
        $snapshot = app(WarehouseStockAvailability::class)->snapshot(($warehouse ?? $this->source)->getKey(), $this->product->getKey());
        if ($snapshot['stock_id'] !== null) {
            $listed = WarehouseStockAvailability::listingRows()->where('balances.id', $snapshot['stock_id'])->first();
            foreach (['direct_issue_quantity', 'pickable_quantity', 'assigned_quantity', 'staged_quantity', 'source_reserved_quantity', 'pending_putaway_quantity'] as $field) {
                $this->assertSame($snapshot[$field], $listed->$field === null ? null : (int) $listed->$field, $field);
            }
        }

        return $snapshot;
    }

    private function stock(Warehouse $warehouse, int $quantity, int $quarantined = 0, ?Product $product = null): Stock
    {
        return Stock::create([
            'warehouse_id' => $warehouse->getKey(),
            'product_id' => ($product ?? $this->product)->getKey(),
            'quantity' => $quantity,
            'quarantined_quantity' => $quarantined,
        ]);
    }

    private function location(Warehouse $warehouse, string $code, bool $active = true): WarehouseLocation
    {
        return $warehouse->storageLocations()->create([
            'code' => $code,
            'name' => 'Polc '.$code,
            'kind' => 'shelf',
            'max_weight_grams' => 100000,
            'active' => $active,
        ]);
    }

    private function place(Stock $stock, WarehouseLocation $location, int $quantity): WarehousePlacement
    {
        return $location->placements()->create([
            'stock_id' => $stock->id, 'quantity' => $quantity, 'cargo_weight_grams' => 10 * max(0, $quantity),
        ]);
    }
}
