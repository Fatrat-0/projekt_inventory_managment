<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Product, Stock, TransferIntent, User, Warehouse, WarehouseLocation};
use App\Services\{TransferIntentService, TransferStockReservationService, WarehouseStockAvailability, WarehouseTransferTaskService};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualIssueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private Warehouse $warehouse;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'admin']);
        $category = Category::create(['category_name' => 'Kötőelemek']);
        $this->product = Product::factory()->create(['category_id' => $category->getKey()]);
        $this->warehouse = Warehouse::factory()->create();
        $this->stock = Stock::create(['product_id' => $this->product->getKey(), 'warehouse_id' => $this->warehouse->getKey(), 'quantity' => 60]);
        $this->actingAs($this->user);
    }

    public function test_direct_legacy_and_explicit_unassigned_issues_share_the_stock_limit_and_audit(): void
    {
        $legacy = $this->payload(5);
        $this->post(route('movements.store'), $legacy)->assertRedirect(route('movements.index'));
        $this->post(route('movements.store'), $legacy)->assertRedirect(route('movements.index'));
        $movement = InventoryMovement::sole();
        $this->getJson(route('movements.out.history', $movement))->assertOk()
            ->assertJsonPath('sources.0.source_type', 'unassigned')->assertJsonPath('sources.0.location_id', null)
            ->assertJsonPath('sources.0.quantity', 5)->assertJsonPath('stock_before', 60)->assertJsonPath('stock_after', 55);

        $source = $this->allocate(null, 5);
        unset($source['location_id']);
        $this->postJson(route('movements.store'), $this->payload(5, [$source]))->assertOk()
            ->assertJsonPath('stock_after', 50)->assertJsonPath('sources.0.source_type', 'unassigned');
        $this->assertDatabaseCount('inventory_movement_sources', 2);
    }

    public function test_mixed_issue_updates_stock_once_and_keeps_auditable_source_snapshots(): void
    {
        $first = $this->bin('A-01', 6);
        $this->place($first, $this->stock, 4);
        $second = $this->bin('A-02', 15);
        $payload = $this->payload(30, [$this->allocate($first, 10), $this->allocate($second, 15), $this->allocate(null, 5)]);
        $result = $this->postJson(route('movements.store'), $payload + ['user_id' => 999])->assertOk()
            ->assertJsonPath('quantity', 30)->assertJsonPath('product_id', $this->product->getKey())
            ->assertJsonPath('warehouse_id', $this->warehouse->getKey())->assertJsonPath('stock_before', 60)->assertJsonPath('stock_after', 30)
            ->assertJsonPath('user_id', $this->user->id)->assertJsonPath('user_name', $this->user->name)
            ->assertJsonPath('reference', 'KIAD-01')->assertJsonCount(3, 'sources');
        $sources = $result->json('sources');
        $this->assertSame([5, 10, 15], array_column($sources, 'quantity'));
        $this->assertSame([null, 'A-01', 'A-02'], array_column($sources, 'code'));
        $this->assertNotEmpty($result->json('created_at'));
        $movement = InventoryMovement::sole();
        $this->assertSame('Kézi kiadás', $movement->originLabel());
        $this->assertTrue($movement->isReturnable());
        $this->assertSame(30, $this->stock->refresh()->quantity);
        $this->assertDatabaseCount('warehouse_placements', 0);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);

        $first->update(['code' => 'ÚJ-KÓD', 'name' => 'Átnevezett polc']);
        $second->delete();
        $this->get(route('movements.show', $movement))->assertOk()->assertSee('Kiadási forráshelyek')
            ->assertSee('A-01')->assertSee('A-02')->assertSee('Tárhely nélküli készlet')
            ->assertSee(route('warehouses.locations.show', ['warehouse' => $this->warehouse, 'warehouseLocation' => $first]))
            ->assertDontSee(route('warehouses.locations.show', ['warehouse' => $this->warehouse, 'warehouseLocation' => $second]));
        $this->getJson(route('movements.out.history', $movement))->assertOk()->assertExactJson($result->json());
        $payload['source_allocations'] = array_reverse($payload['source_allocations']);
        $this->postJson(route('movements.store'), $payload)->assertOk()->assertExactJson($result->json());
        $payload['source_allocations'] = [$this->allocate(null, 6), $this->allocate($first, 9), $this->allocate($second, 15)];
        $this->postJson(route('movements.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_movement_requests', 1);
        $this->assertSame(30, $this->stock->refresh()->quantity);
    }

    public function test_only_bins_can_supply_an_issue_and_partial_load_remains_until_full_removal(): void
    {
        $first = $this->bin('A-01', 30);
        $second = $this->bin('A-02', 30);
        $placement = $first->placements()->sole();
        $this->postJson(route('movements.store'), $this->payload(35, [$this->allocate($first, 5), $this->allocate($second, 30)]))
            ->assertOk()->assertJsonCount(2, 'sources')->assertJsonPath('stock_after', 25);
        $this->assertSame(25, $placement->refresh()->quantity);
        $this->assertSame(12000, $placement->cargo_weight_grams);
        $this->assertSame(2, $placement->pallet_slots);
        $this->assertTrue($placement->load_requires_verification);
        $this->assertSame(88000, WarehouseLocation::withCapacityUsage()->findOrFail($first->id)->freeWeight());
        $this->assertSame(100000, WarehouseLocation::withCapacityUsage()->findOrFail($second->id)->freeWeight());

        $this->postJson(route('movements.store'), $this->payload(25, [$this->allocate($first, 25)]))
            ->assertOk()->assertJsonCount(1, 'sources')->assertJsonPath('stock_after', 0);
        $this->assertSame(100000, WarehouseLocation::withCapacityUsage()->findOrFail($first->id)->freeWeight());
        $this->assertSame(5, WarehouseLocation::withCapacityUsage()->findOrFail($first->id)->freeSlots());
        $this->assertDatabaseCount('warehouse_placements', 0);
    }

    public function test_advice_and_availability_validation_are_read_only_and_stale_advice_is_rechecked(): void
    {
        $first = $this->bin('A-01', 30);
        $this->bin('Z-01', 25, false);
        $input = [...$this->identity(), 'quantity' => 35];
        $options = $this->getJson(route('movements.out.sources', $input))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('physical_quantity', 60)->assertJsonPath('direct_issue_quantity', 5)
            ->assertJsonPath('available_quantity', 35)->assertJsonPath('sources.0.source_type', 'unassigned');
        $this->assertSame([5, 30], array_column($options->json('suggestion'), 'quantity'));
        $this->assertSame(0, collect($options->json('sources'))->firstWhere('code', 'Z-01')['available_quantity']);
        $suggestion = $options->json('suggestion');
        $this->postJson(route('movements.out.validate'), $input + ['source_allocations' => $suggestion])->assertOk()->assertExactJson(['valid' => true]);
        foreach (['inventory_movements', 'inventory_movement_requests', 'inventory_movement_sources', 'transfer_stock_reservations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(60, $this->stock->refresh()->quantity);
        $this->assertSame(30, $first->placements()->sole()->quantity);
        $this->getJson(route('movements.out.sources', array_replace($input, ['quantity' => 36])))->assertOk()
            ->assertJsonCount(0, 'suggestion')->assertJsonCount(2, 'partial_suggestion')
            ->assertJsonPath('partial_suggestion.0.quantity', 5)->assertJsonPath('partial_suggestion.1.quantity', 30);
        $this->postJson(route('movements.store'), $this->payload(1, [$this->allocate($first, 1)]))->assertOk();
        $this->postJson(route('movements.store'), $this->payload(35, $suggestion))->assertUnprocessable()->assertJsonValidationErrors('source_allocations');
        $this->assertSame(59, $this->stock->refresh()->quantity);
    }

    public function test_bin_advice_supplements_the_draft_without_exceeding_the_unreserved_limit(): void
    {
        $first = $this->bin('A-01', 20);
        $second = $this->bin('A-02', 20);
        $selected = [
            ['source_type' => 'unassigned', 'quantity' => 20],
            $this->allocate($first, 10),
        ];
        $input = $this->identity() + ['recommend_bins' => 1, 'quantity' => 45, 'source_allocations' => $selected];
        $this->getJson(route('movements.out.sources', $input))->assertOk()
            ->assertJsonCount(2, 'suggestion')->assertJsonPath('suggestion.0.location_id', $first->id)
            ->assertJsonPath('suggestion.0.quantity', 10)->assertJsonPath('suggestion.1.location_id', $second->id)
            ->assertJsonPath('suggestion.1.quantity', 5)
            ->assertJsonCount(0, 'partial_suggestion');
        $this->getJson(route('movements.out.sources', array_replace($input, ['quantity' => 70])))->assertOk()
            ->assertJsonCount(0, 'suggestion')
            ->assertJsonCount(2, 'partial_suggestion')
            ->assertJsonPath('partial_suggestion.0.quantity', 10)->assertJsonPath('partial_suggestion.1.quantity', 20);
        $this->getJson(route('movements.out.sources', array_replace($input, ['quantity' => 25])))->assertUnprocessable()
            ->assertJsonValidationErrors('source_allocations');
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('transfer_stock_reservations', 0);
        $this->assertSame(60, $this->stock->refresh()->quantity);

        $this->postJson(route('movements.store'), $this->payload(11, [$this->allocate($first, 11)]))->assertOk();
        $this->getJson(route('movements.out.sources', $input))->assertUnprocessable()
            ->assertJsonValidationErrors('source_allocations');
    }

    public static function invalidSources(): array
    {
        $loose = ['source_type' => 'unassigned', 'quantity' => 1];
        return [
            'missing source' => [[], 'source_allocations'], 'null source' => [null, 'source_allocations'],
            'no source type' => [[['quantity' => 1]], 'source_allocations.0.source_type'],
            'zero' => [[array_replace($loose, ['quantity' => 0])], 'source_allocations.0.quantity'],
            'negative' => [[array_replace($loose, ['quantity' => -1])], 'source_allocations.0.quantity'],
            'fractional' => [[array_replace($loose, ['quantity' => 1.2])], 'source_allocations.0.quantity'],
            'boolean' => [[array_replace($loose, ['quantity' => true])], 'source_allocations'],
            'missing bin id' => [[array_replace($loose, ['source_type' => 'location'])], 'source_allocations'],
            'fake unassigned id' => [[$loose + ['location_id' => 1]], 'source_allocations'],
            'different total' => [[array_replace($loose, ['quantity' => 2])], 'source_allocations'],
            'duplicate unassigned' => [[$loose, $loose], 'source_allocations'],
        ];
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_sources_are_rejected_by_preview_and_booking(mixed $sources, string $field): void
    {
        $payload = $this->payload(1) + ['source_allocations' => $sources];
        $this->postJson(route('movements.out.validate'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->postJson(route('movements.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(60, $this->stock->refresh()->quantity);
    }

    public function test_overdraw_inactive_wrong_warehouse_other_product_and_duplicate_bins_are_rejected(): void
    {
        $active = $this->bin('A-01', 5);
        $inactive = $this->bin('A-02', 5, false);
        $otherWarehouse = Warehouse::factory()->create();
        $foreign = $this->bin('A-01', warehouse: $otherWarehouse);
        $this->place($foreign, Stock::create(['warehouse_id' => $otherWarehouse->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 10]), 10);
        $otherProduct = $this->bin('X-01');
        $stock = Stock::create(['warehouse_id' => $this->warehouse->getKey(), 'product_id' => Product::factory()->create()->getKey(), 'quantity' => 5]);
        $this->place($otherProduct, $stock, 5);

        foreach ([[$this->allocate($active, 6)], [$this->allocate($inactive, 2)], [$this->allocate($foreign, 2)],
            [$this->allocate($otherProduct, 2)], [$this->allocate($active, 1), $this->allocate($active, 1)]] as $sources) {
            $this->postJson(route('movements.store'), $this->payload(array_sum(array_column($sources, 'quantity')), $sources))
                ->assertUnprocessable()->assertJsonValidationErrors('source_allocations');
        }
        $this->assertSame(60, $this->stock->refresh()->quantity);
        $this->assertDatabaseCount('inventory_movement_requests', 0);
        $this->assertSame(5, $active->placements()->sole()->quantity);
    }

    public function test_reservation_staged_stock_and_quarantine_are_protected_without_double_counting(): void
    {
        $this->stock->update(['quarantined_quantity' => 5]);
        $bin = $this->bin('A-01', 50);
        $destination = Warehouse::factory()->create();
        $intents = app(TransferIntentService::class);
        $intent = TransferIntent::findOrFail($intents->create($this->user, [
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->warehouse->getKey(),
            'to_warehouse_id' => $destination->getKey(), 'quantity' => 20, 'operation_key' => (string) Str::uuid(),
        ])['intent']['id']);
        $command = fn () => ['version' => $intent->refresh()->version, 'operation_key' => (string) Str::uuid()];
        $intents->approve($this->user, $intent, $command());
        app(TransferStockReservationService::class)->reserve($this->user, $intent, $command());
        $choices = route('movements.out.options', $this->identity() + ['quantity' => 100]);
        $this->getJson($choices)->assertOk()
            ->assertJsonPath('products.0.pickable_quantity', 35)
            ->assertJsonPath('warehouses.0.direct_issue_quantity', 5);
        $this->postJson(route('movements.store'), $this->payload(36, [$this->allocate($bin, 36)]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $task = app(WarehouseTransferTaskService::class);
        $task->create($this->user, $intent, $command());
        $task->pick($this->user, $intent, $command() + ['quantity' => 10, 'source_allocations' => [$this->allocate($bin, 10)]]);
        $this->getJson($choices)->assertOk()->assertJsonPath('products.0.pickable_quantity', 35);
        $this->postJson(route('movements.store'), $this->payload(36, [$this->allocate($bin, 36)]))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson(route('movements.store'), $this->payload(6, [$this->allocate(null, 6)]))
            ->assertUnprocessable()->assertJsonValidationErrors('source_allocations');
        $this->postJson(route('movements.store'), $this->payload(35, [$this->allocate($bin, 35)]))->assertOk();
        $balance = app(WarehouseStockAvailability::class)->snapshot($this->warehouse->getKey(), $this->product->getKey());
        $this->assertSame([25, 10, 10, 5, 0, true], [$balance['physical_quantity'], $balance['source_reserved_quantity'],
            $balance['staged_quantity'], $balance['quarantined_quantity'], $balance['free_quantity'], $balance['consistent']]);
        $this->assertSame(5, $bin->placements()->sole()->quantity);
        $this->getJson($choices)->assertOk()->assertExactJson(['products' => [], 'warehouses' => []]);
    }

    public function test_issue_choices_use_both_directions_and_only_warehouses_with_picking_access(): void
    {
        $this->bin('ACTIVE', 60);
        $inactive = Warehouse::factory()->create();
        $inactiveStock = Stock::create(['product_id' => $this->product->getKey(), 'warehouse_id' => $inactive->getKey(), 'quantity' => 10]);
        $this->place($this->bin('INACTIVE', 0, false, $inactive), $inactiveStock, 10);
        $other = Warehouse::factory()->create();
        $protected = Product::factory()->create(['category_id' => $this->product->category_id]);
        Stock::create(['product_id' => $protected->getKey(), 'warehouse_id' => $this->warehouse->getKey(), 'quantity' => 10, 'quarantined_quantity' => 10]);
        Stock::create(['product_id' => $protected->getKey(), 'warehouse_id' => $other->getKey(), 'quantity' => 12]);
        $zero = Product::factory()->create(['category_id' => $this->product->category_id]);
        Stock::create(['product_id' => $zero->getKey(), 'warehouse_id' => $this->warehouse->getKey(), 'quantity' => 0]);

        $worker = User::factory()->create(['role' => 'worker']);
        $worker->addWarehouseMembership($this->warehouse->getKey(), ['dispatch_operator']);
        $worker->addWarehouseMembership($other->getKey(), ['receiving_operator']);
        $this->actingAs($worker);
        $this->getJson(route('movements.out.options'))->assertOk()->assertExactJson([
            'products' => [['id' => (string) $this->product->getKey()]],
            'warehouses' => [['id' => (string) $this->warehouse->getKey()]],
        ]);
        $product = ['id' => (string) $this->product->getKey(), 'pickable_quantity' => 60, 'direct_issue_quantity' => 0];
        $warehouse = ['id' => (string) $this->warehouse->getKey(), 'pickable_quantity' => 60, 'direct_issue_quantity' => 0];
        $this->getJson(route('movements.out.options', ['warehouse_id' => $this->warehouse->getKey()]))
            ->assertOk()->assertJsonPath('products', [$product]);
        $this->getJson(route('movements.out.options', ['product_id' => $this->product->getKey()]))
            ->assertOk()->assertJsonPath('warehouses', [$warehouse]);
        $this->getJson(route('movements.out.options', ['warehouse_id' => $other->getKey()]))
            ->assertOk()->assertJsonCount(0, 'products');
        $this->actingAs($this->user)->getJson(route('movements.out.options'))->assertOk()
            ->assertJsonCount(2, 'warehouses')->assertJsonCount(2, 'products');
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_inconsistent_stock_and_inbound_source_data_are_rejected(): void
    {
        $bin = $this->bin('A-01', 61);
        $payload = $this->payload(5, [$this->allocate($bin, 5)]);
        $this->getJson(route('movements.out.sources', $this->identity() + ['quantity' => 5]))->assertOk()
            ->assertJsonPath('consistent', false)->assertJsonPath('direct_issue_quantity', null)->assertJsonCount(0, 'suggestion');
        $this->postJson(route('movements.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('source_allocations');
        $this->postJson(route('movements.store'), array_replace($payload, ['type' => 'in', 'confirmed' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('source_allocations');
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_late_audit_failure_rolls_back_all_writes_and_releases_the_request_key(): void
    {
        $first = $this->bin('A-01', 10);
        $second = $this->bin('A-02', 20);
        $payload = $this->payload(16, [$this->allocate(null, 1), $this->allocate($first, 10), $this->allocate($second, 5)]);
        DB::unprepared("CREATE TRIGGER reject_issue_audit BEFORE INSERT ON inventory_movement_sources
            WHEN NEW.location_code = 'A-02' BEGIN SELECT RAISE(ABORT, 'forced late audit failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->postJson(route('movements.store'), $payload);
            $this->fail('A kényszerített naplózási hibának meg kellett állítania a kiadást.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced late audit failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_issue_audit');
        }
        $this->assertSame(60, $this->stock->refresh()->quantity);
        $this->assertSame(10, $first->placements()->sole()->quantity);
        $this->assertSame(20, $second->placements()->sole()->quantity);
        $this->assertFalse($second->placements()->sole()->load_requires_verification);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_movement_sources', 0);
        $this->assertDatabaseCount('inventory_movement_requests', 0);
        $this->postJson(route('movements.store'), $payload)->assertOk()->assertJsonPath('stock_after', 44)->assertJsonCount(3, 'sources');
    }

    public function test_endpoints_share_movement_permissions_and_do_not_invent_history_for_old_rows(): void
    {
        $legacy = InventoryMovement::create($this->identity() + ['type' => 'out', 'user_id' => $this->user->id, 'quantity' => 1]);
        $inbound = InventoryMovement::create($this->identity() + ['type' => 'in', 'user_id' => $this->user->id, 'quantity' => 1]);
        $this->getJson(route('movements.out.history', $legacy))->assertOk()->assertJsonCount(0, 'sources');
        $this->getJson(route('movements.out.history', $inbound))->assertNotFound();
        $this->get(route('movements.show', $legacy))->assertOk()->assertSee('még nem történt forráshelyenkénti naplózás');
        $this->get(route('movements.show', $inbound))->assertOk()->assertDontSee('Kiadási forráshelyek');
        $options = route('movements.out.sources', $this->identity());
        $history = route('movements.out.history', $legacy);
        auth()->logout();
        $this->getJson($options)->assertUnauthorized();
        $this->getJson($history)->assertUnauthorized();
        $this->user->role = 'invalid';
        $this->actingAs($this->user);
        $this->getJson($options)->assertForbidden();
        $this->getJson($history)->assertForbidden();
        $this->postJson(route('movements.out.validate'), $this->payload(1, [$this->allocate(null, 1)]))->assertForbidden();
    }

    private function identity(): array
    {
        return ['product_id' => $this->product->getKey(), 'warehouse_id' => $this->warehouse->getKey()];
    }

    private function payload(int $quantity, ?array $sources = null): array
    {
        return $this->identity() + ['quantity' => $quantity, 'type' => 'out', 'reference' => 'KIAD-01', 'request_key' => (string) Str::uuid()]
            + ($sources === null ? [] : ['source_allocations' => $sources]);
    }

    private function allocate(?WarehouseLocation $location, int $quantity): array
    {
        return ['source_type' => $location ? 'location' : 'unassigned', 'location_id' => $location?->id, 'quantity' => $quantity];
    }

    private function bin(string $code, int $quantity = 0, bool $active = true, ?Warehouse $warehouse = null): WarehouseLocation
    {
        $bin = ($warehouse ?? $this->warehouse)->storageLocations()->create(['code' => $code, 'name' => 'Polc '.$code,
            'kind' => 'pallet', 'active' => $active, 'max_weight_grams' => 100000, 'max_pallet_slots' => 5]);
        if ($quantity > 0) $this->place($bin, $this->stock, $quantity);

        return $bin;
    }

    private function place(WarehouseLocation $bin, Stock $stock, int $quantity): void
    {
        $bin->placements()->create(['stock_id' => $stock->id, 'quantity' => $quantity, 'cargo_weight_grams' => 12000, 'pallet_slots' => 2]);
    }
}
