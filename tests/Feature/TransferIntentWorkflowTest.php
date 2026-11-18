<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\TransferIntent;
use App\Models\TransferWorkflowEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TransferIntentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Warehouse $source;
    private Warehouse $destination;
    private User $requester;
    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $category = Category::create(['category_name' => 'Csavarok']);
        $this->product = Product::create(['product_name' => 'Csavar', 'sku' => 'INTENT-WORKFLOW', 'price' => 1, 'category_id' => $category->getKey()]);
        $this->source = Warehouse::create(['warehouse_name' => 'Forrásraktár']);
        $this->destination = Warehouse::create(['warehouse_name' => 'Célraktár']);
        $this->requester = $this->member($this->source, 'requester');
        $this->supervisor = $this->member($this->source, 'supervisor');
    }

    public function test_source_destination_and_central_admin_can_create_even_without_stock(): void
    {
        $destinationRequester = $this->member($this->destination, 'requester');
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([$this->requester, $destinationRequester, $admin] as $actor) {
            $data = $this->input(['requested_by' => $this->supervisor->id, 'status' => 'approved', 'version' => 5]);
            $id = $this->actingAs($actor)->postJson(route('transfer-intents.store'), $data)->assertCreated()
                ->assertJsonPath('intent.status', 'pending_approval')
                ->assertJsonPath('intent.priority', 'normal')
                ->assertJsonPath('intent.shipping_preference', 'groupable')
                ->assertJsonPath('intent.requested_arrival_at', null)
                ->assertJsonPath('intent.version', 1)
                ->assertJsonPath('event.event_type', 'created')
                ->assertJsonPath('event.actor_id', $actor->id)
                ->json('intent.id');
            $this->assertDatabaseHas('transfer_intents', ['id' => $id, 'requested_by' => $actor->id, 'quantity' => 200]);
            $this->assertDatabaseHas('transfer_workflow_events', [
                'transfer_intent_id' => $id, 'actor_id' => $actor->id, 'event_type' => 'created',
                'from_status' => null, 'to_status' => 'pending_approval', 'operation_key' => $data['operation_key'],
            ]);
        }

        $this->assertDatabaseCount('transfer_intents', 3);
        $this->assertDatabaseCount('stocks', 0);
    }

    public function test_global_worker_and_manager_have_no_implicit_warehouse_permission(): void
    {
        $this->postJson(route('transfer-intents.store'), $this->input())->assertUnauthorized();
        $unrelated = Warehouse::create(['warehouse_name' => 'Másik raktár']);
        foreach (['worker', 'manager'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $actor->warehouseMemberships()->create(['warehouse_id' => $unrelated->getKey(), 'role' => 'supervisor']);
            $this->actingAs($actor)->postJson(route('transfer-intents.store'), $this->input())->assertForbidden();
        }

        $this->assertDatabaseCount('transfer_intents', 0);
        $this->assertDatabaseCount('transfer_workflow_events', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            'unknown product' => ['product_id', 999999],
            'unknown source' => ['from_warehouse_id', 999999],
            'unknown destination' => ['to_warehouse_id', 999999],
            'zero pieces' => ['quantity', 0],
            'negative pieces' => ['quantity', -1],
            'fractional pieces' => ['quantity', 1.5],
            'too many pieces' => ['quantity', 2147483648],
            'invalid deadline' => ['requested_arrival_at', 'érvénytelen időpont'],
            'invalid priority' => ['priority', 'emergency'],
            'invalid key' => ['operation_key', 'not-a-uuid'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_creation_validates_its_inputs(string $key, mixed $value): void
    {
        $this->actingAs($this->requester)->postJson(route('transfer-intents.store'), $this->input([$key => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->assertDatabaseCount('transfer_intents', 0);
        $this->assertDatabaseCount('transfer_workflow_events', 0);
    }

    public function test_source_and_destination_must_be_different_even_when_input_types_differ(): void
    {
        $this->actingAs($this->requester)->postJson(route('transfer-intents.store'), $this->input([
            'to_warehouse_id' => (string) $this->source->getKey(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');

        $this->assertDatabaseCount('transfer_intents', 0);
    }

    public function test_only_the_source_supervisor_or_a_central_admin_can_approve_or_reject(): void
    {
        $intent = $this->createIntent();
        $destinationSupervisor = $this->member($this->destination, 'supervisor');
        $dispatcher = $this->member($this->source, 'dispatch_operator');
        foreach ([$destinationSupervisor, $dispatcher, $this->requester] as $actor) {
            $this->actingAs($actor)->patchJson(route('transfer-intents.approve', $intent), $this->operation($intent))->assertForbidden();
            $this->patchJson(route('transfer-intents.reject', $intent), $this->operation($intent, ['reason' => 'Indok']))->assertForbidden();
        }

        $this->assertSame('pending_approval', $intent->refresh()->status);
        $this->assertSame(1, $intent->events()->count());
        $decision = $this->operation($intent);
        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.approve', $intent), $decision)->assertOk()
            ->assertJsonPath('intent.status', 'approved')->assertJsonPath('intent.version', 2)
            ->assertJsonPath('event.from_status', 'pending_approval')->assertJsonPath('event.to_status', 'approved');
        $this->assertDatabaseHas('transfer_workflow_events', [
            'transfer_intent_id' => $intent->id, 'actor_id' => $this->supervisor->id, 'event_type' => 'approved',
            'from_status' => 'pending_approval', 'to_status' => 'approved', 'operation_key' => $decision['operation_key'],
        ]);

        $admin = User::factory()->create(['role' => 'admin']);
        $another = $this->createIntent();
        $this->actingAs($admin)->patchJson(route('transfer-intents.approve', $another), $this->operation($another))->assertOk()
            ->assertJsonPath('intent.status', 'approved');
    }

    public function test_rejection_requires_a_reason_and_is_terminal(): void
    {
        $intent = $this->createIntent();
        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.reject', $intent), $this->operation($intent, ['reason' => '   ']))
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $reason = 'Másik forrás szükséges.';
        $this->patchJson(route('transfer-intents.reject', $intent), $this->operation($intent, ['reason' => $reason]))
            ->assertOk()->assertJsonPath('intent.status', 'rejected')->assertJsonPath('intent.version', 2);
        $intent->refresh();
        $this->patchJson(route('transfer-intents.approve', $intent), $this->operation($intent))->assertConflict();
        $this->patchJson(route('transfer-intents.cancel', $intent), $this->operation($intent, ['reason' => 'Visszavonás']))->assertConflict();
        $this->assertSame(2, $intent->events()->count());
        $this->assertDatabaseHas('transfer_workflow_events', [
            'transfer_intent_id' => $intent->id, 'actor_id' => $this->supervisor->id,
            'event_type' => 'rejected', 'to_status' => 'rejected', 'reason' => $reason,
        ]);
    }

    public function test_requester_can_cancel_own_pending_intent_only_and_supervisor_can_cancel_approved(): void
    {
        $intent = $this->createIntent();
        $otherRequester = $this->member($this->source, 'requester');
        $destinationSupervisor = $this->member($this->destination, 'supervisor');
        foreach ([$otherRequester, $destinationSupervisor] as $actor) {
            $this->actingAs($actor)->patchJson(route('transfer-intents.cancel', $intent), $this->operation($intent, ['reason' => 'Módosult a terv']))
                ->assertForbidden();
        }
        $this->actingAs($this->requester)->patchJson(route('transfer-intents.cancel', $intent), $this->operation($intent))
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson(route('transfer-intents.cancel', $intent), $this->operation($intent, ['reason' => 'Már nincs rá szükség.']))
            ->assertOk()->assertJsonPath('intent.status', 'cancelled');
        $intent->refresh();
        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.approve', $intent), $this->operation($intent))->assertConflict();
        $this->assertDatabaseHas('transfer_workflow_events', [
            'transfer_intent_id' => $intent->id, 'actor_id' => $this->requester->id,
            'event_type' => 'cancelled', 'from_status' => 'pending_approval', 'reason' => 'Már nincs rá szükség.',
        ]);

        $approved = $this->createIntent();
        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.approve', $approved), $this->operation($approved))->assertOk();
        $approved->refresh();
        $this->actingAs($this->requester)->patchJson(route('transfer-intents.cancel', $approved), $this->operation($approved, ['reason' => 'Indok']))
            ->assertForbidden();
        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.cancel', $approved), $this->operation($approved, ['reason' => 'Változott a terv']))
            ->assertOk()->assertJsonPath('intent.status', 'cancelled')->assertJsonPath('intent.version', 3);
        $this->assertDatabaseHas('transfer_workflow_events', [
            'transfer_intent_id' => $approved->id, 'actor_id' => $this->supervisor->id,
            'event_type' => 'cancelled', 'from_status' => 'approved', 'reason' => 'Változott a terv',
        ]);
    }

    public function test_record_access_and_list_scope_follow_the_direction_of_each_membership(): void
    {
        $incoming = $this->createIntent();
        $outgoing = $this->createIntent(['from_warehouse_id' => $this->destination->getKey(), 'to_warehouse_id' => $this->source->getKey()]);
        $destinationDispatcher = $this->member($this->destination, 'dispatch_operator');
        $destinationReceiver = $this->member($this->destination, 'receiving_operator');
        $otherRequester = $this->member($this->destination, 'requester');
        $outsider = User::factory()->create(['role' => 'manager']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($destinationDispatcher)->getJson(route('transfer-intents.show', $outgoing))->assertOk()
            ->assertJsonPath('data.id', $outgoing->id)->assertJsonPath('data.events.0.event_type', 'created');
        $this->getJson(route('transfer-intents.show', $incoming))->assertForbidden();
        $this->getJson(route('transfer-intents.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $outgoing->id);

        $this->actingAs($destinationReceiver)->getJson(route('transfer-intents.show', $incoming))->assertOk()->assertJsonPath('data.id', $incoming->id);
        $this->getJson(route('transfer-intents.show', $outgoing))->assertForbidden();
        $this->getJson(route('transfer-intents.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $incoming->id);

        $this->actingAs($otherRequester)->getJson(route('transfer-intents.show', $incoming))->assertForbidden();
        $this->getJson(route('transfer-intents.index'))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->requester)->getJson(route('transfer-intents.index'))->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($outsider)->getJson(route('transfer-intents.show', $incoming))->assertForbidden();
        $this->getJson(route('transfer-intents.index'))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('total', 0);
        $this->actingAs($admin)->getJson(route('transfer-intents.index'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 2);

        $destinationDispatcher->warehouseMemberships()->create(['warehouse_id' => $this->source->getKey(), 'role' => 'dispatch_operator']);
        $this->actingAs($destinationDispatcher)->getJson(route('transfer-intents.index'))->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_creation_replay_returns_the_original_intent_and_rejects_changed_payload_or_actor(): void
    {
        $data = $this->input(['reason' => 'Utánpótlás', 'requested_arrival_at' => '2027-01-20 13:30:00']);
        $first = $this->actingAs($this->requester)->postJson(route('transfer-intents.store'), $data)->assertCreated();
        $intent = TransferIntent::findOrFail($first->json('intent.id'));
        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.approve', $intent), $this->operation($intent))->assertOk();

        $this->actingAs($this->requester)->postJson(route('transfer-intents.store'), $data)
            ->assertCreated()->assertExactJson($first->json());
        $this->postJson(route('transfer-intents.store'), array_replace($data, ['quantity' => 201]))->assertConflict();
        $otherRequester = $this->member($this->source, 'requester');
        $this->actingAs($otherRequester)->postJson(route('transfer-intents.store'), $data)->assertConflict();
        $this->assertDatabaseCount('transfer_intents', 1);
        $this->assertSame(2, $intent->events()->count());
    }

    public function test_action_replay_returns_original_version_but_a_new_key_cannot_repeat_approval(): void
    {
        $intent = $this->createIntent();
        $approve = $this->operation($intent);
        $first = $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.approve', $intent), $approve)
            ->assertOk()->assertJsonPath('intent.status', 'approved')->assertJsonPath('intent.version', 2);
        $this->patchJson(route('transfer-intents.approve', $intent), $approve)->assertOk()->assertExactJson($first->json());
        $this->patchJson(route('transfer-intents.approve', $intent), array_replace($approve, ['reason' => 'Új indok']))
            ->assertConflict();
        $this->patchJson(route('transfer-intents.reject', $intent), $approve + ['reason' => 'Más művelet'])->assertConflict();
        $this->patchJson(route('transfer-intents.approve', $intent), $this->operation($intent->refresh()))->assertConflict();

        $this->patchJson(route('transfer-intents.cancel', $intent), $this->operation($intent, ['reason' => 'Változott a terv']))->assertOk();
        $this->patchJson(route('transfer-intents.approve', $intent), $approve)->assertOk()->assertExactJson($first->json());
        $this->assertSame('cancelled', $intent->refresh()->status);
        $this->assertSame(3, $intent->version);
        $this->assertSame(3, $intent->events()->count());
    }

    public function test_stale_version_cannot_change_the_current_status_or_log_an_event(): void
    {
        $intent = $this->createIntent();
        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.approve', $intent), $this->operation($intent))->assertOk();
        $this->patchJson(route('transfer-intents.cancel', $intent), $this->operation($intent, ['version' => 1, 'reason' => 'Változás']))->assertConflict();
        $this->assertSame('approved', $intent->refresh()->status);
        $this->assertSame(2, $intent->version);
        $this->assertSame(2, $intent->events()->count());
    }

    public function test_failure_to_append_an_event_rolls_back_creation_and_state_transition(): void
    {
        $intent = $this->createIntent();
        $this->withoutExceptionHandling();
        TransferWorkflowEvent::creating(static function (): never {
            throw new RuntimeException('intent event write failed');
        });

        $operations = [
            fn () => $this->actingAs($this->requester)->postJson(route('transfer-intents.store'), $this->input()),
            fn () => $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.approve', $intent), $this->operation($intent)),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('The simulated event write failure did not occur.');
            } catch (RuntimeException $exception) {
                $this->assertSame('intent event write failed', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('transfer_intents', 1);
        $this->assertDatabaseCount('transfer_workflow_events', 1);
        $this->assertSame('pending_approval', $intent->refresh()->status);
        $this->assertSame(1, $intent->version);
    }

    public function test_all_intent_mutations_leave_inventory_and_shipment_tables_identical(): void
    {
        $stock = Stock::create(['product_id' => $this->product->getKey(), 'warehouse_id' => $this->source->getKey(), 'quantity' => 30]);
        $sourceBin = $this->source->storageLocations()->create(['name' => 'Polc', 'code' => 'A-01', 'kind' => 'shelf', 'max_weight_grams' => 10000]);
        $sourceBin->placements()->create(['stock_id' => $stock->id, 'quantity' => 20, 'cargo_weight_grams' => 2500]);
        $destinationBin = $this->destination->storageLocations()->create(['name' => 'Polc', 'code' => 'B-01', 'kind' => 'shelf', 'max_weight_grams' => 10000]);
        app(InventoryService::class)->startTransfer([
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->destination->getKey(), 'user_id' => $this->requester->id,
            'quantity' => 10, 'to_location_id' => $destinationBin->id, 'cargo_weight_grams' => 2000,
            'source_allocations' => [['location_id' => $sourceBin->id, 'quantity' => 10]],
        ], (string) Str::uuid());
        app(ShipmentService::class)->save([
            'reference' => 'Meglévő rakodási terv', 'origin_warehouse_id' => $this->source->getKey(),
            'stops' => [['warehouse_id' => $this->destination->getKey(), 'items' => [['product_id' => $this->product->getKey(), 'quantity' => 3]]]],
        ], $this->requester->id);
        app(InventoryService::class)->recordMovement([
            'product_id' => $this->product->getKey(), 'warehouse_id' => $this->source->getKey(), 'type' => 'in',
            'quantity' => 3, 'user_id' => $this->requester->id,
        ]);
        $tables = [
            'stocks', 'warehouse_placements', 'warehouse_locations', 'inventory_movements', 'inventory_transfers',
            'inventory_transfer_requests', 'inventory_transfer_source_allocations', 'shipments', 'shipment_stops', 'shipment_items',
        ];
        $snapshot = static fn () => array_map(static fn (string $table) => DB::table($table)->orderBy('id')->get()->all(), $tables);
        $before = $snapshot();
        $first = $this->createIntent();
        $second = $this->createIntent();

        $this->actingAs($this->supervisor)->patchJson(route('transfer-intents.approve', $first), $this->operation($first))->assertOk();
        $this->patchJson(route('transfer-intents.reject', $second), $this->operation($second, ['reason' => 'Elutasítás']))->assertOk();
        $this->patchJson(route('transfer-intents.cancel', $first), $this->operation($first->refresh(), ['reason' => 'Visszavonás']))->assertOk();
        $this->assertEquals($before, $snapshot());
        $this->assertSame(2000, (int) $destinationBin->reservations()->sum('cargo_weight_grams'));
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->warehouseMemberships()->create(['warehouse_id' => $warehouse->getKey(), 'role' => $role]);

        return $user;
    }

    private function input(array $overrides = []): array
    {
        return $overrides + [
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->source->getKey(),
            'to_warehouse_id' => $this->destination->getKey(), 'quantity' => 200, 'operation_key' => (string) Str::uuid(),
        ];
    }

    private function operation(TransferIntent $intent, array $overrides = []): array
    {
        return $overrides + ['version' => $intent->version, 'operation_key' => (string) Str::uuid()];
    }

    private function createIntent(array $overrides = []): TransferIntent
    {
        $response = $this->actingAs($this->requester)->postJson(route('transfer-intents.store'), $this->input($overrides))->assertCreated();

        return TransferIntent::findOrFail($response->json('intent.id'));
    }
}
