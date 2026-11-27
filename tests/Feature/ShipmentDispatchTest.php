<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, Product, Shipment, Stock, TransferIntent, User, Warehouse, WarehouseLocation};
use App\Services\{InventoryService, TransferIntentService, TransferStockReservationService, WarehouseStockAvailability, WarehouseTransferTaskService};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShipmentDispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $source;
    private Warehouse $pecs;
    private Warehouse $szekszard;
    private Product $screw;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
        $this->source = Warehouse::create(['warehouse_name' => 'Központ']);
        $this->pecs = Warehouse::create(['warehouse_name' => 'Pécs']);
        $this->szekszard = Warehouse::create(['warehouse_name' => 'Szekszárd']);
        $this->category = Category::create(['category_name' => 'Rögzítéstechnika']);
        $this->screw = $this->product('CS-01', 'Csavar');
    }

    public function test_a_plan_that_expires_after_the_preview_requires_an_audited_update_without_rebooking_cargo(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-23T14:23:57Z'));
        [$plan] = $this->readyPlan();
        $editUrl = route('shipment-plans.departure.update', $plan);
        $edit = $this->command($plan->refresh()->version, ['planned_departure_at' => '2026-11-23T15:23:00+01:00']);
        $this->patchJson($editUrl, $edit)->assertOk()->assertJsonPath('event.action', 'update_departure');

        $preview = $this->readiness($plan)->assertJsonPath('can_dispatch', true)->json();
        $version = $plan->refresh()->version;
        $dispatch = $this->command($version, ['confirmed' => true]);
        $tables = ['stocks', 'transfer_stock_reservations', 'warehouse_transfer_tasks', 'warehouse_transfer_allocations',
            'shipment_dispatches', 'shipment_transit_items', 'inventory_movements', 'shipment_plan_operations'];
        $before = $this->rows($tables);

        $this->travel(3)->seconds();
        $this->readiness($plan)->assertJsonPath('can_dispatch', false)
            ->assertJsonPath('dispatch_blockers.0.code', 'planned_departure_expired');
        $this->postJson($this->dispatchUrl($plan), $dispatch)->assertConflict()
            ->assertJsonPath('message', 'A tervezett indulási időpont már elmúlt. A feladás előtt frissítsd az indulási tervet.');
        $this->assertSame($before, $this->rows($tables));
        $this->assertSame($version, $plan->refresh()->version);
        $this->get(route('shipments.show', $plan))->assertOk()->assertSee('2026. 11. 23. 15:23');
        $this->patchJson($editUrl, $this->command($version, ['planned_departure_at' => '2026-11-23T15:23:00+01:00']))
            ->assertUnprocessable()->assertJsonValidationErrors('planned_departure_at');
        $this->patchJson($editUrl, $this->command($version, ['planned_departure_at' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('planned_departure_at');
        $this->actingAs($this->member($this->source, 'dispatch_operator'))
            ->patchJson($editUrl, $this->command($version, ['planned_departure_at' => '2026-11-23T15:24:00+01:00']))->assertForbidden();
        $this->actingAs($this->admin);
        $this->patchJson($editUrl, $edit)->assertOk()->assertJsonPath('shipment.version', $version);
        $update = $this->command($version, ['planned_departure_at' => '2026-11-23T15:24:00+01:00']);
        $this->patchJson($editUrl, $update)->assertOk()->assertJsonPath('shipment.version', $version + 1);
        $this->patchJson($editUrl, $this->command($version, ['planned_departure_at' => '2026-11-23T15:30:00+01:00']))->assertConflict();
        $this->readiness($plan)->assertJsonPath('can_dispatch', true)->assertJsonPath('basis_revision', $preview['basis_revision']);
        $this->assertSame(array_slice($before, 0, -1), $this->rows(array_slice($tables, 0, -1)));
        $this->postJson($this->dispatchUrl($plan), $this->command($version, ['confirmed' => true]))->assertConflict();

        $this->travel(37)->seconds();
        $actual = now()->toISOString();
        $validDispatch = $this->command($version + 1, ['confirmed' => true, 'dispatched_at' => '2020-01-01']);
        $result = $this->postJson($this->dispatchUrl($plan), $validDispatch)->assertOk()
            ->assertJsonPath('dispatch.dispatched_at', $actual)->assertJsonPath('shipment.started_at', $actual)
            ->assertJsonPath('event.occurred_at', $actual)
            ->assertJsonPath('dispatch.snapshot.shipment.planned_departure_at', '2026-11-23T14:24:00.000000Z');
        $this->assertSame($actual, InventoryMovement::findOrFail($result->json('transit_items.0.movement_id'))->created_at->toISOString());
        $this->travel(2)->days();
        $this->postJson($this->dispatchUrl($plan), $validDispatch)->assertOk()->assertExactJson($result->json());
        $this->getJson($this->dispatchUrl($plan))->assertOk()->assertJsonPath('dispatch.dispatched_at', $actual);
        $this->get(route('shipments.show', $plan))->assertOk()->assertSee('2026. 11. 23. 15:24');
        $this->readiness($plan)->assertJsonPath('dispatch_blockers.0.code', 'already_dispatched');
        $this->patchJson($editUrl, $this->command($version + 2, ['planned_departure_at' => now()->addDay()->toIso8601String()]))->assertConflict();
        $this->assertDatabaseCount('shipment_dispatches', 1);
        $this->assertDatabaseCount('shipment_transit_items', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertBalance($this->screw, physical: 15, assigned: 0, staged: 0, reserved: 0, free: 15);
    }

    public function test_65_unassigned_pieces_move_from_80_physical_stock_into_transit_and_leave_15_free(): void
    {
        [$plan, $intent] = $this->readyPlan();
        $oldVersion = $plan->refresh()->version;
        $oldIntentVersion = $intent->refresh()->version;
        $history = $this->rows(['transfer_stock_reservations', 'warehouse_transfer_allocations']);

        $this->assertBalance($this->screw, physical: 80, assigned: 0, staged: 65, reserved: 0, free: 15);
        $this->readiness($plan)->assertJsonPath('dispatch_prerequisites_met', true)->assertJsonPath('can_dispatch', true);
        $this->getJson($this->dispatchUrl($plan))->assertOk()->assertJsonPath('dispatch', null)->assertJsonCount(0, 'transit_items');

        $result = $this->dispatch($plan, ['note' => 'A rakományt átvette a sofőr.'])->assertOk()
            ->assertJsonPath('shipment.status', 'in_transit')->assertJsonPath('shipment.version', $oldVersion + 1)
            ->assertJsonPath('event.action', 'dispatch')->assertJsonPath('dispatch.actor_id', $this->admin->id)
            ->assertJsonPath('dispatch.note', 'A rakományt átvette a sofőr.')
            ->assertJsonPath('dispatched_intent_count', 1)->assertJsonPath('transit_quantity', 65)
            ->assertJsonPath('transit_items.0.transfer_intent_id', $intent->id)
            ->assertJsonPath('transit_items.0.shipment_intent_assignment_id', $this->assignmentId($plan, $intent))
            ->assertJsonPath('transit_items.0.dispatched_quantity', 65)
            ->assertJsonPath('transit_items.0.received_quantity', 0)
            ->assertJsonPath('transit_items.0.remaining_quantity', 65)
            ->assertJsonPath('transit_items.0.status', 'in_transit');

        $this->assertNotNull($result->json('dispatch.dispatched_at'));
        $this->assertNotNull($plan->fresh()->started_at);
        $this->assertSame($oldIntentVersion + 1, $intent->fresh()->version);
        $this->assertSame('approved', $intent->fresh()->status);
        $this->assertBalance($this->screw, physical: 15, assigned: 0, staged: 0, reserved: 0, free: 15);
        $this->assertDatabaseMissing('stocks', ['warehouse_id' => $this->pecs->getKey(), 'product_id' => $this->screw->getKey()]);
        $this->assertSame($history, $this->rows(['transfer_stock_reservations', 'warehouse_transfer_allocations']));
        $task = $intent->pickingTasks()->sole();
        $this->assertSame('picked', $task->status);
        $this->assertSame(65, $task->staged_quantity);
        $this->assertNotNull($task->dispatched_at);
        $this->assertSame($task->id, $result->json('transit_items.0.warehouse_transfer_task_id'));
        $this->assertDatabaseHas('inventory_movements', [
            'id' => $result->json('transit_items.0.movement_id'), 'shipment_transit_item_id' => $result->json('transit_items.0.id'),
            'warehouse_id' => $this->source->getKey(), 'product_id' => $this->screw->getKey(), 'user_id' => $this->admin->id,
            'type' => 'out', 'quantity' => 65, 'stock_before' => 80, 'stock_after' => 15,
        ]);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('inventory_transfers', 0);
        $this->readiness($plan)->assertJsonPath('can_dispatch', false)->assertJsonPath('dispatch_prerequisites_met', false);

        $this->getJson($this->dispatchUrl($plan))->assertOk()->assertJsonPath('dispatch.id', $result->json('dispatch.id'))
            ->assertJsonPath('transit_quantity', 65)->assertJsonPath('shipment.status', 'in_transit');
        app(InventoryService::class)->recordMovement([
            'warehouse_id' => $this->source->getKey(), 'product_id' => $this->screw->getKey(),
            'type' => 'out', 'quantity' => 15, 'user_id' => $this->admin->id,
        ]);
        $this->assertBalance($this->screw, physical: 0, assigned: 0, staged: 0, reserved: 0, free: 0);
        $this->getJson($this->dispatchUrl($plan))->assertOk()->assertJsonPath('transit_quantity', 65);
    }

    public function test_one_dispatch_keeps_three_distinct_intents_and_two_stops_while_booking_300_screws_and_50_washers(): void
    {
        $washer = $this->product('AL-02', 'Alátét');
        $screws = $this->stock($this->source, $this->screw, 420);
        $this->stock($this->source, $washer, 100);
        $destination = [
            $this->stock($this->pecs, $this->screw, 7), $this->stock($this->pecs, $washer, 3),
            $this->stock($this->szekszard, $washer, 11), $this->stock($this->szekszard, $this->screw, 9),
        ];
        $primary = $this->bin($screws, 'CS-A', 220);
        $secondary = $this->bin($screws, 'CS-B', 100);
        $first = $this->intent($this->screw, $this->pecs, 200);
        $second = $this->intent($washer, $this->szekszard, 50);
        $third = $this->intent($this->screw, $this->pecs, 100);
        $this->pick($first, location: $primary);
        $this->pick($second);
        $this->pick($third, location: $secondary);
        $this->pick($this->intent($this->screw, $this->szekszard, 10));
        $this->reserve($this->intent($this->screw, $this->szekszard, 25));

        $intents = [$first, $second, $third];
        $plan = $this->plan($intents);
        $vehicle = $this->confirmCargo($plan, $intents, palletSlots: 4);
        $historyTables = ['transfer_stock_reservations', 'warehouse_transfer_allocations', 'warehouse_placements'];
        $history = $this->rows($historyTables);
        $this->assertBalance($this->screw, physical: 420, assigned: 20, staged: 310, reserved: 25, free: 85);
        $response = $this->dispatch($plan)->assertOk()->assertJsonCount(3, 'transit_items')
            ->assertJsonPath('dispatched_intent_count', 3)->assertJsonPath('transit_quantity', 350)
            ->assertJsonCount(2, 'dispatch.snapshot.stops')->assertJsonCount(3, 'dispatch.snapshot.intents')
            ->assertJsonPath('dispatch.snapshot.vehicle.id', $vehicle['id'])
            ->assertJsonPath('dispatch.snapshot.vehicle.version', $vehicle['version'])
            ->assertJsonPath('dispatch.snapshot.vehicle.payload_capacity_grams', 1200000)
            ->assertJsonPath('dispatch.snapshot.vehicle.pallet_slots_capacity', 6)
            ->assertJsonPath('dispatch.snapshot.cargo.confirmation.gross_weight_grams', 350000)
            ->assertJsonPath('dispatch.snapshot.cargo.confirmation.pallet_slots', 4);

        $stops = $plan->stops()->get()->keyBy('warehouse_id');
        $items = collect($response->json('transit_items'))->keyBy('transfer_intent_id');
        foreach ($intents as $intent) {
            $item = $items->get($intent->id);
            $response->assertJsonFragment([
                'id' => $item['id'], 'shipment_id' => $plan->id, 'shipment_dispatch_id' => $response->json('dispatch.id'),
                'transfer_intent_id' => $intent->id, 'shipment_intent_assignment_id' => $this->assignmentId($plan, $intent),
                'product_id' => (int) $intent->product_id, 'from_warehouse_id' => $this->source->getKey(),
                'to_warehouse_id' => (int) $intent->to_warehouse_id, 'shipment_stop_id' => $stops->get($intent->to_warehouse_id)->id,
                'dispatched_quantity' => $intent->quantity, 'remaining_quantity' => $intent->quantity, 'received_quantity' => 0,
            ]);
            $movement = InventoryMovement::findOrFail($item['movement_id']);
            $this->assertSame($item['id'], $movement->shipment_transit_item_id);
            $this->assertSame($intent->quantity, $movement->quantity);
            $this->assertSame($movement->quantity, $movement->stock_before - $movement->stock_after);
        }
        $route = collect($response->json('dispatch.snapshot.stops'))->sortBy('position')->values();
        $this->assertSame([$this->pecs->getKey(), $this->szekszard->getKey()], $route->pluck('warehouse_id')->all());
        $this->assertSame([2, 1], $route->pluck('loading_position')->all());
        $this->assertSame($this->source->getKey(), $response->json('dispatch.snapshot.shipment.origin_warehouse_id'));
        $this->assertSame([7, 3, 11, 9], array_map(fn (Stock $stock) => $stock->fresh()->quantity, $destination));
        $this->assertBalance($this->screw, physical: 120, assigned: 20, staged: 10, reserved: 25, free: 85);
        $this->assertBalance($washer, physical: 50, assigned: 0, staged: 0, reserved: 0, free: 50);
        $this->assertSame($history, $this->rows($historyTables));
        $this->assertDatabaseCount('shipment_dispatches', 1);
        $this->assertDatabaseCount('shipment_transit_items', 3);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertDatabaseCount('inventory_transfers', 0);

        $this->patchJson('/shipment-plans/vehicles/'.$vehicle['id'], $this->command($vehicle['version'], [
            'name' => 'Később átnevezett jármű', 'payload_capacity_kg' => 100, 'pallet_slots_capacity' => 0,
        ]))->assertOk();
        $this->assertSame($response->json('dispatch'), $this->getJson($this->dispatchUrl($plan))->assertOk()->json('dispatch'));
        $this->getJson(route('shipment-plans.index', ['search' => 'ABC-456']))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicle_display', 'Ellenőrzött jármű · ABC-456');
        $this->getJson(route('shipment-plans.index', ['search' => 'Később átnevezett jármű']))->assertOk()->assertJsonCount(0, 'data');
        $dispatch = DB::table('shipment_dispatches')->where('id', $response->json('dispatch.id'));
        $this->assertDatabaseRejects(fn () => $dispatch->update(['note' => 'Utólagos módosítás']));
        $this->assertDatabaseRejects(fn () => $dispatch->delete());
        $duplicate = (array) DB::table('shipment_transit_items')->where('id', $items->first()['id'])->first();
        unset($duplicate['id']);
        $this->assertDatabaseRejects(fn () => DB::table('shipment_transit_items')->insert($duplicate));
    }

    public function test_receipt_preserves_separate_intents_and_audit_rows_for_the_same_product(): void
    {
        $this->stock($this->source, $this->screw, 80);
        $destinationStock = $this->stock($this->pecs, $this->screw, 5);
        $first = $this->intent($this->screw, $this->pecs, 10);
        $second = $this->intent($this->screw, $this->pecs, 20);
        $this->pick($first);
        $this->pick($second);

        $plan = $this->plan([$first, $second]);
        $this->confirmCargo($plan, [$first, $second]);
        $dispatch = $this->dispatch($plan)->assertOk()->json();
        $stop = $plan->stops()->sole();
        $receipt = $this->postJson(route('shipment-plans.stops.receipts.store', [$plan, $stop]), $this->command($stop->receiving_version, [
            'confirmed' => true,
            'items' => collect($dispatch['transit_items'])->reverse()->map(fn (array $item) => [
                'transit_item_id' => $item['id'], 'quantity' => $item['remaining_quantity'],
            ])->values()->all(),
        ]))->assertCreated()->assertJsonPath('shipment.status', 'completed')->assertJsonCount(2, 'items')->json();

        $this->assertSame([$first->id, $second->id], array_column($receipt['items'], 'transfer_intent_id'));
        $this->assertSame([10, 20], array_column($receipt['items'], 'received_quantity'));
        $moves = InventoryMovement::whereIn('id', array_column($receipt['items'], 'movement_id'))->orderBy('id')->get();
        $this->assertSame([5, 15], $moves->pluck('stock_before')->all());
        $this->assertSame([15, 35], $moves->pluck('stock_after')->all());
        $this->assertSame(35, $destinationStock->refresh()->quantity);
        $this->assertSame(30, (int) DB::table('shipment_receipt_items')->sum('pending_putaway_quantity'));

        $beforePutaway = InventoryMovement::count();
        $bin = $this->pecs->storageLocations()->create([
            'name' => 'Közös rekesz', 'code' => 'KOZOS', 'kind' => 'shelf', 'max_weight_grams' => 50000,
        ]);
        foreach ($receipt['items'] as $row) {
            $this->postJson(route('shipment-receipt-items.putaway.store', $row['id']), [
                'operation_key' => (string) Str::uuid(), 'version' => 1, 'confirmed' => true, 'site_verified' => true,
                'to_location_id' => $bin->id, 'quantity' => $row['received_quantity'], 'cargo_weight_kg' => 5, 'pallet_slots' => 0,
            ])->assertCreated()->assertJsonPath('item.pending_putaway_quantity', 0);
            $this->getJson(route('shipment-receipt-items.putaway.history', $row['id']))->assertOk()
                ->assertJsonPath('data.0.transfer_intent_id', $row['transfer_intent_id'])
                ->assertJsonPath('data.0.receipt_item_id', $row['id'])
                ->assertJsonPath('data.0.location_id', $bin->id);
        }
        $this->assertSame([10, 20], $bin->placements()->orderBy('id')->pluck('quantity')->all());
        $this->assertSame(35, $destinationStock->refresh()->quantity);
        $this->assertSame(5, app(WarehouseStockAvailability::class)->snapshot($this->pecs->getKey(), $this->screw->getKey())['direct_issue_quantity']);
        $this->assertDatabaseCount('inventory_movements', $beforePutaway);

    }

    public static function blockingConditions(): array
    {
        return [
            'üres fuvar' => ['empty', 'no_assigned_intents'],
            'jóváhagyás hiánya' => ['unapproved', 'intent_not_approved'],
            'foglalás hiánya' => ['no_reservation', 'source_reservation_required'],
            'feladat hiánya' => ['no_task', 'picking_task_required'],
            'részleges kitárolás' => ['partial', 'picking_incomplete'],
            'hibás allokációs összeg' => ['allocation_mismatch', 'staged_quantity_mismatch'],
            'ellentmondásos fizikai készlet' => ['stock_mismatch', 'stock_inconsistent'],
            'hibás megálló' => ['wrong_stop', 'route_mismatch'],
            'hiányzó tömeg' => ['no_totals', 'cargo_weight_unverified'],
            'hiányzó raklaphely-megerősítés' => ['no_totals', 'pallet_requirement_unverified'],
            'elavult megerősítés' => ['stale_cargo', 'cargo_confirmation_stale'],
            'inaktív jármű' => ['inactive', 'vehicle_inactive'],
            'időközben csökkent tömegkapacitás' => ['weight', 'vehicle_weight_capacity_exceeded'],
            'időközben csökkent raklapkapacitás' => ['pallets', 'vehicle_pallet_slots_capacity_exceeded'],
        ];
    }

    #[DataProvider('blockingConditions')]
    public function test_dispatch_rechecks_each_blocker_and_preserves_all_rows(string $case, string $blocker): void
    {
        $stock = $this->stock($this->source, $this->screw, 100);
        if ($case === 'empty') {
            $plan = $this->plan([]);
        } else {
            $intent = $this->intent($this->screw, $this->pecs, 65);
            $intents = [$intent];
            if ($case === 'partial') {
                $other = $this->intent($this->screw, $this->szekszard, 20);
                $this->pick($other);
                $this->pick($intent, quantity: 10);
                $intents[] = $other;
            } elseif ($case === 'no_task') {
                $this->reserve($intent);
            } elseif ($case !== 'no_reservation') {
                $this->pick($intent);
            }
            $plan = $this->plan($intents);
            $vehicle = $this->confirmCargo($plan, $intents, palletSlots: 2, totals: $case !== 'no_totals');
            if (in_array($case, ['inactive', 'weight', 'pallets'], true)) {
                $this->readiness($plan)->assertJsonPath('can_dispatch', true);
            }
            match ($case) {
                'unapproved' => DB::table('transfer_intents')->where('id', $intent->id)->update(['status' => 'pending_approval']),
                'allocation_mismatch' => $intent->pickingTasks()->sole()->allocations()->update(['quantity' => 64]),
                'stock_mismatch' => $stock->update(['quantity' => 64]),
                'wrong_stop' => $plan->stops()->update(['warehouse_id' => $this->szekszard->getKey()]),
                'stale_cargo' => $this->confirmItem($plan, $intent, 70000),
                'inactive', 'weight', 'pallets' => $this->patchJson('/shipment-plans/vehicles/'.$vehicle['id'], $this->command($vehicle['version'], match ($case) {
                    'inactive' => ['active' => false], 'weight' => ['payload_capacity_kg' => 60], 'pallets' => ['pallet_slots_capacity' => 1],
                }))->assertOk(),
                default => null,
            };
        }

        $before = $this->bookingRows();
        $this->readiness($plan)->assertJsonPath('can_dispatch', false)->assertJsonFragment(['code' => $blocker]);
        $this->dispatch($plan)->assertConflict()->assertJsonStructure(['blockers'])->assertJsonFragment(['code' => $blocker]);
        $this->assertSame($before, $this->bookingRows());
        $this->assertSame('draft', $plan->fresh()->status);
        $this->assertNull($plan->fresh()->started_at);
    }

    public function test_explicit_confirmation_permissions_version_and_idempotency_are_enforced_before_booking(): void
    {
        [$plan] = $this->readyPlan();
        $data = $this->command($plan->refresh()->version, ['confirmed' => true, 'note' => 'A kapunál átadva.']);
        $before = $this->bookingRows();
        foreach ([null, false, 0] as $confirmation) {
            $this->postJson($this->dispatchUrl($plan), array_replace($data, ['confirmed' => $confirmation]))
                ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        }
        $this->postJson($this->dispatchUrl($plan), array_replace($data, ['version' => $data['version'] - 1]))->assertConflict();
        $operator = $this->member($this->source, 'dispatch_operator');
        $this->actingAs($operator)->getJson($this->dispatchUrl($plan))->assertOk()->assertJsonPath('dispatch', null);
        $this->readiness($plan)->assertJsonPath('dispatch_prerequisites_met', true)->assertJsonPath('can_dispatch', false);
        foreach ([$operator, $this->member($this->source, 'requester'), $this->member($this->pecs, 'receiving_operator'), $this->member($this->pecs, 'supervisor')] as $actor) {
            $this->actingAs($actor)->postJson($this->dispatchUrl($plan), $data)->assertForbidden();
        }
        $this->assertSame($before, $this->bookingRows());

        $supervisor = $this->member($this->source, 'supervisor');
        $this->actingAs($supervisor);
        $this->readiness($plan)->assertJsonPath('can_dispatch', true);
        $first = $this->postJson($this->dispatchUrl($plan), $data)->assertOk()->assertJsonPath('dispatch.actor_id', $supervisor->id);
        $after = $this->bookingRows();
        $this->postJson($this->dispatchUrl($plan), $data)->assertOk()->assertExactJson($first->json());
        $this->postJson($this->dispatchUrl($plan), array_replace($data, ['note' => 'Megváltozott tartalom.']))->assertConflict();
        $this->dispatch($plan)->assertConflict();
        $this->actingAs($this->admin)->postJson($this->dispatchUrl($plan), $data)->assertConflict();
        $this->actingAs($operator)->getJson($this->dispatchUrl($plan))->assertOk()->assertJsonPath('transit_quantity', 65);
        $this->assertSame($after, $this->bookingRows());
        $this->assertDatabaseCount('shipment_dispatches', 1);
        $this->assertDatabaseCount('shipment_transit_items', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_dispatched_plan_cannot_be_replanned_restocked_or_returned_through_old_routes(): void
    {
        [$plan, $intent] = $this->readyPlan();
        $result = $this->dispatch($plan)->assertOk();
        $stop = $plan->stops()->sole();
        $movement = InventoryMovement::findOrFail($result->json('transit_items.0.movement_id'));
        $secondIntent = $this->intent($this->screw, $this->pecs, 5);
        $secondPlan = $this->plan([]);
        $before = $this->bookingRows();

        $this->postJson(route('shipments.start', $plan), ['version' => $plan->refresh()->version])->assertConflict();
        $this->patchJson(route('shipments.cancel', $plan), ['version' => $plan->version])->assertConflict();
        $this->patchJson(route('shipments.receive', [$plan, $stop]))->assertConflict();
        $this->deleteJson(route('shipment-plans.intents.detach', [$plan, $intent]), $this->command($plan->version))->assertConflict();
        $this->postJson(route('shipment-plans.intents.attach', $plan), $this->command($plan->version, ['intent_ids' => [$secondIntent->id]]))->assertConflict();
        $this->postJson(route('shipment-plans.intents.attach', $secondPlan), $this->command($secondPlan->version, ['intent_ids' => [$intent->id]]))->assertConflict();
        $this->patchJson(route('shipment-plans.stops.update', $plan), $this->command($plan->version, ['stop_ids' => [$stop->id]]))->assertConflict();
        $this->patchJson("/shipment-plans/{$plan->id}/vehicle", $this->command($plan->version, ['vehicle_id' => null]))->assertConflict();
        $assignment = $this->assignmentId($plan, $intent);
        $this->patchJson("/shipment-plans/{$plan->id}/cargo/items/{$assignment}", $this->command($plan->version, [
            'source' => 'manual', 'gross_weight_grams' => 80000, 'confirmed' => true,
        ]))->assertConflict();
        $this->patchJson(route('transfer-intents.cancel', $intent), $this->command($intent->refresh()->version, ['reason' => 'Utólagos visszavonás.']))->assertConflict();
        $this->postJson(route('transfer-intents.stock-reservation.store', $intent), $this->command($intent->version))->assertConflict();
        $this->patchJson(route('transfer-intents.stock-reservation.release', $intent), $this->command($intent->version))->assertConflict();
        $this->postJson(route('transfer-intents.picking-task.store', $intent), $this->command($intent->version))->assertConflict();
        $this->postJson(route('transfer-intents.picking-task.picks', $intent), $this->command($intent->version, $this->pickData(1)))->assertConflict();
        $this->assertFalse($movement->isReturnable());
        $this->postJson(route('movements.return.store', $movement), [
            'request_key' => (string) Str::uuid(), 'quantity' => 1, 'return_reason' => 'Hibás közvetlen visszavétel.',
        ])->assertUnprocessable();
        $this->assertSame($before, $this->bookingRows());
    }

    public static function dispatchFailures(): array
    {
        return [
            'second transit row' => ['shipment_transit_items', 'EXISTS (SELECT 1 FROM shipment_transit_items WHERE shipment_id = NEW.shipment_id)'],
            'audit after stock and movement writes' => ['shipment_plan_operations', "NEW.action = 'dispatch'"],
        ];
    }

    #[DataProvider('dispatchFailures')]
    public function test_database_failure_rolls_back_the_whole_dispatch(string $table, string $condition): void
    {
        $this->stock($this->source, $this->screw, 100);
        $first = $this->intent($this->screw, $this->pecs, 30);
        $second = $this->intent($this->screw, $this->szekszard, 40);
        $this->pick($first);
        $this->pick($second);
        $plan = $this->plan([$first, $second]);
        $this->confirmCargo($plan, [$first, $second]);
        $before = $this->bookingRows();
        $data = $this->command($plan->refresh()->version, ['confirmed' => true]);

        DB::unprepared("CREATE TRIGGER force_dispatch_failure BEFORE INSERT ON $table
            WHEN $condition
            BEGIN SELECT RAISE(ABORT, 'szandekos feladasi proba'); END");
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->dispatchUrl($plan), $data);
            $this->fail('Az indukált adatbázishibának meg kellett szakítania a feladást.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('szandekos feladasi proba', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
            DB::unprepared('DROP TRIGGER force_dispatch_failure');
        }

        $this->assertSame($before, $this->bookingRows());
        $this->assertBalance($this->screw, physical: 100, assigned: 0, staged: 70, reserved: 0, free: 30);
        $this->postJson($this->dispatchUrl($plan), $data)->assertOk()->assertJsonPath('transit_quantity', 70);
        $this->assertBalance($this->screw, physical: 30, assigned: 0, staged: 0, reserved: 0, free: 30);
    }

    public function test_a_dispatch_at_the_maximum_plan_version_is_not_offered(): void
    {
        [$plan] = $this->readyPlan();
        $plan->update(['version' => 4294967295]);
        $this->readiness($plan)->assertJsonPath('can_dispatch', false)->assertJsonFragment(['code' => 'shipment_version_exhausted']);
        $this->dispatch($plan)->assertConflict();
        $this->assertDatabaseCount('shipment_dispatches', 0);
    }

    public function test_sqlite_replace_cannot_rewrite_a_dispatch_snapshot(): void
    {
        [$plan] = $this->readyPlan();
        $result = $this->dispatch($plan)->assertOk();
        $dispatch = DB::table('shipment_dispatches')->where('id', $result->json('dispatch.id'))->sole();
        try {
            DB::statement('INSERT OR REPLACE INTO shipment_dispatches (id, shipment_id, actor_id, vehicle_id, dispatched_at, snapshot)
                VALUES (?, ?, ?, ?, ?, ?)', [$dispatch->id, $dispatch->shipment_id, $dispatch->actor_id, $dispatch->vehicle_id,
                $dispatch->dispatched_at, '{}']);
            $this->fail('A feladás pillanatképét lecserélte az adatbázis.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE constraint failed: shipment_dispatches.id', $exception->getMessage());
        }
        $this->getJson($this->dispatchUrl($plan))->assertOk()->assertJsonPath('dispatch.snapshot', $result->json('dispatch.snapshot'));
    }

    private function readyPlan(): array
    {
        $this->stock($this->source, $this->screw, 80);
        $intent = $this->intent($this->screw, $this->pecs, 65);
        $this->pick($intent);
        $plan = $this->plan([$intent]);
        $this->confirmCargo($plan, [$intent]);

        return [$plan, $intent];
    }

    private function intent(Product $product, Warehouse $target, int $quantity): TransferIntent
    {
        $service = app(TransferIntentService::class);
        $created = $service->create($this->admin, [
            'operation_key' => (string) Str::uuid(), 'product_id' => $product->getKey(), 'quantity' => $quantity,
            'from_warehouse_id' => $this->source->getKey(), 'to_warehouse_id' => $target->getKey(),
            'shipping_preference' => 'groupable',
        ]);
        $intent = TransferIntent::findOrFail($created['intent']['id']);
        $service->approve($this->admin, $intent, $this->command($intent->version));

        return $intent->refresh();
    }

    private function reserve(TransferIntent $intent): void
    {
        app(TransferStockReservationService::class)->reserve($this->admin, $intent, $this->command($intent->refresh()->version));
    }

    private function pick(TransferIntent $intent, ?int $quantity = null, ?WarehouseLocation $location = null): void
    {
        $this->reserve($intent);
        $service = app(WarehouseTransferTaskService::class);
        $service->create($this->admin, $intent, $this->command($intent->refresh()->version));
        $service->pick($this->admin, $intent, $this->command($intent->refresh()->version, $this->pickData($quantity ?? $intent->quantity, $location)));
    }

    private function pickData(int $quantity, ?WarehouseLocation $location = null): array
    {
        $data = ['quantity' => $quantity, 'source_allocations' => [[
            'source_type' => $location ? 'location' : 'unassigned', 'location_id' => $location?->id, 'quantity' => $quantity,
        ]]];
        if ($location === null) {
            $data['unassigned_confirmation'] = ['found' => true, 'description' => 'Az ellenőrzött kiadási területen.'];
        }

        return $data;
    }

    private function plan(array $intents): Shipment
    {
        $id = $this->postJson(route('shipment-plans.store'), [
            'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $this->source->getKey(),
            'reference' => 'Feladási tesztfuvar', 'intent_ids' => array_map(fn (TransferIntent $intent) => $intent->id, $intents),
        ])->assertCreated()->json('shipment.id');

        return Shipment::findOrFail($id);
    }

    private function confirmCargo(Shipment $plan, array $intents, int $palletSlots = 0, bool $totals = true): array
    {
        $vehicle = $this->postJson('/shipment-plans/vehicles', [
            'operation_key' => (string) Str::uuid(), 'name' => 'Ellenőrzött jármű', 'registration_number' => 'ABC-456',
            'payload_capacity_kg' => 1200, 'pallet_slots_capacity' => 6, 'active' => true,
        ])->assertCreated()->json('vehicle');
        $this->patchJson("/shipment-plans/{$plan->id}/vehicle", $this->command($plan->refresh()->version, [
            'vehicle_id' => $vehicle['id'],
        ]))->assertOk();
        foreach ($intents as $intent) {
            $this->confirmItem($plan, $intent, $intent->quantity * 1000);
        }
        if ($totals) {
            $basis = $this->getJson("/shipment-plans/{$plan->id}/cargo")->assertOk()->json('basis_revision');
            $this->patchJson("/shipment-plans/{$plan->id}/cargo", $this->command($plan->refresh()->version, [
                'basis_revision' => $basis, 'gross_weight_grams' => collect($intents)->sum('quantity') * 1000,
                'pallet_slots' => $palletSlots,
            ]))->assertOk();
        }

        return $vehicle;
    }

    private function confirmItem(Shipment $plan, TransferIntent $intent, int $weight): TestResponse
    {
        $assignment = $this->assignmentId($plan, $intent);

        return $this->patchJson("/shipment-plans/{$plan->id}/cargo/items/{$assignment}", $this->command($plan->refresh()->version, [
            'source' => 'manual', 'gross_weight_grams' => $weight, 'confirmed' => true,
        ]))->assertOk();
    }

    private function product(string $sku, string $name): Product
    {
        return Product::create(['sku' => $sku, 'product_name' => $name, 'price' => 1, 'category_id' => $this->category->getKey()]);
    }

    private function stock(Warehouse $warehouse, Product $product, int $quantity): Stock
    {
        return Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(), 'quantity' => $quantity]);
    }

    private function bin(Stock $stock, string $code, int $quantity): WarehouseLocation
    {
        $location = $this->source->storageLocations()->create(['code' => $code, 'name' => $code, 'kind' => 'shelf', 'max_weight_grams' => 250000]);
        $stock->placements()->create(['warehouse_location_id' => $location->id, 'quantity' => $quantity, 'cargo_weight_grams' => 1000, 'pallet_slots' => 0]);

        return $location;
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->addWarehouseMembership($warehouse->getKey(), [$role]);

        return $user;
    }

    private function assignmentId(Shipment $plan, TransferIntent $intent): int
    {
        return $plan->intentAssignments()->active()->where('transfer_intent_id', $intent->id)->sole()->id;
    }

    private function readiness(Shipment $plan): TestResponse
    {
        return $this->getJson(route('shipment-plans.readiness', $plan))->assertOk();
    }

    private function dispatchUrl(Shipment $plan): string
    {
        return "/shipment-plans/{$plan->id}/dispatch";
    }

    private function dispatch(Shipment $plan, array $data = []): TestResponse
    {
        return $this->postJson($this->dispatchUrl($plan), $this->command($plan->refresh()->version, $data + ['confirmed' => true]));
    }

    private function command(int $version, array $data = []): array
    {
        return $data + ['operation_key' => (string) Str::uuid(), 'version' => $version];
    }

    private function rows(array $tables): array
    {
        return array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    }

    private function bookingRows(): array
    {
        return $this->rows([
            'stocks', 'warehouse_placements', 'warehouse_transfer_tasks', 'warehouse_transfer_allocations', 'transfer_stock_reservations',
            'transfer_intents', 'transfer_workflow_events', 'shipments', 'shipment_stops', 'shipment_intent_assignments',
            'shipment_plan_operations', 'shipment_dispatches', 'shipment_transit_items', 'inventory_movements', 'inventory_transfers',
        ]);
    }

    private function assertBalance(Product $product, int $physical, int $assigned, int $staged, int $reserved, int $free): void
    {
        $balance = app(WarehouseStockAvailability::class)->snapshot($this->source->getKey(), $product->getKey());
        $this->assertTrue($balance['consistent']);
        $this->assertSame($physical, $balance['physical_quantity']);
        $this->assertSame($assigned, $balance['assigned_quantity']);
        $this->assertSame($physical - $assigned - $staged, $balance['unassigned_quantity']);
        $this->assertSame($staged, $balance['staged_quantity']);
        $this->assertSame($reserved, $balance['source_reserved_quantity']);
        $this->assertSame($free, $balance['free_quantity']);
    }

    private function assertDatabaseRejects(callable $action): void
    {
        try {
            $action();
            $this->fail('Az adatbázisnak el kellett volna utasítania a tiltott műveletet.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->errorInfo);
        }
    }
}
