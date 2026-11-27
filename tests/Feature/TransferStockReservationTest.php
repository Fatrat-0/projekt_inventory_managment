<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, TransferIntent, TransferWorkflowEvent, User, Warehouse, WarehouseLocation};
use App\Services\{InventoryService, ShipmentService, SmartPicking, TransferIntentService, WarehouseCapacity, WarehouseStockAvailability};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TransferStockReservationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Warehouse $source;
    private Warehouse $target;
    private User $admin;
    private User $supervisor;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['category_name' => 'Kötőelemek']);
        $this->product = Product::create([
            'product_name' => 'Csavar', 'sku' => 'RESERVE-01', 'category_id' => $category->getKey(), 'price' => 1,
        ]);
        $this->source = Warehouse::create(['warehouse_name' => 'Központi raktár']);
        $this->target = Warehouse::create(['warehouse_name' => 'Célraktár']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->supervisor = $this->member($this->source, 'supervisor');
        $this->stock = Stock::create([
            'warehouse_id' => $this->source->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 200,
        ]);
    }

    public function test_full_reservation_and_release_are_warehouse_level_and_leave_physical_stock_unchanged(): void
    {
        $first = $this->location('A-01', 100);
        $second = $this->location('B-02', 60);
        $intent = $this->approved();
        $this->assertSame(200, $this->snapshot()['free_quantity']);
        $this->assertDatabaseCount('transfer_stock_reservations', 0);
        $this->actingAs($this->supervisor)->getJson($this->reservationUrl('show', $intent))
            ->assertOk()->assertJsonPath('status', 'unreserved')->assertJsonPath('reservation', null);

        $command = $this->command($intent, ['reason' => 'Fuvar előkészítéséhez.']);
        $reserved = $this->postJson($this->reservationUrl('store', $intent), $command)->assertCreated()
            ->assertJsonPath('intent.status', 'approved')->assertJsonPath('intent.version', 3)
            ->assertJsonPath('event.event_type', 'stock_reserved')->assertJsonPath('event.actor_id', $this->supervisor->id)
            ->assertJsonPath('reservation.transfer_intent_id', $intent->id)->assertJsonPath('reservation.stock_id', $this->stock->id)
            ->assertJsonPath('reservation.status', 'active')->assertJsonPath('reservation.quantity', 150)
            ->assertJsonPath('reservation.remaining_quantity', 150)->assertJsonPath('reservation.reserved_by', $this->supervisor->id);
        $this->getJson($this->reservationUrl('show', $intent))->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('version', 3);
        $this->assertDatabaseHas('transfer_workflow_events', ['transfer_intent_id' => $intent->id, 'operation_key' => $command['operation_key'], 'event_type' => 'stock_reserved']);

        $snapshot = $this->snapshot();
        $this->assertSame(200, $snapshot['physical_quantity']);
        $this->assertSame(160, $snapshot['assigned_quantity']);
        $this->assertSame(40, $snapshot['unassigned_quantity']);
        $this->assertSame(150, $snapshot['source_reserved_quantity']);
        $this->assertSame(50, $snapshot['free_quantity']);
        $this->assertSame(50, $snapshot['pickable_quantity']);
        $this->assertSame(40, $snapshot['direct_issue_quantity']);
        $bins = array_column($snapshot['locations'], null, 'location_id');
        $this->assertSame(100, $bins[$first->id]['available_quantity']);
        $this->assertSame(60, $bins[$second->id]['available_quantity']);
        $this->assertNull($bins[$first->id]['reserved_quantity']);
        $this->assertNull($bins[$second->id]['reserved_quantity']);

        $secondIntent = $this->approved();
        $error = $this->postJson($this->reservationUrl('store', $secondIntent), $this->command($secondIntent))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity')->json('errors.quantity.0');
        $this->assertStringContainsString('50', $error);
        $this->assertSame('approved', $secondIntent->refresh()->status);
        $this->assertDatabaseCount('transfer_stock_reservations', 1);

        $intent->refresh();
        $release = $this->command($intent, ['reason' => 'A fuvar még várhat.']);
        $released = $this->patchJson($this->reservationUrl('release', $intent), $release)->assertOk()
            ->assertJsonPath('intent.status', 'approved')->assertJsonPath('intent.version', 4)
            ->assertJsonPath('event.event_type', 'stock_released')->assertJsonPath('event.reason', $release['reason'])
            ->assertJsonPath('reservation.status', 'released')->assertJsonPath('reservation.quantity', 150)
            ->assertJsonPath('reservation.remaining_quantity', 0)->assertJsonPath('reservation.released_by', $this->supervisor->id);
        $this->patchJson($this->reservationUrl('release', $intent), $release)->assertOk()->assertExactJson($released->json());
        $this->patchJson($this->reservationUrl('release', $intent), $this->command($intent->refresh()))->assertConflict();
        $this->postJson($this->reservationUrl('store', $intent), $command)->assertCreated()->assertExactJson($reserved->json());
        $this->getJson($this->reservationUrl('show', $intent))->assertOk()->assertJsonPath('status', 'released');
        $this->assertSame(200, $this->snapshot()['free_quantity']);
        $this->assertSame(0, $this->snapshot()['source_reserved_quantity']);
        $this->assertSame(200, $this->stock->refresh()->quantity);
        $this->assertSame(100, $first->placements()->sole()->quantity);
        $this->assertSame(60, $second->placements()->sole()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertSame(4, $intent->events()->count());
    }

    public function test_an_approved_intent_can_reserve_again_after_release_without_replacing_the_history(): void
    {
        $intent = $this->approved();
        $this->reserve($intent);
        $this->patchJson($this->reservationUrl('release', $intent), $this->command($intent->refresh()))->assertOk();

        $this->reserve($intent->refresh());
        $this->assertDatabaseHas('transfer_stock_reservations', ['transfer_intent_id' => $intent->id, 'status' => 'released', 'remaining_quantity' => 0]);
        $this->assertDatabaseHas('transfer_stock_reservations', ['transfer_intent_id' => $intent->id, 'status' => 'active', 'remaining_quantity' => 150]);
        $this->assertDatabaseCount('transfer_stock_reservations', 2);
        $this->assertSame(150, $this->snapshot()['source_reserved_quantity']);
        $this->assertSame(200, $this->stock->refresh()->quantity);
    }

    public static function unavailableIntentStates(): array
    {
        return [['pending_approval'], ['rejected'], ['cancelled']];
    }

    #[DataProvider('unavailableIntentStates')]
    public function test_reservation_requires_an_approved_intent(string $state): void
    {
        $intent = $this->intent();
        $service = app(TransferIntentService::class);
        if ($state === 'rejected') $service->reject($this->supervisor, $intent, $this->command($intent, ['reason' => 'Elutasítva.']));
        if ($state === 'cancelled') $service->cancel($this->supervisor, $intent, $this->command($intent, ['reason' => 'Visszavonva.']));
        $intent->refresh();

        $this->actingAs($this->supervisor)->postJson($this->reservationUrl('store', $intent), $this->command($intent))->assertConflict();
        $this->assertDatabaseCount('transfer_stock_reservations', 0);
        $this->assertSame($state, $intent->refresh()->status);
    }

    public function test_aggregate_counts_only_pickable_stock_and_refuses_inconsistent_source_data(): void
    {
        $this->stock->update(['quarantined_quantity' => 10]);
        $this->location('INAKTÍV', 70, active: false);
        $active = $this->location('AKTÍV', 90);
        $intent = $this->approved(121);
        $this->actingAs($this->supervisor);

        $this->assertSame(120, $this->snapshot()['free_quantity']);
        $this->assertSame(30, $this->snapshot()['direct_issue_quantity']);
        $error = $this->postJson($this->reservationUrl('store', $intent), $this->command($intent))
            ->assertUnprocessable()->assertJsonValidationErrors('quantity')->json('errors.quantity.0');
        $this->assertStringContainsString('120', $error);
        $this->assertDatabaseCount('transfer_stock_reservations', 0);
        $this->assertSame('approved', $intent->refresh()->status);

        $active->placements()->update(['quantity' => 140]);
        $this->assertFalse($this->snapshot()['consistent']);
        $this->postJson($this->reservationUrl('store', $intent), $this->command($intent))->assertUnprocessable();
        $this->assertDatabaseCount('transfer_stock_reservations', 0);
        $this->assertSame(200, $this->stock->refresh()->quantity);
        $this->assertSame(2, $intent->events()->count());
    }

    public function test_reservation_commands_are_scoped_to_the_source_supervisor_or_global_admin(): void
    {
        $intent = $this->approved();
        $destinationSupervisor = $this->member($this->target, 'supervisor');
        $sourceDispatcher = $this->member($this->source, 'dispatch_operator');
        $outsider = User::factory()->create(['role' => 'manager']);
        $this->getJson($this->reservationUrl('show', $intent))->assertUnauthorized();
        foreach ([$destinationSupervisor, $sourceDispatcher, $outsider] as $actor) {
            $this->actingAs($actor)->postJson($this->reservationUrl('store', $intent), $this->command($intent))->assertForbidden();
        }
        $this->getJson($this->reservationUrl('show', $intent))->assertForbidden();
        $this->actingAs($destinationSupervisor)->getJson($this->reservationUrl('show', $intent))->assertOk()->assertJsonPath('status', 'unreserved');
        $this->actingAs($this->admin)->postJson($this->reservationUrl('store', $intent), $this->command($intent))->assertCreated();
        $intent->refresh();
        foreach ([$destinationSupervisor, $sourceDispatcher] as $actor) {
            $this->actingAs($actor)->patchJson($this->reservationUrl('release', $intent), $this->command($intent))->assertForbidden();
        }
        $this->actingAs($this->admin)->patchJson($this->reservationUrl('release', $intent), $this->command($intent))->assertOk();
    }

    public function test_replayed_reservation_requires_an_identical_command_and_new_mutations_require_the_current_version(): void
    {
        $intent = $this->approved();
        $command = $this->command($intent, ['reason' => 'Eredeti indok']);
        $this->actingAs($this->supervisor);
        $result = $this->postJson($this->reservationUrl('store', $intent), $command)->assertCreated();
        $this->postJson($this->reservationUrl('store', $intent), $command)->assertCreated()->assertExactJson($result->json());
        $this->postJson($this->reservationUrl('store', $intent), array_replace($command, ['reason' => 'Eltérő indok']))->assertConflict();
        $this->actingAs($this->admin)->postJson($this->reservationUrl('store', $intent), $command)->assertConflict();
        $this->patchJson($this->reservationUrl('release', $intent), $command)->assertConflict();
        $this->postJson($this->reservationUrl('store', $intent), $this->command($intent->refresh()))->assertConflict();
        $this->patchJson($this->reservationUrl('release', $intent), $this->command($intent, ['version' => 2]))->assertConflict();
        $this->assertDatabaseCount('transfer_stock_reservations', 1);
        $this->assertSame(3, $intent->events()->count());
        $this->assertSame(50, $this->snapshot()['free_quantity']);
    }

    public function test_cancellation_atomically_releases_the_active_hold_and_preserves_approved_snapshot_replay(): void
    {
        $intent = $this->approved();
        $this->reserve($intent);
        $command = $this->command($intent->refresh(), ['reason' => 'Meghiúsult a megrendelés.']);

        $response = $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.cancel', $intent), $command)
            ->assertOk()->assertJsonPath('intent.status', 'cancelled')->assertJsonPath('intent.version', 4);
        $this->patchJson(route('transfer-intents.cancel', $intent), $command)->assertOk()->assertExactJson($response->json());
        $this->assertDatabaseHas('transfer_stock_reservations', [
            'transfer_intent_id' => $intent->id, 'status' => 'released', 'remaining_quantity' => 0, 'released_by' => $this->supervisor->id,
        ]);
        $event = $intent->events()->where('event_type', 'cancelled')->sole();
        $this->assertSame('released', $event->metadata['reservation_snapshot']['status']);
        $this->assertSame(200, $this->snapshot()['free_quantity']);
        $this->assertSame(200, $this->stock->refresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public static function atomicCommands(): array
    {
        return [['reserve'], ['release'], ['cancel']];
    }

    #[DataProvider('atomicCommands')]
    public function test_an_event_write_failure_rolls_back_the_intent_version_and_reservation(string $action): void
    {
        $intent = $this->approved();
        if ($action !== 'reserve') {
            $this->reserve($intent);
            $intent->refresh();
        }
        $version = $intent->version;
        $eventCount = $intent->events()->count();
        $expectedReserved = $action === 'reserve' ? 0 : 150;
        $command = $this->command($intent, ['reason' => 'Tranzakciós próba.']);
        TransferWorkflowEvent::creating(static function (): never {
            throw new RuntimeException('reservation workflow log failure');
        });
        $this->withoutExceptionHandling();
        $this->actingAs($this->supervisor);

        try {
            match ($action) {
                'reserve' => $this->postJson($this->reservationUrl('store', $intent), $command),
                'release' => $this->patchJson($this->reservationUrl('release', $intent), $command),
                'cancel' => $this->patchJson(route('transfer-intents.cancel', $intent), $command),
            };
            $this->fail('The simulated audit error did not propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('reservation workflow log failure', $exception->getMessage());
        }

        $this->assertSame('approved', $intent->refresh()->status);
        $this->assertSame($version, $intent->version);
        $this->assertSame($eventCount, $intent->events()->count());
        $this->assertSame($expectedReserved, $this->snapshot()['source_reserved_quantity']);
        $this->assertDatabaseCount('transfer_stock_reservations', $action === 'reserve' ? 0 : 1);
        $this->assertDatabaseMissing('transfer_workflow_events', ['operation_key' => $command['operation_key']]);
        $this->assertSame(200, $this->stock->refresh()->quantity);
    }

    public function test_out_supplier_return_and_legacy_transfer_share_the_reserved_stock_guard(): void
    {
        $inventory = app(InventoryService::class);
        $this->stock->update(['quantity' => 0]);
        $receipt = $inventory->recordMovement($this->movementData('in', 200));
        $intent = $this->approved();
        $this->reserve($intent);

        $this->assertStockRejection(fn () => $inventory->recordMovement($this->movementData('out', 100)));
        $this->assertStockRejection(fn () => $inventory->recordReturn($receipt, [
            'quantity' => 100, 'return_reason' => 'Beszállítói visszaküldés', 'user_id' => $this->admin->id,
        ]));
        $this->assertStockRejection(fn () => $inventory->startTransfer($this->transferData(100)));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertSame(200, $this->stock->refresh()->quantity);

        $inventory->recordMovement($this->movementData('out', 50));
        $this->assertSame(150, $this->stock->refresh()->quantity);
        $this->assertSame(150, $this->snapshot()['source_reserved_quantity']);
        $this->assertSame(0, $this->snapshot()['direct_issue_quantity']);
    }

    public function test_multi_stop_shipment_rolls_back_earlier_items_if_a_later_stop_would_use_reserved_stock(): void
    {
        $otherTarget = Warehouse::create(['warehouse_name' => 'Másik célraktár']);
        $intent = $this->approved();
        $this->reserve($intent);
        $service = app(ShipmentService::class);
        $shipment = $service->save([
            'reference' => 'Kétmegállós fuvar', 'origin_warehouse_id' => $this->source->getKey(),
            'stops' => [
                ['warehouse_id' => $this->target->getKey(), 'items' => [['product_id' => $this->product->getKey(), 'quantity' => 25]]],
                ['warehouse_id' => $otherTarget->getKey(), 'items' => [['product_id' => $this->product->getKey(), 'quantity' => 26]]],
            ],
        ], $this->admin->id);

        $this->assertStockRejection(fn () => $service->start($shipment, $this->admin->id, $shipment->version));
        $this->assertSame('draft', $shipment->refresh()->status);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertSame(0, DB::table('shipment_items')->whereNotNull('transfer_id')->count());
        $this->assertSame(200, $this->stock->refresh()->quantity);
        $this->assertSame(50, $this->snapshot()['free_quantity']);
    }

    public function test_picking_is_capped_once_across_bins_and_manual_bin_release_does_not_free_the_reservation(): void
    {
        $first = $this->location('A-01', 100);
        $second = $this->location('B-02', 100);
        $intent = $this->approved();
        $this->reserve($intent);
        $inventory = app(InventoryService::class);
        $picking = app(SmartPicking::class);
        $options = $picking->options($this->source->getKey(), $this->product->getKey(), 51);
        $this->assertSame(50, $options['available_quantity']);
        $this->assertSame([], $options['suggestion']);
        $this->assertStockRejection(fn () => $inventory->startTransfer($this->transferData(51) + [
            'source_allocations' => [['location_id' => $first->id, 'quantity' => 30], ['location_id' => $second->id, 'quantity' => 21]],
        ]));
        $this->assertSame(100, $first->placements()->sole()->quantity);
        $this->assertSame(100, $second->placements()->sole()->quantity);
        $this->assertDatabaseCount('inventory_transfer_source_allocations', 0);

        app(WarehouseCapacity::class)->release($first, $first->placements()->sole());
        $this->assertSame(100, $this->snapshot()['unassigned_quantity']);
        $this->assertSame(50, $this->snapshot()['direct_issue_quantity']);
        $this->assertStockRejection(fn () => $inventory->recordMovement($this->movementData('out', 51)));
        $this->assertSame(200, $this->stock->refresh()->quantity);
        $transfer = $inventory->startTransfer($this->transferData(50) + [
            'source_allocations' => [['location_id' => $second->id, 'quantity' => 50]],
        ]);
        $this->assertSame(50, $transfer->sourceAllocations()->sole()->quantity);
        $this->assertSame(50, $second->placements()->sole()->quantity);
        $this->assertSame(150, $this->stock->refresh()->quantity);
        $this->assertSame(0, $this->snapshot()['free_quantity']);
    }

    public function test_manual_bin_placement_can_move_reserved_goods_inside_the_warehouse_without_freeing_the_hold(): void
    {
        $intent = $this->approved();
        $this->reserve($intent);
        $bin = $this->source->storageLocations()->create([
            'code' => 'A-01', 'name' => 'Elhelyezési polc', 'kind' => 'shelf', 'max_weight_grams' => 100000,
        ]);
        app(WarehouseCapacity::class)->place($bin, [
            'product_id' => $this->product->getKey(), 'quantity' => 100, 'cargo_weight_kg' => 10,
        ]);

        $this->assertSame(100, $bin->placements()->sole()->quantity);
        $this->assertSame(200, $this->stock->refresh()->quantity);
        $this->assertSame(150, $this->snapshot()['source_reserved_quantity']);
        $this->assertSame(50, $this->snapshot()['free_quantity']);
        $this->assertSame(50, $this->snapshot()['direct_issue_quantity']);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->addWarehouseMembership($warehouse->getKey(), [$role]);

        return $user;
    }

    private function intent(int $quantity = 150, ?User $actor = null): TransferIntent
    {
        $result = app(TransferIntentService::class)->create($actor ?? $this->admin, [
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->target->getKey(), 'quantity' => $quantity, 'operation_key' => (string) Str::uuid(),
        ]);

        return TransferIntent::findOrFail($result['intent']['id']);
    }

    private function approved(int $quantity = 150): TransferIntent
    {
        $intent = $this->intent($quantity);
        app(TransferIntentService::class)->approve($this->supervisor, $intent, $this->command($intent));

        return $intent->refresh();
    }

    private function command(TransferIntent $intent, array $overrides = []): array
    {
        return $overrides + ['version' => $intent->version, 'operation_key' => (string) Str::uuid()];
    }

    private function reservationUrl(string $action, TransferIntent $intent): string
    {
        return route('transfer-intents.stock-reservation.'.$action, $intent);
    }

    private function reserve(TransferIntent $intent): void
    {
        $this->actingAs($this->supervisor)->postJson($this->reservationUrl('store', $intent), $this->command($intent))->assertCreated();
    }

    private function snapshot(): array
    {
        return app(WarehouseStockAvailability::class)->snapshot($this->source->getKey(), $this->product->getKey());
    }

    private function transferData(int $quantity): array
    {
        return [
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->target->getKey(), 'user_id' => $this->admin->id, 'quantity' => $quantity,
        ];
    }

    private function movementData(string $type, int $quantity): array
    {
        return [
            'product_id' => $this->product->getKey(), 'warehouse_id' => $this->source->getKey(),
            'user_id' => $this->admin->id, 'type' => $type, 'quantity' => $quantity,
        ];
    }

    private function location(string $code, int $quantity, bool $active = true): WarehouseLocation
    {
        $bin = $this->source->storageLocations()->create([
            'code' => $code, 'name' => 'Polc '.$code, 'kind' => 'shelf', 'max_weight_grams' => 100000, 'active' => $active,
        ]);
        $bin->placements()->create(['stock_id' => $this->stock->id, 'quantity' => $quantity, 'cargo_weight_grams' => $quantity * 10]);

        return $bin;
    }

    private function assertStockRejection(callable $action): void
    {
        try {
            $action();
            $this->fail('The operation used reserved stock.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
