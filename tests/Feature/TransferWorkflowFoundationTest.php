<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryTransfer;
use App\Models\Product;
use App\Models\Stock;
use App\Models\TransferIntent;
use App\Models\TransferWorkflowEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMembership;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferWorkflowFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Warehouse $source;
    private Warehouse $destination;
    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['category_name' => 'Kötőelemek']);
        $this->product = Product::create([
            'category_id' => $category->getKey(),
            'product_name' => 'Csavar',
            'sku' => 'FOUNDATION-01',
            'price' => 1,
        ]);
        $this->source = Warehouse::create(['warehouse_name' => 'Forrásraktár']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Célraktár']);
        $this->requester = User::factory()->create(['role' => 'worker']);
    }

    public function test_intent_stores_pieces_and_resolves_all_business_relations_and_defaults(): void
    {
        $intent = $this->intent(['reason' => 'Utánpótlás', 'note' => 'Egyben kérjük.'])->refresh();

        $this->assertTrue($intent->product->is($this->product));
        $this->assertTrue($intent->fromWarehouse->is($this->source));
        $this->assertTrue($intent->toWarehouse->is($this->destination));
        $this->assertTrue($intent->requester->is($this->requester));
        $this->assertSame(25, $intent->quantity);
        $this->assertSame('normal', $intent->priority);
        $this->assertSame('groupable', $intent->shipping_preference);
        $this->assertSame('pending_approval', $intent->status);
        $this->assertSame(1, $intent->version);
        $this->assertSame('2027-01-20 13:30:00', $intent->requested_arrival_at->toDateTimeString());
        $this->assertSame('Utánpótlás', $intent->reason);
        $this->assertSame('Egyben kérjük.', $intent->note);
    }

    public function test_intent_persists_selected_priority_shipping_preference_and_nullable_text(): void
    {
        $intent = $this->intent(['priority' => 'critical', 'shipping_preference' => 'dedicated_requested', 'quantity' => 2147483647])->refresh();

        $this->assertSame('critical', $intent->priority);
        $this->assertSame('dedicated_requested', $intent->shipping_preference);
        $this->assertSame('pending_approval', $intent->status);
        $this->assertSame(2147483647, $intent->quantity);
        $this->assertNull($intent->reason);
        $this->assertNull($intent->note);
    }

    public function test_generated_references_are_stable_and_the_database_rejects_a_duplicate(): void
    {
        $first = $this->intent();
        $second = $this->intent();
        $this->assertMatchesRegularExpression('/^TR-[0-9A-HJKMNP-TV-Z]{26}$/i', $first->reference);
        $this->assertNotSame($first->reference, $second->reference);
        $this->assertSame($first->reference, $first->fresh()->reference);

        $row = $this->rawIntentRow($first, ['reference' => $first->reference]);
        $this->expectException(QueryException::class);
        DB::table('transfer_intents')->insert($row);
    }

    public static function invalidModelQuantities(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'fraction' => [1.5],
            'integral float' => [1.0],
            'nonnumeric' => ['egy'],
            'integer overflow' => [2147483648],
        ];
    }

    #[DataProvider('invalidModelQuantities')]
    public function test_intent_model_rejects_invalid_quantities_without_persisting(mixed $quantity): void
    {
        try {
            $this->intent(['quantity' => $quantity]);
            $this->fail('Invalid quantity was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertDatabaseCount('transfer_intents', 0);
    }

    public static function invalidDatabaseQuantities(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'integer overflow' => [2147483648],
        ];
    }

    #[DataProvider('invalidDatabaseQuantities')]
    public function test_database_rejects_invalid_quantities_even_without_model_validation(int $quantity): void
    {
        $row = $this->rawIntentRow($this->intent(), ['quantity' => $quantity]);
        $this->expectException(QueryException::class);
        DB::table('transfer_intents')->insert($row);
    }

    public function test_sqlite_database_does_not_accept_fractional_quantities_in_integer_column(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite type affinity is specific to the SQLite test driver.');
        }

        $row = $this->rawIntentRow($this->intent(), ['quantity' => 1.5]);
        $this->expectException(QueryException::class);
        DB::table('transfer_intents')->insert($row);
    }

    public function test_database_rejects_a_route_to_the_same_warehouse(): void
    {
        $row = $this->rawIntentRow($this->intent(), ['to_warehouse_id' => $this->source->getKey()]);
        $this->expectException(QueryException::class);
        DB::table('transfer_intents')->insert($row);
    }

    public static function invalidIntentFields(): array
    {
        return [
            'physical status' => ['status', 'in_transit'],
            'unsupported priority' => ['priority', 'express'],
            'unsupported shipping preference' => ['shipping_preference', 'dedicated'],
            'zero version' => ['version', 0],
        ];
    }

    #[DataProvider('invalidIntentFields')]
    public function test_database_checks_workflow_values_even_without_model_validation(string $field, mixed $value): void
    {
        $row = $this->rawIntentRow($this->intent(), [$field => $value]);
        $this->expectException(QueryException::class);
        DB::table('transfer_intents')->insert($row);
    }

    public function test_requested_arrival_is_optional(): void
    {
        $intent = $this->intent(['requested_arrival_at' => null]);

        $this->assertNull($intent->refresh()->requested_arrival_at);
    }

    public static function foreignKeys(): array
    {
        return [
            'product' => ['product_id'],
            'source warehouse' => ['from_warehouse_id'],
            'destination warehouse' => ['to_warehouse_id'],
            'requester' => ['requested_by'],
        ];
    }

    #[DataProvider('foreignKeys')]
    public function test_intent_database_rejects_missing_parents(string $field): void
    {
        $row = $this->rawIntentRow($this->intent(), [$field => 999999]);
        $this->expectException(QueryException::class);
        DB::table('transfer_intents')->insert($row);
    }

    public function test_user_can_have_different_local_roles_in_several_warehouses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dispatch = $admin->addWarehouseMembership($this->source->getKey(), ['dispatch_operator']);
        $receive = $admin->addWarehouseMembership($this->destination->getKey(), ['receiving_operator']);

        $this->assertCount(2, $admin->warehouseMemberships);
        $this->assertTrue($dispatch->user->is($admin));
        $this->assertTrue($dispatch->warehouse->is($this->source));
        $this->assertTrue($receive->warehouse->is($this->destination));
        $this->assertTrue($this->destination->memberships()->sole()->is($receive));
        $this->assertTrue($admin->hasWarehouseCapability('pick', $this->source->getKey()));
        $this->assertFalse($admin->hasWarehouseCapability('receive', $this->source->getKey()));
        $this->assertTrue($admin->hasWarehouseCapability('receive', $this->destination->getKey()));
        $this->assertFalse($admin->hasWarehouseCapability('pick', $this->destination->getKey()));
        $this->assertFalse($admin->hasWarehouseCapability('supervise', $this->destination->getKey()));
    }

    public function test_database_rejects_a_second_membership_in_the_same_warehouse(): void
    {
        $membership = [
            'user_id' => $this->requester->id,
            'warehouse_id' => $this->source->getKey(),
        ];
        $this->requester->addWarehouseMembership($this->source->getKey(), ['requester']);

        $this->expectException(QueryException::class);
        WarehouseMembership::create($membership);
    }

    public function test_events_store_actor_transition_timestamp_reason_and_structured_metadata(): void
    {
        $intent = $this->intent();
        $actor = User::factory()->create(['role' => 'manager']);
        $event = $this->event($intent, $actor, [
            'event_type' => 'approved',
            'from_status' => 'pending_approval',
            'to_status' => 'approved',
            'reason' => 'Szükséges utánpótlás',
            'metadata' => ['decision' => ['source' => 'internal', 'checks' => ['quantity', 'warehouse']]],
        ])->refresh();

        $this->assertTrue($event->intent->is($intent));
        $this->assertTrue($event->actor->is($actor));
        $this->assertTrue($intent->events()->sole()->is($event));
        $this->assertSame('approved', $event->event_type);
        $this->assertSame('pending_approval', $event->from_status);
        $this->assertSame('approved', $event->to_status);
        $this->assertSame('Szükséges utánpótlás', $event->reason);
        $this->assertSame(['decision' => ['source' => 'internal', 'checks' => ['quantity', 'warehouse']]], $event->metadata);
        $this->assertInstanceOf(CarbonInterface::class, $event->occurred_at);
    }

    public function test_operation_key_is_unique_across_intents(): void
    {
        $event = $this->event($this->intent(), $this->requester);
        $anotherIntent = $this->intent();

        $this->expectException(QueryException::class);
        $this->event($anotherIntent, $this->requester, ['operation_key' => $event->operation_key]);
    }

    public function test_database_prevents_bulk_event_update_and_delete(): void
    {
        $intent = $this->intent();
        $event = $this->event($intent, $this->requester);
        $events = DB::table('transfer_workflow_events')->where('transfer_intent_id', $intent->id);

        $this->assertDatabaseRejects(fn () => (clone $events)->update(['event_type' => 'altered']));
        $this->assertDatabaseRejects(fn () => (clone $events)->delete());

        $this->assertDatabaseHas('transfer_workflow_events', ['id' => $event->id, 'event_type' => 'created']);
    }

    public function test_sqlite_replace_cannot_rewrite_an_event_by_primary_key_or_operation_key(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite replacement syntax is specific to the SQLite test driver.');
        }

        $event = $this->event($this->intent(), $this->requester);
        $insert = 'INSERT OR REPLACE INTO transfer_workflow_events (id, transfer_intent_id, event_type, actor_id, operation_key) VALUES (?, ?, ?, ?, ?)';
        $this->assertDatabaseRejects(fn () => DB::insert($insert, [$event->id, $event->transfer_intent_id, 'altered', $event->actor_id, (string) Str::uuid()]));
        $this->assertDatabaseRejects(fn () => DB::insert($insert, [$event->id + 1, $event->transfer_intent_id, 'altered', $event->actor_id, $event->operation_key]));

        $this->assertDatabaseCount('transfer_workflow_events', 1);
        $this->assertDatabaseHas('transfer_workflow_events', ['id' => $event->id, 'operation_key' => $event->operation_key, 'event_type' => 'created']);
    }

    public function test_removing_an_intent_or_audit_actor_does_not_cascade_to_events(): void
    {
        $intent = $this->intent();
        $actor = User::factory()->create(['role' => 'manager']);
        $event = $this->event($intent, $actor);

        $this->assertDatabaseRejects(fn () => DB::table('transfer_intents')->where('id', $intent->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('users')->where('id', $actor->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('users')->where('id', $this->requester->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('products')->where('product_id', $this->product->getKey())->delete());
        $this->assertDatabaseRejects(fn () => DB::table('warehouses')->where('warehouse_id', $this->source->getKey())->delete());
        $this->assertDatabaseRejects(fn () => DB::table('warehouses')->where('warehouse_id', $this->destination->getKey())->delete());

        $this->assertModelExists($intent);
        $this->assertModelExists($actor);
        $this->assertModelExists($event);
    }

    public function test_deleting_an_audit_actor_through_the_existing_model_returns_a_validation_error(): void
    {
        $actor = User::factory()->create(['role' => 'manager']);
        $event = $this->event($this->intent(), $actor);

        try {
            $actor->delete();
            $this->fail('The event actor was deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }

        $this->assertModelExists($actor);
        $this->assertModelExists($event);
    }

    public function test_foundation_records_leave_existing_inventory_placements_and_pending_capacity_unchanged(): void
    {
        $stock = Stock::create(['product_id' => $this->product->getKey(), 'warehouse_id' => $this->source->getKey(), 'quantity' => 30]);
        $sourceBin = $this->source->storageLocations()->create(['name' => 'Polc', 'code' => 'A-01', 'kind' => 'shelf', 'max_weight_grams' => 10000]);
        $sourceBin->placements()->create(['stock_id' => $stock->id, 'quantity' => 20, 'cargo_weight_grams' => 2500]);
        $destinationBin = $this->destination->storageLocations()->create(['name' => 'Polc', 'code' => 'B-01', 'kind' => 'shelf', 'max_weight_grams' => 10000]);
        InventoryTransfer::create([
            'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->destination->getKey(),
            'user_id' => $this->requester->id,
            'quantity' => 10,
            'status' => 'pending',
            'to_location_id' => $destinationBin->id,
            'cargo_weight_grams' => 2000,
        ]);
        $tables = ['stocks', 'warehouse_placements', 'warehouse_locations', 'inventory_movements', 'inventory_transfers', 'inventory_transfer_requests', 'shipments'];
        $snapshot = fn () => array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->all(), $tables);
        $before = $snapshot();

        $intent = $this->intent(['quantity' => 100]);
        $this->requester->addWarehouseMembership($this->source->getKey(), ['requester']);
        $this->event($intent, $this->requester);

        $this->assertEquals($before, $snapshot());
        $this->assertSame(2000, (int) $destinationBin->reservations()->sum('cargo_weight_grams'));
        $this->assertSame(100, $intent->refresh()->quantity);
    }

    private function intent(array $overrides = []): TransferIntent
    {
        return TransferIntent::create($overrides + [
            'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->destination->getKey(),
            'requested_by' => $this->requester->id,
            'quantity' => 25,
            'requested_arrival_at' => '2027-01-20 13:30:00',
        ]);
    }

    private function event(TransferIntent $intent, User $actor, array $overrides = []): TransferWorkflowEvent
    {
        return $intent->events()->create($overrides + [
            'event_type' => 'created',
            'to_status' => 'pending_approval',
            'actor_id' => $actor->id,
            'operation_key' => (string) Str::uuid(),
        ]);
    }

    private function rawIntentRow(TransferIntent $intent, array $overrides): array
    {
        $row = (array) DB::table('transfer_intents')->find($intent->id);
        unset($row['id']);

        return $overrides + ['reference' => 'TR-'.Str::ulid()] + $row;
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database accepted an integrity violation.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0] ?? null, ['23000', '45000']);
        }
    }
}
