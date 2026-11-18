<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Stock, TransferIntent, TransferWorkflowEvent, User, Warehouse, WarehouseLocation, WarehousePlacement};
use App\Services\{InventoryService, ShipmentService, SmartPicking, SmartPutaway, TransferIntentService, TransferStockReservationService, TransferWorkflowOperations, WarehouseCapacity, WarehouseStockAvailability};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WarehouseTransferTaskTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $source;
    private Warehouse $target;
    private Product $product;
    private Stock $stock;
    private User $supervisor;
    private User $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $category = Category::create(['category_name' => 'Kötőelemek']);
        $this->product = Product::create(['category_id' => $category->getKey(), 'product_name' => 'Csavar', 'sku' => 'TASK-01', 'price' => 1]);
        $this->source = Warehouse::create(['warehouse_name' => 'Induló raktár']);
        $this->target = Warehouse::create(['warehouse_name' => 'Célraktár']);
        $this->stock = Stock::create(['warehouse_id' => $this->source->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 200]);
        $this->supervisor = $this->member($this->source, 'supervisor');
        $this->dispatcher = $this->member($this->source, 'dispatch_operator');
    }

    public function test_reserved_intent_creates_one_task_with_protected_read_scope_and_idempotent_creation(): void
    {
        $intent = $this->intent(150);
        $command = $this->command($intent->version);
        $this->actingAs($this->dispatcher)->postJson($this->taskUrl('store', $intent), $command)->assertForbidden();
        $response = $this->actingAs($this->supervisor)->postJson($this->taskUrl('store', $intent), $command)
            ->assertCreated()->assertJsonPath('task.status', 'pending')
            ->assertJsonPath('task.transfer_intent_id', $intent->id)
            ->assertJsonPath('task.stock_id', $this->stock->id)
            ->assertJsonPath('task.warehouse_id', $this->source->getKey())
            ->assertJsonPath('task.expected_quantity', 150)->assertJsonPath('task.staged_quantity', 0)
            ->assertJsonPath('task.created_by', $this->supervisor->id)
            ->assertJsonPath('event.event_type', 'picking_task_created');
        $this->postJson($this->taskUrl('store', $intent), $command)->assertCreated()->assertExactJson($response->json());
        $this->postJson($this->taskUrl('store', $intent), $this->command($intent->refresh()->version))->assertConflict();

        $this->actingAs($this->dispatcher)->getJson($this->taskUrl('show', $intent))->assertOk()
            ->assertJsonPath('task.id', $response->json('task.id'))->assertJsonPath('version', $intent->version);
        foreach ([$this->member($this->source, 'requester'), $this->member($this->target, 'receiving_operator'),
            $this->member($this->target, 'dispatch_operator'), $this->member($this->target, 'supervisor')] as $actor) {
            $this->actingAs($actor)->getJson($this->taskUrl('show', $intent))->assertForbidden();
            $this->getJson($this->taskUrl('recommendations', $intent))->assertForbidden();
            $this->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 1, $this->allocation(null, 1)))
                ->assertForbidden();
        }
        $this->assertDatabaseCount('warehouse_transfer_tasks', 1);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);
    }

    public static function incompleteIntents(): array
    {
        return ['pending' => [false], 'approved without a reservation' => [true]];
    }

    #[DataProvider('incompleteIntents')]
    public function test_task_requires_an_approved_intent_and_a_full_active_reservation(bool $approve): void
    {
        $intent = $this->intent(150, approve: $approve, reserve: false);
        $this->actingAs($this->supervisor)->postJson($this->taskUrl('store', $intent), $this->command($intent->version))->assertConflict();
        $this->assertDatabaseCount('warehouse_transfer_tasks', 0);
        $this->assertSame(0, $this->snapshot()['staged_quantity']);
    }

    public function test_own_reservation_can_be_picked_in_steps_and_remains_protected_without_changing_physical_stock(): void
    {
        $first = $this->bin('A-01', 100);
        $second = $this->bin('A-02', 100, weight: 25000, palletSlots: 2);
        $intent = $this->createTask($this->intent(150));
        $this->actingAs($this->dispatcher);

        $this->assertBalances(200, 150, 0, 50);
        $before = $this->snapshot();
        $recommendations = $this->getJson($this->taskUrl('recommendations', $intent).'?quantity=150')->assertOk();
        $this->assertSame([
            $this->allocation($first, 100), $this->allocation($second, 50),
        ], $recommendations->json('suggestion'));
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);

        $firstPick = $this->pickCommand($intent, 100, $this->allocation($first, 100));
        $this->postJson($this->taskUrl('picks', $intent), $firstPick + [
            'unassigned_confirmation' => ['found' => true, 'description' => 'Kiadási terület mellett.'],
        ])->assertUnprocessable()->assertJsonValidationErrors('unassigned_confirmation');
        $firstResult = $this->postJson($this->taskUrl('picks', $intent), $firstPick)->assertOk()
            ->assertJsonPath('event.event_type', 'stock_picked')
            ->assertJsonPath('task.status', 'in_progress')->assertJsonPath('task.staged_quantity', 100)
            ->assertJsonPath('task.performed_by', $this->dispatcher->id)
            ->assertJsonPath('reservation.status', 'active')->assertJsonPath('reservation.remaining_quantity', 50);
        $this->assertBalances(200, 50, 100, 50);
        $this->assertSame(0, $first->placements()->count());
        $this->assertSame(100000, WarehouseLocation::withCapacityUsage()->findOrFail($first->id)->freeWeight());
        $remainingSources = $this->getJson($this->taskUrl('recommendations', $intent))->assertOk()->json('sources');
        $remainingBin = collect($remainingSources)->firstWhere('location_id', $second->id);
        $this->assertSame(100, $remainingBin['stored_quantity']);
        $this->assertSame(50, $remainingBin['available_quantity']);
        $this->assertDatabaseHas('warehouse_transfer_allocations', [
            'warehouse_location_id' => $first->id, 'source_type' => 'location', 'location_code' => 'A-01',
            'quantity' => 100, 'cargo_weight_grams' => 1000, 'pallet_slots' => 0,
            'performed_by' => $this->dispatcher->id, 'transfer_workflow_event_id' => $firstResult->json('event.id'),
        ]);

        $secondPick = $this->pickCommand($intent, 50, $this->allocation($second, 50));
        $result = $this->postJson($this->taskUrl('picks', $intent), $secondPick)->assertOk()
            ->assertJsonPath('task.status', 'picked')->assertJsonPath('task.staged_quantity', 150)
            ->assertJsonPath('reservation.status', 'consumed')->assertJsonPath('reservation.remaining_quantity', 0);
        $this->assertNotNull($result->json('task.completed_at'));
        $this->assertBalances(200, 0, 150, 50);
        $this->assertSame(50, $second->placements()->sole()->quantity);
        $this->assertSame(25000, $second->placements()->sole()->cargo_weight_grams);
        $this->assertSame(2, $second->placements()->sole()->pallet_slots);
        $this->assertDatabaseHas('warehouse_transfer_allocations', [
            'warehouse_location_id' => $second->id, 'source_type' => 'location', 'quantity' => 50,
            'cargo_weight_grams' => null, 'pallet_slots' => null,
        ]);
        $this->getJson($this->taskUrl('show', $intent))->assertOk()->assertJsonCount(2, 'allocations');
        $this->postJson($this->taskUrl('picks', $intent), $firstPick)->assertOk()->assertExactJson($firstResult->json());
        $this->postJson($this->taskUrl('picks', $intent), array_replace($firstPick, ['reason' => 'Eltérő tartalom']))
            ->assertConflict();
        $this->assertDatabaseCount('warehouse_transfer_allocations', 2);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertNull(Stock::where('warehouse_id', $this->target->getKey())->first());

        $intent->refresh();
        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.stock-reservation.release', $intent), $this->command($intent->version))
            ->assertConflict();
        $this->patchJson(route('transfer-intents.cancel', $intent), $this->command($intent->version, ['reason' => 'Teszt']))
            ->assertConflict();
        $this->assertSame('approved', $intent->refresh()->status);
        $this->assertBalances(200, 0, 150, 50);
    }

    public function test_single_pick_combines_unassigned_stock_and_multiple_bins_with_explicit_source_types(): void
    {
        $first = $this->bin('A-01', 60);
        $second = $this->bin('A-02', 100);
        $intent = $this->createTask($this->intent(150));
        $this->actingAs($this->dispatcher);
        $this->assertSame([$this->allocation($second, 100), $this->allocation($first, 50)],
            $this->getJson($this->taskUrl('recommendations', $intent))->assertOk()->json('suggestion'));
        $this->postJson($this->taskUrl('picks', $intent), $this->pickCommand(
            $intent, 150, $this->allocation(null, 40), $this->allocation($first, 55), $this->allocation($second, 55),
        ) + ['unassigned_confirmation' => ['found' => true, 'description' => 'Beérkező áruk raklapján.']])
            ->assertOk()->assertJsonPath('task.status', 'picked');

        $this->assertBalances(200, 0, 150, 50);
        $this->assertSame(5, $first->placements()->sole()->quantity);
        $this->assertSame(45, $second->placements()->sole()->quantity);
        $this->assertDatabaseHas('warehouse_transfer_allocations', ['warehouse_location_id' => null, 'source_type' => 'unassigned', 'quantity' => 40]);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 3);
    }

    public function test_unassigned_stock_requires_physical_confirmation_and_keeps_one_auditable_pick(): void
    {
        $this->stock->update(['quantity' => 60]);
        $intent = $this->createTask($this->intent(10));
        $this->actingAs($this->dispatcher);
        $url = $this->taskUrl('picks', $intent);
        $payload = $this->pickCommand($intent, 10, $this->allocation(null, 10));
        $confirmation = ['found' => true, 'description' => 'A kiadási terület mellett.'];

        $options = $this->getJson($this->taskUrl('recommendations', $intent))->assertOk()
            ->assertJsonPath('suggestion', [])->assertJsonPath('available_quantity', 10);
        $options->assertJsonCount(1, 'sources')->assertJsonPath('sources.0.source_type', 'unassigned')
            ->assertJsonPath('sources.0.location_id', null)->assertJsonPath('sources.0.stored_quantity', 60)
            ->assertJsonPath('sources.0.available_quantity', 10)->assertJsonPath('sources.0.plan_url', null);

        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('unassigned_confirmation');
        foreach ([false, 'true', 1] as $falseConfirmation) {
            $this->postJson($url, $payload + ['unassigned_confirmation' => ['found' => $falseConfirmation] + $confirmation])
                ->assertUnprocessable()->assertJsonValidationErrors('unassigned_confirmation.found');
        }
        foreach (['  ', str_repeat('ő', 256)] as $invalidDescription) {
            $this->postJson($url, $payload + ['unassigned_confirmation' => ['description' => $invalidDescription] + $confirmation])
                ->assertUnprocessable()->assertJsonValidationErrors('unassigned_confirmation.description');
        }
        $this->postJson($url, $this->pickCommand($intent, 11, $this->allocation(null, 11)) + ['unassigned_confirmation' => $confirmation])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);
        $this->assertDatabaseMissing('transfer_workflow_events', ['event_type' => 'stock_picked']);
        $this->assertSame($payload['version'], $intent->refresh()->version);
        $this->assertBalances(60, 10, 0, 50);

        $saved = $this->postJson($url, $payload + ['unassigned_confirmation' => ['description' => '  '.$confirmation['description'].'  '] + $confirmation])
            ->assertOk()->assertJsonPath('task.status', 'picked')->assertJsonPath('reservation.remaining_quantity', 0)
            ->assertJsonPath('event.unassigned_confirmation', $confirmation)->assertJsonPath('event.actor_id', $this->dispatcher->id);
        $audit = TransferWorkflowEvent::findOrFail($saved->json('event.id'));
        $this->assertSame($confirmation, $audit->metadata['unassigned_confirmation']);
        $this->getJson($this->taskUrl('show', $intent))->assertOk()->assertJsonCount(1, 'allocations')
            ->assertJsonPath('allocations.0.source_type', 'unassigned')->assertJsonPath('allocations.0.warehouse_location_id', null)
            ->assertJsonPath('allocations.0.unassigned_confirmation', $confirmation)
            ->assertJsonPath('allocations.0.performed_by', $this->dispatcher->id)
            ->assertJsonPath('allocations.0.performer_name', $this->dispatcher->name)
            ->assertJsonPath('allocations.0.transfer_workflow_event_id', $audit->id);

        $this->postJson($url, $payload + ['unassigned_confirmation' => $confirmation])->assertOk()->assertExactJson($saved->json());
        $this->postJson($url, $payload + ['unassigned_confirmation' => ['description' => 'Másik hely.'] + $confirmation])->assertConflict();
        $this->postJson($url, array_replace($payload, ['operation_key' => (string) Str::uuid()]) + ['unassigned_confirmation' => $confirmation])
            ->assertConflict();
        $this->assertDatabaseCount('warehouse_transfer_allocations', 1);
        $this->assertSame(1, $intent->events()->where('event_type', 'stock_picked')->count());
        $this->assertDatabaseCount('warehouse_locations', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(50, $this->snapshot()['unassigned_quantity']);
        $this->assertBalances(60, 0, 10, 50);
    }

    public function test_bin_recommendations_can_cover_only_part_of_a_task_and_manual_stock_has_its_own_limit(): void
    {
        $this->stock->update(['quantity' => 60]);
        $first = $this->bin('A-01', 20);
        $second = $this->bin('A-02', 30);
        $intent = $this->createTask($this->intent(55));
        $this->actingAs($this->dispatcher);
        $url = $this->taskUrl('recommendations', $intent);

        $result = $this->getJson($url)->assertOk()->assertJsonPath('available_quantity', 55);
        $this->assertSame([$this->allocation($second, 30), $this->allocation($first, 20)], $result->json('suggestion'));
        $this->assertSame(10, collect($result->json('sources'))->firstWhere('source_type', 'unassigned')['available_quantity']);
        $this->assertSame([$this->allocation($second, 5)], $this->getJson($url.'?quantity=5')->assertOk()->json('suggestion'));

        $this->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 11, $this->allocation(null, 11)) + [
            'unassigned_confirmation' => ['found' => true, 'description' => 'Átmeneti tárolóterületen.'],
        ])->assertUnprocessable()->assertJsonValidationErrors('source_allocations');
        $this->assertBalances(60, 55, 0, 5);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);
    }

    public function test_legacy_unassigned_pick_can_only_be_replayed_with_the_same_original_command(): void
    {
        $intent = $this->createTask($this->intent(10));
        $this->actingAs($this->dispatcher);
        $url = $this->taskUrl('picks', $intent);
        $original = $this->pickCommand($intent, 4, $this->allocation(null, 4));
        // Insert the state and event shape stored before physical confirmation was introduced.
        $task = $intent->pickingTasks()->sole();
        $reservation = $task->reservation;
        $reservation->update(['remaining_quantity' => 6]);
        $task->update(['staged_quantity' => 4, 'status' => 'in_progress', 'performed_by' => $this->dispatcher->id, 'started_at' => now()]);
        $intent->forceFill(['version' => $original['version'] + 1])->save();
        $operations = app(TransferWorkflowOperations::class);
        $fingerprint = $operations->fingerprint('stock_picked', $this->dispatcher, $intent->id, [
            'version' => $original['version'], 'reason' => null, 'quantity' => 4,
            'allocations' => [['location_id' => null, 'quantity' => 4]],
        ]);
        $recorded = $operations->log($intent, $this->dispatcher, 'stock_picked', 'approved', null,
            $original['operation_key'], $fingerprint, $reservation, $task);
        $task->allocations()->create([
            'transfer_workflow_event_id' => $recorded['event']['id'], 'source_type' => 'unassigned', 'quantity' => 4,
            'performed_by' => $this->dispatcher->id, 'performed_at' => $recorded['event']['occurred_at'],
        ]);

        $this->postJson($url, $original)->assertOk()->assertExactJson($recorded);
        $this->getJson($this->taskUrl('show', $intent))->assertOk()->assertJsonCount(1, 'allocations')
            ->assertJsonPath('allocations.0.unassigned_confirmation', null);
        $this->postJson($url, $this->pickCommand($intent, 4, $this->allocation(null, 4)))
            ->assertUnprocessable()->assertJsonValidationErrors('unassigned_confirmation');
        $different = array_replace($original, ['quantity' => 5, 'source_allocations' => [$this->allocation(null, 5)]]);
        $this->postJson($url, $different)->assertConflict();
        $this->assertDatabaseCount('warehouse_transfer_allocations', 1);
        $this->assertBalances(200, 6, 4, 190);
    }

    public function test_task_offers_no_pick_when_the_only_source_becomes_unusable(): void
    {
        $this->stock->update(['quantity' => 60]);
        $bin = $this->bin('A-01', 60);
        $intent = $this->createTask($this->intent(10));
        // Simulate a source becoming unavailable after the original reservation.
        $bin->update(['active' => false]);
        $this->actingAs($this->dispatcher);

        $result = $this->getJson($this->taskUrl('recommendations', $intent))->assertOk()
            ->assertJsonPath('suggestion', [])->assertJsonPath('available_quantity', 0);
        $this->assertSame(0, collect($result->json('sources'))->firstWhere('source_type', 'unassigned')['available_quantity']);
        $unusableBin = collect($result->json('sources'))->firstWhere('location_id', $bin->id);
        $this->assertFalse($unusableBin['active']);
        $this->assertSame(0, $unusableBin['available_quantity']);
        $this->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 10, $this->allocation($bin, 10)))
            ->assertUnprocessable();
        $this->assertSame(60, $this->stock->refresh()->quantity);
        $this->assertSame(60, $bin->placements()->sole()->quantity);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);
    }

    public function test_recommendations_and_picks_ignore_inactive_foreign_and_wrong_product_locations_and_respect_other_holds(): void
    {
        $good = $this->bin('A-01', 70);
        $otherLocal = $this->bin('A-02', 70);
        $inactive = $this->bin('INAKTÍV', 20, active: false);
        $foreign = $this->target->storageLocations()->create(['code' => 'CÉL', 'name' => 'Másik raktár', 'kind' => 'shelf', 'max_weight_grams' => 100000]);
        $unrelatedProduct = Product::create(['category_id' => $this->product->category_id, 'product_name' => 'Alátét', 'sku' => 'TASK-OTHER', 'price' => 1]);
        $otherStock = Stock::create(['warehouse_id' => $this->source->getKey(), 'product_id' => $unrelatedProduct->getKey(), 'quantity' => 10]);
        $otherSku = $this->source->storageLocations()->create(['code' => 'MÁSIK', 'name' => 'Másik termék', 'kind' => 'shelf', 'max_weight_grams' => 100000]);
        $otherSku->placements()->create(['stock_id' => $otherStock->id, 'quantity' => 10, 'cargo_weight_grams' => 1000]);
        $this->intent(80);
        $intent = $this->createTask($this->intent(100));
        $this->actingAs($this->dispatcher);
        $suggestions = $this->getJson($this->taskUrl('recommendations', $intent).'?quantity=100')->assertOk()->json('suggestion');
        $this->assertSame([$this->allocation($good, 70), $this->allocation($otherLocal, 30)], $suggestions);

        foreach ([$inactive, $foreign, $otherSku] as $invalid) {
            $this->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 10, $this->allocation($invalid, 10)))
                ->assertUnprocessable();
        }
        $this->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 10, ['source_type' => 'location', 'location_id' => 999999, 'quantity' => 10]))
            ->assertUnprocessable();
        $this->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 71, $this->allocation($good, 71)))
            ->assertUnprocessable();
        $this->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 101, $this->allocation($good, 31), $this->allocation($otherLocal, 70)))
            ->assertUnprocessable();
        $this->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 1, ['source_type' => 'unassigned', 'location_id' => $good->id, 'quantity' => 1]))
            ->assertUnprocessable();
        $this->assertBalances(200, 180, 0, 0);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);
    }

    public function test_partial_and_full_stage_cannot_be_used_through_legacy_write_paths(): void
    {
        $first = $this->bin('A-01', 100);
        $second = $this->bin('A-02', 100);
        $intent = $this->createTask($this->intent(150));
        $this->actingAs($this->dispatcher)->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 100, $this->allocation($first, 100)))
            ->assertOk();
        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.stock-reservation.release', $intent), $this->command($intent->refresh()->version))
            ->assertConflict();
        $this->patchJson(route('transfer-intents.cancel', $intent), $this->command($intent->version, ['reason' => 'Teszt']))
            ->assertConflict();
        $this->assertBalances(200, 50, 100, 50);

        $this->actingAs($this->dispatcher)->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 50, $this->allocation($second, 50)))
            ->assertOk();
        $this->assertBalances(200, 0, 150, 50);
        $inventory = app(InventoryService::class);
        $this->assertIssueRejected(fn () => $inventory->recordMovement($this->movement(51)));
        $this->assertIssueRejected(fn () => $inventory->startTransfer($this->transfer(51)));
        $shipmentService = app(ShipmentService::class);
        $shipment = $shipmentService->save([
            'reference' => 'Tesztfuvar', 'origin_warehouse_id' => $this->source->getKey(),
            'stops' => [['warehouse_id' => $this->target->getKey(), 'items' => [['product_id' => $this->product->getKey(), 'quantity' => 51]]]],
        ], $this->dispatcher->id);
        $this->assertIssueRejected(fn () => $shipmentService->start($shipment, $this->dispatcher->id, $shipment->version));
        $this->assertSame('draft', $shipment->refresh()->status);

        $lookup = app(SmartPicking::class)->options($this->source->getKey(), $this->product->getKey(), 51);
        $this->assertSame(50, $lookup['available_quantity']);
        $this->assertSame([], $lookup['suggestion']);
        $this->assertIssueRejected(fn () => $inventory->startTransfer($this->transfer(51) + [
            'source_allocations' => [['location_id' => null, 'quantity' => 51]],
        ]));
        $first->refresh();
        $this->assertIssueRejected(fn () => app(WarehouseCapacity::class)->place($first, [
            'product_id' => $this->product->getKey(), 'quantity' => 1, 'cargo_weight_kg' => 1,
        ]));
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertSame(150, $this->snapshot()['staged_quantity']);
    }

    public static function cancellingOperations(): array
    {
        return [['release'], ['cancel']];
    }

    #[DataProvider('cancellingOperations')]
    public function test_pending_task_is_cancelled_when_its_unused_reservation_is_released(string $operation): void
    {
        $intent = $this->createTask($this->intent(150));
        $url = $operation === 'release'
            ? route('transfer-intents.stock-reservation.release', $intent)
            : route('transfer-intents.cancel', $intent);
        $this->patchJson($url, $this->command($intent->version, ['reason' => 'Teszt']))
            ->assertOk();

        $this->assertDatabaseHas('warehouse_transfer_tasks', ['transfer_intent_id' => $intent->id, 'status' => 'cancelled', 'staged_quantity' => 0]);
        $this->assertDatabaseHas('transfer_stock_reservations', ['transfer_intent_id' => $intent->id, 'status' => 'released', 'remaining_quantity' => 0]);
        $this->assertBalances(200, 0, 0, 200);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);
    }

    public function test_stale_version_cannot_pick_or_add_allocations(): void
    {
        $bin = $this->bin('A-01', 150);
        $intent = $this->createTask($this->intent(150));
        $payload = $this->pickCommand($intent, 20, $this->allocation($bin, 20));
        $this->actingAs($this->dispatcher)->postJson($this->taskUrl('picks', $intent), $payload)->assertOk();
        $this->postJson($this->taskUrl('picks', $intent), array_replace($payload, ['operation_key' => (string) Str::uuid()]))
            ->assertConflict();
        $this->assertSame(130, $bin->placements()->sole()->quantity);
        $this->assertBalances(200, 130, 20, 50);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 1);
    }

    public function test_failure_while_writing_pick_audit_rolls_back_bins_task_and_reservation(): void
    {
        $bin = $this->bin('A-01', 150);
        $intent = $this->createTask($this->intent(150));
        $version = $intent->version;
        $command = $this->pickCommand($intent, 50, $this->allocation($bin, 50));
        TransferWorkflowEvent::creating(static function (): never {
            throw new RuntimeException('pick workflow log failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->dispatcher)->postJson($this->taskUrl('picks', $intent), $command);
            $this->fail('The simulated event failure did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('pick workflow log failure', $exception->getMessage());
        }

        $this->assertSame($version, $intent->refresh()->version);
        $this->assertSame(150, $bin->placements()->sole()->quantity);
        $this->assertBalances(200, 150, 0, 50);
        $this->assertDatabaseHas('warehouse_transfer_tasks', ['transfer_intent_id' => $intent->id, 'status' => 'pending', 'staged_quantity' => 0]);
        $this->assertDatabaseMissing('transfer_workflow_events', ['operation_key' => $command['operation_key']]);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);
    }

    public function test_failure_while_writing_allocation_rolls_back_audit_and_every_other_write(): void
    {
        $bin = $this->bin('A-01', 150);
        $intent = $this->createTask($this->intent(150));
        $version = $intent->version;
        $command = $this->pickCommand($intent, 50, $this->allocation($bin, 50));
        \App\Models\WarehouseTransferAllocation::creating(static function (): never {
            throw new RuntimeException('pick allocation write failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->dispatcher)->postJson($this->taskUrl('picks', $intent), $command);
            $this->fail('The simulated allocation failure did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('pick allocation write failure', $exception->getMessage());
        }

        $this->assertSame($version, $intent->refresh()->version);
        $this->assertSame(150, $bin->placements()->sole()->quantity);
        $this->assertBalances(200, 150, 0, 50);
        $this->assertDatabaseHas('warehouse_transfer_tasks', ['transfer_intent_id' => $intent->id, 'status' => 'pending', 'staged_quantity' => 0]);
        $this->assertDatabaseMissing('transfer_workflow_events', ['operation_key' => $command['operation_key']]);
        $this->assertDatabaseCount('warehouse_transfer_allocations', 0);
    }

    public function test_staging_leaves_destination_capacity_reservations_and_putaway_advice_unchanged(): void
    {
        $this->stock->update(['quantity' => 225]);
        $target = $this->target->storageLocations()->create([
            'code' => 'C-01', 'name' => 'Célrekesz', 'kind' => 'shelf', 'max_weight_grams' => 100000,
        ]);
        $legacy = app(InventoryService::class)->startTransfer($this->transfer(25) + [
            'to_location_id' => $target->id, 'cargo_weight_kg' => 20,
        ]);
        $bin = $this->bin('A-01', 200);
        $intent = $this->createTask($this->intent(150));
        $recommend = fn () => app(SmartPutaway::class)->recommend(
            $this->target, $this->product, ['quantity' => 150, 'cargo_weight_kg' => 25, 'pallet_slots' => 0],
        );
        $before = $recommend();
        $this->actingAs($this->dispatcher)->postJson($this->taskUrl('picks', $intent), $this->pickCommand($intent, 150, $this->allocation($bin, 150)))
            ->assertOk();

        $this->assertBalances(200, 0, 150, 50);
        $this->assertSame($before, $recommend());
        $this->assertSame($legacy->id, $target->reservations()->sole()->id);
        $this->assertSame(80000, WarehouseLocation::withCapacityUsage()->findOrFail($target->id)->freeWeight());
        $this->assertDatabaseCount('inventory_transfers', 1);
        $this->assertDatabaseMissing('stocks', ['warehouse_id' => $this->target->getKey()]);
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->warehouseMemberships()->create(['warehouse_id' => $warehouse->getKey(), 'role' => $role]);

        return $user;
    }

    private function intent(int $quantity, bool $approve = true, bool $reserve = true): TransferIntent
    {
        $service = app(TransferIntentService::class);
        $created = $service->create($this->supervisor, [
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->target->getKey(), 'quantity' => $quantity, 'operation_key' => (string) Str::uuid(),
        ]);
        $intent = TransferIntent::findOrFail($created['intent']['id']);
        if ($approve) {
            $service->approve($this->supervisor, $intent, $this->command($intent->version));
            $intent->refresh();
        }
        if ($reserve) {
            app(TransferStockReservationService::class)->reserve($this->supervisor, $intent, $this->command($intent->version));
        }

        return $intent->refresh();
    }

    private function command(int $version, array $overrides = []): array
    {
        return $overrides + ['operation_key' => (string) Str::uuid(), 'version' => $version];
    }

    private function taskUrl(string $action, TransferIntent $intent): string
    {
        return route('transfer-intents.picking-task.'.$action, $intent);
    }

    private function createTask(TransferIntent $intent): TransferIntent
    {
        $this->actingAs($this->supervisor)->postJson($this->taskUrl('store', $intent), $this->command($intent->version))->assertCreated();

        return $intent->refresh();
    }

    private function allocation(?WarehouseLocation $location, int $quantity): array
    {
        return ['location_id' => $location?->id, 'quantity' => $quantity, 'source_type' => $location ? 'location' : 'unassigned'];
    }

    private function pickCommand(TransferIntent $intent, int $quantity, array ...$allocations): array
    {
        return $this->command($intent->refresh()->version, ['quantity' => $quantity, 'source_allocations' => $allocations]);
    }

    private function assertBalances(int $physical, int $reserved, int $staged, int $free): void
    {
        $snapshot = $this->snapshot();
        $this->assertTrue($snapshot['consistent']);
        $this->assertSame($physical, $snapshot['physical_quantity']);
        $this->assertSame($reserved, $snapshot['source_reserved_quantity']);
        $this->assertSame($staged, $snapshot['staged_quantity']);
        $this->assertSame($free, $snapshot['free_quantity']);
        $this->assertSame($free, $snapshot['pickable_quantity']);
    }

    private function bin(string $code, int $quantity, ?int $weight = null, bool $active = true, int $palletSlots = 0): WarehouseLocation
    {
        $location = $this->source->storageLocations()->create([
            'code' => $code, 'name' => 'Rekesz '.$code, 'active' => $active, 'kind' => $palletSlots > 0 ? 'pallet' : 'shelf',
            'max_weight_grams' => 100000, 'max_pallet_slots' => $palletSlots > 0 ? 4 : null,
        ]);
        $location->placements()->create(['stock_id' => $this->stock->id, 'quantity' => $quantity, 'cargo_weight_grams' => $weight ?? $quantity * 10, 'pallet_slots' => $palletSlots]);

        return $location;
    }

    private function snapshot(): array
    {
        return app(WarehouseStockAvailability::class)->snapshot($this->source->getKey(), $this->product->getKey());
    }

    private function movement(int $quantity): array
    {
        return ['product_id' => $this->product->getKey(), 'warehouse_id' => $this->source->getKey(),
            'user_id' => $this->dispatcher->id, 'type' => 'out', 'quantity' => $quantity];
    }

    private function transfer(int $quantity): array
    {
        return ['product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->target->getKey(), 'user_id' => $this->dispatcher->id, 'quantity' => $quantity];
    }

    private function assertIssueRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('A normal issue used staged or reserved stock.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
