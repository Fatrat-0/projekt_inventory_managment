<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Shipment, ShipmentIntentAssignment, ShipmentPlanOperation, Stock, TransferIntent, User, Warehouse, WarehouseLocation};
use App\Services\{InventoryService, TransferIntentService, TransferStockReservationService, WarehouseTransferTaskService};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ShipmentPlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $origin;
    private Warehouse $pecs;
    private Warehouse $szekszard;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
        $this->origin = Warehouse::create(['warehouse_name' => 'Központ']);
        $this->pecs = Warehouse::create(['warehouse_name' => 'Pécs']);
        $this->szekszard = Warehouse::create(['warehouse_name' => 'Szekszárd']);
        $category = Category::create(['category_name' => 'Kötőelemek']);
        $this->product = Product::create([
            'product_name' => 'Csavar', 'sku' => 'SHIP-PLAN-1', 'price' => 1, 'category_id' => $category->getKey(),
        ]);
        Stock::create(['warehouse_id' => $this->origin->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 400]);
    }

    public function test_three_original_intents_share_two_stops_without_any_stock_booking(): void
    {
        $a = $this->intent(200, $this->pecs);
        $b = $this->intent(50, $this->szekszard);
        $c = $this->intent(100, $this->pecs);
        $beforeStock = $this->stockState();

        $plan = $this->createPlan([$a, $b, $c]);
        $this->assertSame('draft', $plan->status);
        $this->assertSame('transfer_intent', $plan->planning_mode);
        $this->assertSame($this->origin->getKey(), (int) $plan->origin_warehouse_id);
        $this->assertNotNull($plan->planned_departure_at);
        $stops = $plan->stops()->get()->keyBy('warehouse_id');
        $this->assertCount(2, $stops);
        foreach ([[$a, $this->pecs, 200], [$b, $this->szekszard, 50], [$c, $this->pecs, 100]] as [$intent, $target, $quantity]) {
            $this->assertDatabaseHas('shipment_intent_assignments', [
                'shipment_id' => $plan->id, 'transfer_intent_id' => $intent->id,
                'shipment_stop_id' => $stops[$target->getKey()]->id, 'assigned_by' => $this->admin->id, 'detached_at' => null,
            ]);
            $this->assertSame($quantity, $intent->refresh()->quantity);
            $this->assertSame('approved', $intent->status);
        }
        $read = $this->getJson(route('shipment-plans.show', $plan))->assertOk()->assertJsonCount(3, 'assignments')->assertJsonCount(2, 'stops');
        $this->assertStringContainsString('no-store', $read->headers->get('Cache-Control'));
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('shipment_items', 0);
        $this->assertSame($beforeStock, $this->stockState());
        $this->getJson($this->eligibleUrl($this->origin))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_eligible_list_includes_unreserved_partial_and_picked_intents_and_checks_origin(): void
    {
        $unreserved = $this->intent(20, $this->pecs);
        $partial = $this->intent(20, $this->pecs);
        $picked = $this->intent(20, $this->szekszard);
        $otherOrigin = $this->intent(20, $this->szekszard, source: $this->pecs);
        $pending = $this->intent(20, $this->pecs, 'pending_approval');
        $rejected = $this->intent(20, $this->pecs, 'rejected');
        $cancelled = $this->intent(20, $this->pecs, 'cancelled');
        $this->prepare($partial, 5);
        $this->prepare($picked, 20);

        $eligible = $this->getJson($this->eligibleUrl($this->origin))->assertOk()->assertJsonCount(3, 'data');
        foreach ([$unreserved, $partial, $picked] as $intent) $eligible->assertJsonFragment(['reference' => $intent->reference]);
        foreach ([$otherOrigin, $pending, $rejected, $cancelled] as $intent) $eligible->assertJsonMissing(['reference' => $intent->reference]);
        $this->getJson($this->eligibleUrl($this->origin, ['search' => $partial->reference]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonFragment(['reference' => $partial->reference]);

        $beforeStock = $this->stockState();
        $plan = $this->createPlan();
        $this->attach($plan, [$unreserved, $partial, $picked])->assertOk()->assertJsonCount(3, 'assignments');
        $this->assertSame($beforeStock, $this->stockState());
        $this->assertDatabaseHas('warehouse_transfer_tasks', ['transfer_intent_id' => $partial->id, 'status' => 'in_progress', 'staged_quantity' => 5]);
        $this->assertDatabaseHas('warehouse_transfer_tasks', ['transfer_intent_id' => $picked->id, 'status' => 'picked', 'staged_quantity' => 20]);
    }

    public function test_full_replanning_preserves_source_stock_and_existing_destination_capacity_reservation(): void
    {
        foreach ([[$this->pecs, 7], [$this->szekszard, 11]] as [$warehouse, $quantity]) {
            Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => $quantity]);
        }
        $bin = WarehouseLocation::create([
            'warehouse_id' => $this->pecs->getKey(), 'code' => 'PLAN-PAL', 'name' => 'Foglalással terhelt raklaphely',
            'kind' => 'pallet', 'active' => true, 'max_weight_grams' => 200000, 'max_pallet_slots' => 4,
        ]);
        app(InventoryService::class)->startTransfer([
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->origin->getKey(),
            'to_warehouse_id' => $this->pecs->getKey(), 'quantity' => 5, 'user_id' => $this->admin->id,
            'to_location_id' => $bin->id, 'cargo_weight_grams' => 12000, 'pallet_slots' => 1,
        ]);

        $unreserved = $this->intent(200, $this->pecs);
        $partial = $this->intent(50, $this->szekszard);
        $picked = $this->intent(100, $this->pecs);
        $this->prepare($partial, 15);
        $this->prepare($picked, 100);
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $this->origin->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => 395]);
        $this->assertDatabaseHas('transfer_stock_reservations', ['transfer_intent_id' => $partial->id, 'status' => 'active', 'remaining_quantity' => 35]);
        $before = $this->stockState();
        $capacity = WarehouseLocation::withCapacityUsage()->findOrFail($bin->id);
        $this->assertSame([188000, 3], [$capacity->freeWeight(), $capacity->freeSlots()]);

        $plan = $this->createPlan();
        $this->assertSame($before, $this->stockState());
        $this->attach($plan, [$unreserved, $partial, $picked])->assertOk()->assertJsonCount(3, 'assignments')->assertJsonCount(2, 'stops');
        $this->assertSame($before, $this->stockState());
        $stops = $plan->stops()->get();
        $this->assertEqualsCanonicalizing([$this->pecs->getKey(), $this->szekszard->getKey()], $stops->pluck('warehouse_id')->all());
        $this->assertEqualsCanonicalizing([1, 2], $plan->intentAssignments()->active()->get()->countBy('shipment_stop_id')->values()->all());

        $readiness = $this->getJson(route('shipment-plans.readiness', $plan))->assertOk()
            ->assertJsonPath('stock_ready_count', 1)->assertJsonPath('can_dispatch', false);
        $rows = collect($readiness->json('intents'))->keyBy('reference');
        $this->assertContains('source_reservation_required', array_column($rows[$unreserved->reference]['blockers'], 'code'));
        $this->assertContains('picking_incomplete', array_column($rows[$partial->reference]['blockers'], 'code'));
        $this->assertSame(15, $rows[$partial->reference]['staged_quantity']);
        $this->assertSame(100, $rows[$picked->reference]['staged_quantity']);
        $this->assertTrue($rows[$picked->reference]['stock_ready']);

        $ordered = $stops->pluck('id')->all();
        $this->patchJson(route('shipment-plans.stops.update', $plan), $this->command($plan->refresh()->version, [
            'stop_ids' => array_reverse($ordered),
        ]))->assertOk();
        $this->assertSame(array_reverse($ordered), $plan->stops()->pluck('id')->all());
        $loading = $this->getJson(route('shipment-plans.loading-order', $plan))->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($ordered, array_column($loading->json('data'), 'id'));
        $this->assertSame($before, $this->stockState());

        $this->deleteJson(route('shipment-plans.intents.detach', [$plan, $partial]), $this->command($plan->refresh()->version, [
            'reason' => 'Átütemezés másik fuvarra.',
        ]))->assertOk();
        $this->getJson(route('shipment-plans.stops.show', $plan))->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($before, $this->stockState());

        $otherPlan = $this->createPlan();
        $this->attach($otherPlan, [$partial])->assertOk()->assertJsonCount(1, 'assignments')->assertJsonCount(1, 'stops');
        $this->getJson(route('shipment-plans.readiness', $otherPlan))->assertOk()->assertJsonPath('intents.0.staged_quantity', 15)
            ->assertJsonFragment(['code' => 'picking_incomplete']);
        $this->assertSame($before, $this->stockState());
        foreach ([$plan, $otherPlan] as $draft) {
            $this->postJson(route('shipments.start', $draft), ['version' => $draft->refresh()->version])->assertConflict();
            $this->assertSame('draft', $draft->refresh()->status);
            $this->assertNull($draft->started_at);
        }
        $this->assertSame($before, $this->stockState());
        $capacity = WarehouseLocation::withCapacityUsage()->findOrFail($bin->id);
        $this->assertSame([188000, 3], [$capacity->freeWeight(), $capacity->freeSlots()]);
    }

    public function test_invalid_batch_and_conflicts_leave_no_partial_assignments_stops_or_audit(): void
    {
        $approved = $this->intent(20, $this->pecs);
        $plan = $this->createPlan();
        $version = $plan->version;
        $before = $this->stockState();
        foreach ([
            [409, $this->intent(2, $this->szekszard, 'pending_approval')],
            [409, $this->intent(2, $this->szekszard, 'rejected')],
            [409, $this->intent(2, $this->szekszard, 'cancelled')],
            [422, $this->intent(2, $this->szekszard, source: $this->pecs)],
        ] as [$status, $invalid]) {
            $this->attach($plan, [$approved, $invalid])->assertStatus($status);
            $this->assertDatabaseCount('shipment_intent_assignments', 0);
            $this->assertDatabaseCount('shipment_stops', 0);
            $this->assertDatabaseCount('shipment_plan_operations', 1);
            $this->assertSame($version, $plan->refresh()->version);
        }
        $this->attach($plan, [$approved, $approved])->assertUnprocessable();
        $this->attach($plan, [$approved])->assertOk();
        $this->attach($plan->refresh(), [$approved])->assertConflict();
        $other = $this->createPlan();
        $this->attach($other, [$approved])->assertConflict();
        $this->assertDatabaseCount('shipment_intent_assignments', 1);
        $this->assertDatabaseCount('shipment_stops', 1);
        $this->assertSame($before, $this->stockState());
    }

    public function test_delivery_order_can_be_changed_and_loading_order_is_its_reverse(): void
    {
        $plan = $this->createPlan([$this->intent(10, $this->pecs), $this->intent(25, $this->szekszard)]);
        $ids = $plan->stops()->pluck('id')->all();
        $version = $plan->version;
        $before = $this->stockState();
        $this->getJson(route('shipment-plans.stops.show', $plan))->assertOk()->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.1.id', $ids[1]);
        $this->getJson(route('shipment-plans.loading-order', $plan))->assertOk()
            ->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('data.0.loading_position', 1)->assertJsonPath('data.1.id', $ids[0]);
        $update = route('shipment-plans.stops.update', $plan);
        $this->patchJson($update, $this->command($version, ['stop_ids' => [$ids[0]]]))->assertUnprocessable();
        $payload = $this->command($version, ['stop_ids' => array_reverse($ids)]);
        $result = $this->patchJson($update, $payload)->assertOk();
        $this->patchJson($update, $payload)->assertOk()->assertExactJson($result->json());
        $this->patchJson($update, array_replace($payload, ['stop_ids' => $ids]))->assertConflict();
        $this->patchJson($update, $this->command($version, ['stop_ids' => $ids]))->assertConflict();
        $this->assertSame(array_reverse($ids), $plan->stops()->pluck('id')->all());
        $this->getJson(route('shipment-plans.loading-order', $plan))->assertOk()->assertJsonPath('data.0.id', $ids[0]);
        $this->assertSame($before, $this->stockState());
        $this->assertDatabaseCount('shipment_plan_operations', 2);
    }

    public function test_a_failed_audit_write_rolls_back_all_valid_attachments_and_new_stops(): void
    {
        $plan = $this->createPlan();
        $a = $this->intent(20, $this->pecs);
        $b = $this->intent(20, $this->szekszard);
        $beforeStock = $this->stockState();
        Event::listen('eloquent.creating: '.ShipmentPlanOperation::class, function (ShipmentPlanOperation $operation) {
            $this->assertSame('attach', $operation->action);
            $this->assertDatabaseCount('shipment_intent_assignments', 2);
            $this->assertDatabaseCount('shipment_stops', 2);
            throw new RuntimeException('Szimulált naplózási hiba.');
        });

        $this->attach($plan, [$a, $b])->assertInternalServerError();
        $this->assertDatabaseCount('shipment_intent_assignments', 0);
        $this->assertDatabaseCount('shipment_stops', 0);
        $this->assertDatabaseCount('shipment_plan_operations', 1);
        $this->assertSame($plan->version, $plan->fresh()->version);
        $this->assertSame($beforeStock, $this->stockState());
    }

    public function test_dedicated_preference_requires_an_explicit_audited_grouping_decision(): void
    {
        $dedicated = $this->intent(20, $this->pecs, preference: 'dedicated_requested');
        $other = $this->intent(20, $this->szekszard);
        $plan = $this->createPlan([$dedicated]);
        $this->attach($plan, [$other])->assertUnprocessable()->assertJsonValidationErrors('grouping_reason');
        $this->assertDatabaseCount('shipment_intent_assignments', 1);

        $planner = $this->member($this->origin, 'supervisor');
        $reason = 'Közös jármű, elkülönített rakományhely.';
        $this->actingAs($planner);
        $this->attach($plan, [$other], ['grouping_reason' => $reason])->assertOk();
        $this->assertDatabaseHas('shipment_plan_operations', ['shipment_id' => $plan->id, 'actor_id' => $planner->id, 'reason' => $reason]);
        $detail = $this->getJson(route('shipment-plans.show', $plan))->assertOk()->assertJsonCount(2, 'operations');
        $this->assertSame($reason, collect($detail->json('operations'))->firstWhere('action', 'attach')['reason']);
        $this->assertArrayNotHasKey('request_hash', $detail->json('operations.0'));
        $this->assertArrayNotHasKey('result', $detail->json('operations.0'));
        $this->assertDatabaseHas('shipment_intent_assignments', ['transfer_intent_id' => $other->id, 'assigned_by' => $planner->id]);
        $this->assertSame('dedicated_requested', $dedicated->refresh()->shipping_preference);
    }

    public function test_detaching_preserves_staging_retains_the_audit_and_allows_a_different_plan(): void
    {
        $intent = $this->intent(30, $this->pecs);
        $this->prepare($intent, 12);
        $plan = $this->createPlan([$intent]);
        $beforeStock = $this->stockState();
        $reason = 'A jármű kiesett.';
        $url = route('shipment-plans.intents.detach', [$plan, $intent]);
        $payload = $this->command($plan->version, ['reason' => $reason]);
        $result = $this->deleteJson($url, $payload)->assertOk();
        $this->deleteJson($url, $payload)->assertOk()->assertExactJson($result->json());
        $this->assertDatabaseHas('shipment_intent_assignments', [
            'shipment_id' => $plan->id, 'transfer_intent_id' => $intent->id,
            'detached_by' => $this->admin->id, 'detach_reason' => $reason, 'active_intent_id' => null,
        ]);
        $this->assertDatabaseHas('shipment_plan_operations', ['shipment_id' => $plan->id, 'actor_id' => $this->admin->id, 'reason' => $reason]);
        $this->getJson(route('shipment-plans.stops.show', $plan))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->eligibleUrl($this->origin))->assertOk()->assertJsonFragment(['reference' => $intent->reference]);
        $this->getJson($this->eligibleUrl($this->origin, ['shipment_id' => $plan->id]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('shipment-plans.show', $plan))->assertOk()->assertJsonCount(0, 'assignments')
            ->assertJsonCount(1, 'detached_assignments')->assertJsonPath('detached_assignments.0.detach_reason', $reason);
        $this->attach($plan->refresh(), [$intent])->assertConflict();
        $alternative = $this->createPlan([$intent]);
        $this->assertDatabaseHas('shipment_intent_assignments', ['shipment_id' => $alternative->id, 'active_intent_id' => $intent->id]);
        $this->assertSame($beforeStock, $this->stockState());
    }

    public function test_readiness_uses_existing_reservation_and_picking_progress_even_while_attached(): void
    {
        $intent = $this->intent(40, $this->pecs);
        $plan = $this->createPlan([$intent]);
        $url = route('shipment-plans.readiness', $plan);
        $this->getJson($url)->assertOk()->assertJsonPath('stock_ready', false)->assertJsonPath('can_dispatch', false)
            ->assertJsonFragment(['code' => 'source_reservation_required']);
        $this->prepare($intent, 15);
        $this->getJson($url)->assertOk()->assertJsonPath('stock_ready', false)->assertJsonPath('intents.0.staged_quantity', 15)
            ->assertJsonFragment(['code' => 'picking_incomplete']);
        $this->pick($intent, 25);
        $beforeStock = $this->stockState();
        $this->getJson($url)->assertOk()->assertJsonPath('stock_ready', true)
            ->assertJsonPath('stock_status', 'ready')->assertJsonPath('intents.0.stock_ready', true)
            ->assertJsonPath('intents.0.staged_quantity', 40)->assertJsonPath('can_dispatch', false)
            ->assertJsonPath('transport_ready', false)->assertJsonPath('transport_status', 'incomplete');
        $this->assertSame($beforeStock, $this->stockState());

        // A forged old-format cargo row would let the legacy command try to book real stock.
        $plan->stops()->sole()->items()->create(['product_id' => $this->product->getKey(), 'quantity' => 1]);
        $this->postJson(route('shipments.start', $plan), ['version' => $plan->version])->assertConflict();
        $this->patchJson(route('shipments.cancel', $plan), ['version' => $plan->version])->assertConflict();
        $this->patchJson(route('shipments.receive', [$plan, $plan->stops()->sole()]))->assertConflict();
        $this->assertSame('draft', $plan->refresh()->status);
        $this->assertNull($plan->started_at);
        $this->assertSame($beforeStock, $this->stockState());
    }

    public function test_full_picking_reports_failure_when_the_underlying_inventory_is_inconsistent(): void
    {
        $intent = $this->intent(40, $this->pecs);
        $plan = $this->createPlan([$intent]);
        $this->prepare($intent, 40);
        $this->getJson(route('shipment-plans.readiness', $plan))->assertOk()->assertJsonPath('stock_ready', true);

        Stock::query()->sole()->update(['quantity' => 30]);
        $this->getJson(route('shipment-plans.readiness', $plan))->assertOk()->assertJsonPath('stock_ready', false)
            ->assertJsonPath('can_dispatch', false)->assertJsonFragment(['code' => 'stock_inconsistent']);
    }

    public function test_source_roles_are_enforced_and_approved_cancellation_requires_detaching_first(): void
    {
        $intent = $this->intent(20, $this->pecs);
        $plan = $this->createPlan();
        $foreign = $this->postJson(route('shipment-plans.store'), array_replace($this->createPayload(), [
            'origin_warehouse_id' => $this->pecs->getKey(), 'reference' => 'Idegen fuvarterv',
        ]))->assertCreated()->json('shipment.id');
        foreach ([[$this->origin, 'requester'], [$this->pecs, 'receiving_operator'], [$this->pecs, 'supervisor']] as [$warehouse, $role]) {
            $this->actingAs($this->member($warehouse, $role));
            $this->getJson(route('shipment-plans.show', $plan))->assertForbidden();
            $this->getJson($this->eligibleUrl($this->origin))->assertForbidden();
            $this->attach($plan, [$intent])->assertForbidden();
        }
        $this->actingAs($this->member($this->origin, 'dispatch_operator'));
        $this->getJson(route('shipment-plans.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $plan->id);
        $this->getJson(route('shipment-plans.show', $foreign))->assertForbidden();
        $this->getJson(route('shipment-plans.show', $plan))->assertOk();
        $this->getJson(route('shipment-plans.readiness', $plan))->assertOk()->assertJsonPath('stock_ready', false);
        $this->attach($plan, [$intent])->assertForbidden();
        $this->actingAs($this->member($this->origin, 'supervisor'));
        $this->attach($plan, [$intent])->assertOk();
        $cancel = route('transfer-intents.cancel', $intent);
        $cancelPayload = $this->command($intent->refresh()->version, ['reason' => 'Az igény megszűnt.']);
        $this->patchJson($cancel, $cancelPayload)->assertConflict();
        $this->deleteJson(route('shipment-plans.intents.detach', [$plan, $intent]), $this->command($plan->refresh()->version))->assertOk();
        $this->patchJson($cancel, $cancelPayload)->assertOk()->assertJsonPath('intent.status', 'cancelled');
    }

    public function test_create_and_attach_operations_preserve_original_results_under_replay(): void
    {
        $intent = $this->intent(20, $this->pecs);
        $payload = $this->createPayload();
        $url = route('shipment-plans.store');
        $created = $this->postJson($url, $payload)->assertCreated();
        $plan = Shipment::findOrFail($created->json('shipment.id'));
        $op = $this->command($plan->version, ['intent_ids' => [$intent->id]]);
        $attached = $this->postJson(route('shipment-plans.intents.attach', $plan), $op)->assertOk();
        $this->postJson($url, $payload)->assertCreated()->assertExactJson($created->json());
        $this->postJson($url, array_replace($payload, ['reference' => 'Másik referencia']))->assertConflict();
        $this->postJson(route('shipment-plans.intents.attach', $plan), $op)->assertOk()->assertExactJson($attached->json());
        $this->postJson($url, array_replace($payload, ['operation_key' => $op['operation_key']]))->assertConflict();
        $this->assertSame(2, ShipmentPlanOperation::count());
        $this->assertSame(1, ShipmentIntentAssignment::count());
    }

    public function test_database_rejects_a_second_active_assignment_without_relying_on_the_service(): void
    {
        $intent = $this->intent(20, $this->pecs);
        $this->createPlan([$intent]);
        $other = $this->createPlan();
        $stop = $other->stops()->create(['warehouse_id' => $this->pecs->getKey(), 'position' => 1]);
        $this->expectException(QueryException::class);
        ShipmentIntentAssignment::create([
            'shipment_id' => $other->id, 'shipment_stop_id' => $stop->id,
            'transfer_intent_id' => $intent->id, 'assigned_by' => $this->admin->id, 'assigned_at' => now(),
        ]);
    }

    public function test_database_refuses_a_stop_belonging_to_another_plan_and_keeps_audit_append_only(): void
    {
        $intent = $this->intent(20, $this->pecs);
        $plan = $this->createPlan([$intent]);
        $other = $this->createPlan();
        $second = $this->intent(20, $this->pecs);
        try {
            DB::transaction(fn () => $other->intentAssignments()->create([
                'shipment_stop_id' => $plan->stops()->sole()->id, 'transfer_intent_id' => $second->id,
                'assigned_by' => $this->admin->id, 'assigned_at' => now(),
            ]));
            $this->fail('A másik fuvar megállója FK-hibát kell okozzon.');
        } catch (QueryException) {
            $this->assertDatabaseCount('shipment_intent_assignments', 1);
        }
        $audit = ShipmentPlanOperation::where('shipment_id', $plan->id)->sole();
        $this->expectException(QueryException::class);
        $audit->update(['reason' => 'Utólagos módosítás.']);
    }

    private function intent(
        int $quantity, Warehouse $target, string $status = 'approved', ?Warehouse $source = null, string $preference = 'groupable',
    ): TransferIntent {
        $service = app(TransferIntentService::class);
        $result = $service->create($this->admin, [
            'operation_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => ($source ?? $this->origin)->getKey(), 'to_warehouse_id' => $target->getKey(),
            'quantity' => $quantity, 'shipping_preference' => $preference,
        ]);
        $intent = TransferIntent::findOrFail($result['intent']['id']);
        $decision = $this->command($intent->version, ['reason' => 'Teszt döntés']);
        match ($status) {
            'approved' => $service->approve($this->admin, $intent, $decision),
            'rejected' => $service->reject($this->admin, $intent, $decision),
            'cancelled' => $service->cancel($this->admin, $intent, $decision),
            'pending_approval' => null,
        };

        return $intent->refresh();
    }

    private function prepare(TransferIntent $intent, int $amount): void
    {
        app(TransferStockReservationService::class)->reserve($this->admin, $intent, $this->command($intent->refresh()->version));
        app(WarehouseTransferTaskService::class)->create($this->admin, $intent, $this->command($intent->refresh()->version));
        $this->pick($intent, $amount);
    }

    private function pick(TransferIntent $intent, int $amount): void
    {
        app(WarehouseTransferTaskService::class)->pick($this->admin, $intent, $this->command($intent->refresh()->version, [
            'quantity' => $amount, 'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => $amount]],
            'unassigned_confirmation' => ['found' => true, 'description' => 'A kiadási terület mellett.'],
        ]));
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $actor = User::factory()->create(['role' => 'worker']);
        $actor->warehouseMemberships()->create(['warehouse_id' => $warehouse->getKey(), 'role' => $role]);

        return $actor;
    }

    private function createPayload(array $intents = []): array
    {
        return [
            'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $this->origin->getKey(),
            'reference' => 'SH-001', 'planned_departure_at' => '2026-11-23T10:00:00Z', 'vehicle_label' => 'ABC-123',
            'intent_ids' => array_map(fn ($intent) => $intent->id, $intents),
        ];
    }

    private function createPlan(array $intents = []): Shipment
    {
        $id = $this->postJson(route('shipment-plans.store'), $this->createPayload($intents))->assertCreated()->json('shipment.id');

        return Shipment::findOrFail($id);
    }

    private function attach(Shipment $plan, array $intents, array $fields = [])
    {
        return $this->postJson(route('shipment-plans.intents.attach', $plan), $this->command($plan->version, $fields + [
            'intent_ids' => array_map(fn ($intent) => $intent->id, $intents),
        ]));
    }

    private function eligibleUrl(Warehouse $warehouse, array $filters = []): string
    {
        return route('shipment-plans.eligible', ['origin_warehouse_id' => $warehouse->getKey()] + $filters);
    }

    private function command(int $version, array $fields = []): array
    {
        return $fields + ['operation_key' => (string) Str::uuid(), 'version' => $version];
    }

    private function stockState(): array
    {
        $tables = ['stocks', 'inventory_movements', 'inventory_transfers', 'warehouse_placements',
            'transfer_stock_reservations', 'warehouse_transfer_tasks', 'warehouse_transfer_allocations'];

        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    }
}
