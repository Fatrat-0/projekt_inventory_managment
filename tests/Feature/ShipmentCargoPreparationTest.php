<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Shipment, ShipmentPlanOperation, Stock, TransferIntent, User, Warehouse, WarehouseLocation};
use App\Services\{InventoryService, TransferCargoWeight, TransferIntentService, TransferStockReservationService, WarehouseTransferTaskService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ShipmentCargoPreparationTest extends TestCase
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
        $this->origin = Warehouse::create(['warehouse_name' => 'Budapest, központi raktár']);
        $this->pecs = Warehouse::create(['warehouse_name' => 'Pécsi átvételi raktár']);
        $this->szekszard = Warehouse::create(['warehouse_name' => 'Szekszárdi átvételi raktár']);
        $category = Category::create(['category_name' => 'Kötőelemek']);
        $this->product = Product::create([
            'product_name' => 'Rögzítőcsavar', 'sku' => 'CARGO-001', 'price' => 1, 'category_id' => $category->getKey(),
        ]);
        $this->stock($this->origin, 1000);
    }

    public function test_vehicle_catalog_records_known_capacity_and_distinguishes_zero_from_unknown(): void
    {
        $unknown = $this->createVehicle(null, null);
        $this->assertNull($unknown['payload_capacity_grams']);
        $this->assertNull($unknown['pallet_slots_capacity']);
        $zero = $this->createVehicle(0, 0);
        $this->assertSame(0, $zero['payload_capacity_grams']);
        $this->assertSame(0, $zero['pallet_slots_capacity']);
        $disabled = $this->postJson('/shipment-plans/vehicles', array_replace($this->vehicleData(850, 2), [
            'registration_number' => 'TES-401', 'active' => false, 'operation_key' => (string) Str::uuid(),
        ]))->assertCreated()->assertJsonPath('vehicle.registration_number', 'TES-401')->json('vehicle');
        $this->getJson('/shipment-plans/vehicles')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($this->member($this->origin, 'dispatch_operator'))->getJson('/shipment-plans/vehicles')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonMissing(['id' => $disabled['id']]);
        $this->actingAs($this->admin);

        $data = $this->vehicleData(1200, 6) + $this->command($unknown['version']);
        $response = $this->patchJson('/shipment-plans/vehicles/'.$unknown['id'], $data)->assertOk()
            ->assertJsonPath('vehicle.id', $unknown['id'])->assertJsonPath('vehicle.payload_capacity_grams', 1200000)
            ->assertJsonPath('vehicle.pallet_slots_capacity', 6)->assertJsonPath('event.actor_id', $this->admin->id);
        $updated = $response->json('vehicle');
        $this->assertSame($unknown['version'] + 1, $updated['version']);
        $this->patchJson('/shipment-plans/vehicles/'.$unknown['id'], $data)->assertOk()->assertExactJson($response->json());
        $this->patchJson('/shipment-plans/vehicles/'.$unknown['id'], array_replace($data, ['payload_capacity_kg' => 900]))
            ->assertConflict();
        $this->patchJson('/shipment-plans/vehicles/'.$unknown['id'], $this->command($unknown['version']) + $this->vehicleData(900, 6))
            ->assertConflict();
        $this->postJson('/shipment-plans/vehicles', $this->vehicleData(-1, -1) + ['operation_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors(['payload_capacity_kg', 'pallet_slots_capacity']);
    }

    public function test_only_admin_manages_the_vehicle_catalog_and_only_source_supervisors_manage_plan_cargo(): void
    {
        $vehicle = $this->createVehicle(1200, 6);
        $intent = $this->intent(24, $this->pecs);
        $plan = $this->plan([$intent]);
        $assignment = $this->assignment($plan, $intent);
        $supervisor = $this->member($this->origin, 'supervisor');
        $dispatcher = $this->member($this->origin, 'dispatch_operator');
        $receiving = $this->member($this->pecs, 'receiving_operator');
        $otherSupervisor = $this->member($this->pecs, 'supervisor');

        $this->actingAs($supervisor)->getJson('/shipment-plans/vehicles')->assertOk()->assertJsonFragment(['id' => $vehicle['id']]);
        $this->assignVehicle($plan, $vehicle['id'])->assertOk()->assertJsonPath('cargo.vehicle.id', $vehicle['id']);
        $this->manualItem($plan, $intent, 6000)->assertOk()->assertJsonPath('event.actor_id', $supervisor->id);
        $this->confirmTotals($plan, 6000, 0)->assertOk()->assertJsonPath('event.actor_id', $supervisor->id);
        $this->actingAs($dispatcher)->getJson($this->url($plan, 'cargo'))->assertOk()
            ->assertJsonPath('vehicle.id', $vehicle['id']);
        $this->getJson(route('shipment-plans.readiness', $plan))->assertOk();
        $basis = $this->cargo($plan)['basis_revision'];
        foreach ([$supervisor, $dispatcher, $receiving, $otherSupervisor] as $actor) {
            $this->actingAs($actor)->postJson('/shipment-plans/vehicles', $this->vehicleData(1200, 6) + [
                'operation_key' => (string) Str::uuid(),
            ])->assertForbidden();
            $this->patchJson('/shipment-plans/vehicles/'.$vehicle['id'], $this->command($vehicle['version']) + $this->vehicleData(1300, 6))
                ->assertForbidden();
        }
        foreach ([$dispatcher, $receiving, $otherSupervisor] as $actor) {
            $this->actingAs($actor);
            $this->assignVehicle($plan, null)->assertForbidden();
            $this->edit($plan, "cargo/items/{$assignment}", [
                'source' => 'manual', 'gross_weight_grams' => 7000, 'confirmed' => true,
            ])->assertForbidden();
            $this->edit($plan, 'cargo', [
                'basis_revision' => $basis, 'gross_weight_grams' => 7000, 'pallet_slots' => 0,
            ])->assertForbidden();
        }
        $this->actingAs($otherSupervisor)->getJson($this->url($plan, 'cargo'))->assertForbidden();
        $this->actingAs($this->admin)->getJson($this->url($plan, 'cargo'))->assertOk()
            ->assertJsonPath('confirmation.gross_weight_grams', 6000);
    }

    public function test_whole_boxes_have_a_gram_exact_suggestion_that_requires_confirmation(): void
    {
        $packaging = $this->product->defaultPackaging()->create([
            'name' => 'Csavaros doboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.500',
        ]);
        $whole = $this->intent(200, $this->pecs);
        $fraction = $this->intent(150, $this->szekszard);
        $plan = $this->plan([$whole, $fraction]);
        $suggestion = $this->item($plan, $whole)['suggestion'];
        $this->assertSame(11000, $suggestion['gross_weight_grams']);
        $this->assertSame(2, $suggestion['package_count']);
        $this->assertSame(100, $suggestion['pieces_per_package']);
        $this->assertSame(5500, $suggestion['gross_weight_per_package_grams']);
        $this->assertSame(TransferCargoWeight::packagingRevision($packaging), $suggestion['packaging_revision']);
        $this->assertNull($this->item($plan, $whole)['cargo']);
        $this->assertNull($this->item($plan, $fraction)['suggestion']);

        $assignment = $this->assignment($plan, $whole);
        $fields = ['source' => 'calculated', 'packaging_revision' => $suggestion['packaging_revision']];
        $this->edit($plan, "cargo/items/{$assignment}", $fields + ['confirmed' => false])->assertOk();
        $draft = $this->item($plan, $whole)['cargo'];
        $this->assertSame('draft', $draft['status']);
        $this->assertSame(11000, $draft['gross_weight_grams']);
        $this->edit($plan, "cargo/items/{$assignment}", $fields + ['confirmed' => true])->assertOk();
        $confirmed = $this->item($plan, $whole)['cargo'];
        $this->assertSame('calculated', $confirmed['source']);
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertSame($this->admin->id, $confirmed['confirmed_by']);
        $this->assertNotNull($confirmed['confirmed_at']);
        $fractionAssignment = $this->assignment($plan, $fraction);
        $this->edit($plan, "cargo/items/{$fractionAssignment}", $fields + ['confirmed' => true])->assertConflict();
    }

    public function test_missing_package_mass_never_estimates_weight_and_manual_item_keeps_the_intent_quantity(): void
    {
        $this->product->defaultPackaging()->create(['name' => 'Doboz tömegadat nélkül', 'pieces_per_package' => 100]);
        $intent = $this->intent(200, $this->pecs);
        $plan = $this->plan([$intent]);
        $this->assertNull($this->item($plan, $intent)['suggestion']);

        $draft = ['source' => 'manual', 'confirmed' => false, 'packaging_note' => 'A tömeget még mérni kell.'];
        $endpoint = 'cargo/items/'.$this->assignment($plan, $intent);
        $this->edit($plan, $endpoint, $draft)->assertOk();
        $this->assertSame('draft', $this->item($plan, $intent)['cargo']['status']);
        $this->assertNull($this->item($plan, $intent)['cargo']['gross_weight_grams']);
        $this->edit($plan, $endpoint, array_replace($draft, ['confirmed' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('gross_weight_grams');

        $this->manualItem($plan, $intent, 12543, [
            'dimensions_cm' => ['width' => 41.5, 'depth' => 25, 'height' => 20],
            'packaging_note' => 'Mért, külön lezárt kartonok.',
        ])->assertOk();
        $this->manualItem($plan, $intent, 12543, [
            'quantity' => 999, 'dimensions_cm' => ['width' => 41.5, 'depth' => 25, 'height' => 20],
            'packaging_note' => 'Mért, külön lezárt kartonok.',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->manualItem($plan, $intent, 12543, ['dimensions_cm' => ['width' => 41.5, 'depth' => 25]])
            ->assertUnprocessable()->assertJsonValidationErrors('dimensions_cm');
        $item = $this->item($plan, $intent);
        $this->assertSame($intent->id, $item['transfer_intent_id']);
        $this->assertSame($this->product->getKey(), $item['product_id']);
        $this->assertSame(200, $item['quantity']);
        $this->assertSame(200, $intent->refresh()->quantity);
        $this->assertSame('manual', $item['cargo']['source']);
        $this->assertSame('confirmed', $item['cargo']['status']);
        $this->assertSame(12543, $item['cargo']['gross_weight_grams']);
        $this->assertEquals(['width' => 41.5, 'depth' => 25, 'height' => 20], $item['cargo']['dimensions_cm']);
        $this->assertSame('Mért, külön lezárt kartonok.', $item['cargo']['packaging_note']);
    }

    public function test_three_picked_intents_fit_one_vehicle_across_two_stops_without_stock_changes_or_dispatch(): void
    {
        $this->stock($this->pecs, 7);
        $this->stock($this->szekszard, 11);
        $bin = WarehouseLocation::create([
            'warehouse_id' => $this->pecs->getKey(), 'code' => 'CARGO-PAL', 'name' => 'Foglalással terhelt raklaphely',
            'kind' => 'pallet', 'active' => true, 'max_weight_grams' => 200000, 'max_pallet_slots' => 4,
        ]);
        app(InventoryService::class)->startTransfer([
            'product_id' => $this->product->getKey(), 'from_warehouse_id' => $this->origin->getKey(),
            'to_warehouse_id' => $this->pecs->getKey(), 'quantity' => 5, 'user_id' => $this->admin->id,
            'to_location_id' => $bin->id, 'cargo_weight_grams' => 12000, 'pallet_slots' => 1,
        ]);

        $a = $this->intent(200, $this->pecs);
        $b = $this->intent(100, $this->szekszard);
        $c = $this->intent(100, $this->pecs);
        $unrelated = $this->intent(30, $this->pecs);
        app(TransferStockReservationService::class)->reserve($this->admin, $unrelated, $this->command($unrelated->version));
        foreach ([$a, $b, $c] as $intent) {
            $this->pick($intent);
        }
        $plan = $this->plan([$a, $b, $c]);
        $before = $this->stockState();

        $this->readiness($plan)->assertJsonPath('stock_ready', true)->assertJsonPath('stock_ready_count', 3)
            ->assertJsonPath('cargo_ready', false)->assertJsonPath('vehicle_ready', false)
            ->assertJsonPath('dispatch_prerequisites_met', false)->assertJsonPath('can_dispatch', false);
        $this->getJson(route('shipment-plans.show', $plan))->assertOk()->assertJsonCount(2, 'stops')
            ->assertJsonCount(3, 'assignments');
        $vehicle = $this->createVehicle(1200, 6);
        $this->assignVehicle($plan, $vehicle['id'])->assertOk();
        foreach ([[$a, 300000], [$b, 350000], [$c, 325000]] as [$intent, $weight]) {
            $this->manualItem($plan, $intent, $weight)->assertOk();
        }
        $this->confirmTotals($plan, 1000000, 4, [
            'shared_materials_weight_grams' => 25000,
            'reason' => 'Két igény közös raklapot használ; a raklapok és a rögzítők tömege külön mért.',
        ])->assertOk()->assertJsonPath('cargo.confirmation.status', 'confirmed')
            ->assertJsonPath('cargo.confirmation.gross_weight_grams', 1000000)
            ->assertJsonPath('cargo.confirmation.pallet_slots', 4)
            ->assertJsonPath('cargo.confirmation.confirmed_by', $this->admin->id);
        $this->readiness($plan)->assertJsonPath('stock_ready', true)->assertJsonPath('cargo_ready', true)
            ->assertJsonPath('vehicle_ready', true)->assertJsonPath('dispatch_prerequisites_met', true)
            ->assertJsonPath('can_dispatch', true)->assertJsonCount(0, 'cargo_blockers')->assertJsonCount(0, 'vehicle_blockers');
        $this->getJson(route('shipment-plans.index'))->assertOk()->assertJsonPath('data.0.id', $plan->id)
            ->assertJsonPath('data.0.stock_ready', true)->assertJsonPath('data.0.transport_ready', true);
        $prepared = $this->cargo($plan);
        $this->assertSame(
            ['limit' => 1200000, 'required' => 1000000, 'remaining' => 200000, 'status' => 'within_limit'],
            $prepared['capacity']['weight_grams'],
        );
        $this->assertSame(
            ['limit' => 6, 'required' => 4, 'remaining' => 2, 'status' => 'within_limit'],
            $prepared['capacity']['pallet_slots'],
        );
        $this->assertSame(975000, $prepared['confirmed_item_weight_grams']);
        // Both individual stops' cargo is below 700 kg, but all cargo is on board at departure.
        $this->patchJson(route('shipment-plans.vehicles.update', $vehicle['id']), $this->command($vehicle['version']) + [
            'payload_capacity_kg' => 700, 'pallet_slots_capacity' => 3,
        ])->assertOk();
        $this->readiness($plan)->assertJsonPath('can_dispatch', false)->assertJsonPath('cargo_ready', true)
            ->assertJsonFragment(['code' => 'vehicle_weight_capacity_exceeded'])
            ->assertJsonFragment(['code' => 'vehicle_pallet_slots_capacity_exceeded']);

        $this->postJson(route('shipments.start', $plan), ['version' => $plan->refresh()->version])->assertConflict();
        $this->assertSame('draft', $plan->refresh()->status);
        $this->assertNull($plan->started_at);
        $this->assertSame($before, $this->stockState());
        $capacity = WarehouseLocation::withCapacityUsage()->findOrFail($bin->id);
        $this->assertSame([188000, 3], [$capacity->freeWeight(), $capacity->freeSlots()]);
    }

    public function test_unknown_pallet_slot_requirement_cannot_be_confirmed_and_explicit_zero_fits_zero_slots(): void
    {
        $intent = $this->intent(10, $this->pecs);
        $plan = $this->plan([$intent]);
        $unknown = $this->createVehicle(null, null);
        $this->assignVehicle($plan, $unknown['id'])->assertOk();
        $this->manualItem($plan, $intent, 2500)->assertOk();
        $fields = ['basis_revision' => $this->cargo($plan)['basis_revision'], 'gross_weight_grams' => 2500];
        $this->edit($plan, 'cargo', $fields)->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->edit($plan, 'cargo', $fields + ['pallet_slots' => null])->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->edit($plan, 'cargo', $fields + ['pallet_slots' => -1])->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->readiness($plan)->assertJsonPath('cargo_ready', false)->assertJsonPath('vehicle_ready', false);
        $this->assertNull($this->cargo($plan)['confirmation']);

        $this->confirmTotals($plan, 2500, 0)->assertOk()->assertJsonPath('cargo.confirmation.pallet_slots', 0);
        $this->readiness($plan)->assertJsonPath('cargo_ready', true)->assertJsonPath('vehicle_ready', false)
            ->assertJsonFragment(['code' => 'vehicle_weight_capacity_unknown'])
            ->assertJsonFragment(['code' => 'vehicle_pallet_slots_capacity_unknown']);

        $noPalletVehicle = $this->createVehicle(10, 0);
        $this->assignVehicle($plan, $noPalletVehicle['id'])->assertOk();
        $this->confirmTotals($plan, 2500, 0)->assertOk()->assertJsonPath('cargo.confirmation.pallet_slots', 0);
        $this->readiness($plan)->assertJsonPath('cargo_ready', true)->assertJsonPath('vehicle_ready', true);
    }

    public function test_weight_and_pallet_limits_are_checked_separately_against_current_vehicle_data(): void
    {
        $intent = $this->intent(25, $this->pecs);
        $plan = $this->plan([$intent]);
        $light = $this->createVehicle(800, 6);
        $this->assignVehicle($plan, $light['id'])->assertOk();
        $this->manualItem($plan, $intent, 1000000)->assertOk();
        $this->confirmTotals($plan, 1000000, 4)->assertOk();
        $this->readiness($plan)->assertJsonPath('cargo_ready', true)->assertJsonPath('vehicle_ready', false)
            ->assertJsonPath('dispatch_prerequisites_met', false)->assertJsonCount(1, 'vehicle_blockers')
            ->assertJsonPath('vehicle_blockers.0.code', 'vehicle_weight_capacity_exceeded');

        // Editing the referenced record must affect readiness without reassigning it.
        $this->patchJson('/shipment-plans/vehicles/'.$light['id'],
            $this->command($light['version']) + $this->vehicleData(1200, 3))->assertOk();
        $current = $this->cargo($plan);
        $this->assertSame($light['id'], $current['vehicle']['id']);
        $this->assertSame(1200000, $current['vehicle']['payload_capacity_grams']);
        $this->assertSame(3, $current['vehicle']['pallet_slots_capacity']);
        $this->assertSame('confirmed', $current['confirmation']['status']);
        $this->readiness($plan)->assertJsonPath('cargo_ready', true)->assertJsonPath('vehicle_ready', false)
            ->assertJsonCount(1, 'vehicle_blockers')->assertJsonPath('vehicle_blockers.0.code', 'vehicle_pallet_slots_capacity_exceeded');

        $exact = $this->createVehicle(1000, 4);
        $this->assignVehicle($plan, $exact['id'])->assertOk();
        $this->assertSame('stale', $this->cargo($plan)['confirmation']['status']);
        $this->confirmTotals($plan, 1000000, 4)->assertOk();
        $this->readiness($plan)->assertJsonPath('vehicle_ready', true)
            ->assertJsonPath('stock_ready', false)->assertJsonPath('dispatch_prerequisites_met', false);

        $disabled = array_replace($this->vehicleData(1000, 4), ['active' => false]);
        $this->patchJson('/shipment-plans/vehicles/'.$exact['id'], $this->command($exact['version']) + $disabled)->assertOk();
        $this->readiness($plan)->assertJsonPath('vehicle_ready', false)->assertJsonPath('can_dispatch', false);
    }

    public function test_total_lighter_than_verified_items_and_shared_materials_is_rejected_at_one_gram(): void
    {
        $a = $this->intent(20, $this->pecs);
        $b = $this->intent(10, $this->pecs);
        $plan = $this->plan([$a, $b]);
        $vehicle = $this->createVehicle(1200, 6);
        $this->assignVehicle($plan, $vehicle['id'])->assertOk();
        $this->manualItem($plan, $a, 60000)->assertOk();
        $this->manualItem($plan, $b, 40000)->assertOk();
        $version = $plan->refresh()->version;
        $materials = ['shared_materials_weight_grams' => 100];
        $this->confirmTotals($plan, 100099, 2, $materials + ['reason' => 'Elvégzett mérlegelés.'])->assertUnprocessable();
        $this->confirmTotals($plan, 100101, 2, $materials)->assertUnprocessable();
        $this->assertSame($version, $plan->refresh()->version);
        $this->assertNull($this->cargo($plan)['confirmation']);

        $reason = 'A teljes mérés egy gramm további közös ragasztószalagot igazolt.';
        $record = $this->confirmTotals($plan, 100101, 2, $materials + ['reason' => $reason])->assertOk()
            ->assertJsonPath('cargo.confirmation.gross_weight_grams', 100101)
            ->assertJsonPath('event.actor_id', $this->admin->id)->assertJsonPath('event.reason', $reason);
        $this->assertNotNull($record->json('event.occurred_at'));
    }

    public function test_adding_and_detaching_intents_invalidate_totals_but_reordering_stops_does_not(): void
    {
        $a = $this->intent(20, $this->pecs);
        $b = $this->intent(10, $this->szekszard);
        $additional = $this->intent(5, $this->pecs);
        $plan = $this->plan([$a, $b]);
        $vehicle = $this->createVehicle(1000, 4);
        $this->assignVehicle($plan, $vehicle['id'])->assertOk();
        $this->manualItem($plan, $a, 10000)->assertOk();
        $this->manualItem($plan, $b, 20000)->assertOk();
        $this->confirmTotals($plan, 30000, 2)->assertOk();
        $before = $this->cargo($plan);

        $this->patchJson(route('shipment-plans.stops.update', $plan), $this->command($plan->refresh()->version, [
            'stop_ids' => array_reverse($plan->stops()->pluck('id')->all()),
        ]))->assertOk();
        $reordered = $this->cargo($plan);
        $this->assertSame($before['basis_revision'], $reordered['basis_revision']);
        $this->assertSame('confirmed', $reordered['confirmation']['status']);

        $this->postJson(route('shipment-plans.intents.attach', $plan), $this->command($plan->refresh()->version, [
            'intent_ids' => [$additional->id],
        ]))->assertOk();
        $added = $this->cargo($plan);
        $this->assertCount(3, $added['items']);
        $this->assertSame('stale', $added['confirmation']['status']);
        $this->assertNotSame($before['basis_revision'], $added['basis_revision']);
        $this->readiness($plan)->assertJsonPath('cargo_ready', false);

        $this->manualItem($plan, $additional, 5000)->assertOk();
        $this->confirmTotals($plan, 35000, 2)->assertOk();
        $this->deleteJson(route('shipment-plans.intents.detach', [$plan, $additional]), $this->command($plan->refresh()->version, [
            'reason' => 'Az igény a következő fuvarra kerül.',
        ]))->assertOk();
        $detached = $this->cargo($plan);
        $this->assertCount(2, $detached['items']);
        $this->assertSame('stale', $detached['confirmation']['status']);
        $this->readiness($plan)->assertJsonPath('cargo_ready', false);

        $this->confirmTotals($plan, 30000, 2)->assertOk();
        $this->readiness($plan)->assertJsonPath('cargo_ready', true);
    }

    public function test_updated_packaging_invalidates_only_the_dependent_item_and_the_shipment_totals(): void
    {
        $packaging = $this->product->defaultPackaging()->create([
            'name' => 'Csavaros doboz', 'pieces_per_package' => 100, 'gross_weight_kg' => '5.500',
        ]);
        $calculated = $this->intent(200, $this->pecs);
        $manual = $this->intent(200, $this->szekszard);
        $plan = $this->plan([$calculated, $manual]);
        $vehicle = $this->createVehicle(500, 4);
        $this->assignVehicle($plan, $vehicle['id'])->assertOk();
        $this->manualItem($plan, $manual, 12000)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->manualItem($plan, $manual, 12000, ['reason' => 'A külön mért tétel vastagabb csomagolást kapott.'])->assertOk()
            ->assertJsonPath('changed_intent_ids.0', $manual->id);
        $revision = $this->item($plan, $calculated)['suggestion']['packaging_revision'];
        $assignment = $this->assignment($plan, $calculated);
        $fields = ['source' => 'calculated', 'packaging_revision' => $revision, 'confirmed' => true];
        $this->edit($plan, "cargo/items/{$assignment}", $fields)->assertOk();
        $this->confirmTotals($plan, 23000, 0)->assertOk();

        $packaging->update(['gross_weight_kg' => '6.250']);
        $newCalculated = $this->item($plan, $calculated);
        $newManual = $this->item($plan, $manual);
        $this->assertSame(12500, $newCalculated['suggestion']['gross_weight_grams']);
        $this->assertNotSame($revision, $newCalculated['suggestion']['packaging_revision']);
        $this->assertSame('stale', $newCalculated['cargo']['status']);
        $this->assertSame('confirmed', $newManual['cargo']['status']);
        $this->assertSame(12000, $newManual['cargo']['gross_weight_grams']);
        $this->assertSame('stale', $this->cargo($plan)['confirmation']['status']);
        $readiness = $this->readiness($plan)->assertJsonPath('cargo_ready', false);
        $stale = collect($readiness->json('cargo_blockers'))->firstWhere('code', 'item_cargo_stale');
        $this->assertSame($calculated->id, $stale['transfer_intent_id']);
        $this->assertSame($assignment, $stale['assignment_id']);
        $this->edit($plan, "cargo/items/{$assignment}", $fields)->assertConflict();

        $packaging->update(['gross_weight_kg' => '5.500']);
        $reverted = $this->item($plan, $calculated);
        $this->assertSame(11000, $reverted['suggestion']['gross_weight_grams']);
        $this->assertNotSame($revision, $reverted['suggestion']['packaging_revision']);
        $this->assertSame('stale', $reverted['cargo']['status']);
        $this->assertSame('stale', $this->cargo($plan)['confirmation']['status']);

        $fields['packaging_revision'] = $reverted['suggestion']['packaging_revision'];
        $this->edit($plan, "cargo/items/{$assignment}", $fields)->assertOk();
        $this->confirmTotals($plan, 23000, 0)->assertOk();
        $this->readiness($plan)->assertJsonPath('cargo_ready', true);
    }

    public function test_inactivating_a_vehicle_audit_author_keeps_their_events(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($actor);
        $vehicle = $this->createVehicle(1200, 6);

        $this->actingAs($this->admin)->from(route('users.index'))->patch(route('users.access', $actor), ['active' => false, 'lock_version' => 1])
            ->assertRedirect(route('users.index'));
        $this->assertFalse($actor->fresh()->isActive());
        $this->assertDatabaseHas('vehicle_operations', ['vehicle_id' => $vehicle['id'], 'actor_id' => $actor->id]);
    }

    public function test_picking_changes_the_confirmation_basis_even_with_the_same_plan_version(): void
    {
        $intent = $this->intent(15, $this->pecs);
        $plan = $this->plan([$intent]);
        $vehicle = $this->createVehicle(1000, 4);
        $this->assignVehicle($plan, $vehicle['id'])->assertOk();
        $this->manualItem($plan, $intent, 8000)->assertOk();
        $this->confirmTotals($plan, 8000, 0)->assertOk();
        $this->readiness($plan)->assertJsonPath('cargo_ready', true)->assertJsonPath('vehicle_ready', true)
            ->assertJsonPath('stock_ready', false)->assertJsonPath('dispatch_prerequisites_met', false);
        $previous = $this->cargo($plan);
        $previousVersion = $plan->refresh()->version;

        $this->pick($intent);
        $this->assertSame($previousVersion, $plan->refresh()->version);
        $this->readiness($plan)->assertJsonPath('cargo_ready', false)->assertJsonPath('stock_ready', true)
            ->assertJsonPath('dispatch_prerequisites_met', false);
        $this->assertSame('stale', $this->cargo($plan)['confirmation']['status']);
        $this->edit($plan, 'cargo', [
            'basis_revision' => $previous['basis_revision'], 'gross_weight_grams' => 8000, 'pallet_slots' => 0,
        ])->assertConflict();

        $this->confirmTotals($plan, 8000, 0)->assertOk();
        $this->readiness($plan)->assertJsonPath('dispatch_prerequisites_met', true)->assertJsonPath('can_dispatch', true);
    }

    public function test_confirmation_replay_is_idempotent_and_changed_payload_or_stale_plan_version_conflicts(): void
    {
        $intent = $this->intent(10, $this->pecs);
        $plan = $this->plan([$intent]);
        $vehicle = $this->createVehicle(500, 4);
        $this->assignVehicle($plan, $vehicle['id'])->assertOk();
        $this->manualItem($plan, $intent, 7000)->assertOk();
        $before = $this->stockState();
        $url = $this->url($plan, 'cargo');
        $data = $this->command($plan->refresh()->version, [
            'basis_revision' => $this->cargo($plan)['basis_revision'], 'gross_weight_grams' => 7000, 'pallet_slots' => 1,
        ]);
        $saved = $this->patchJson($url, $data)->assertOk()->json();
        $this->patchJson($url, $data)->assertOk()->assertExactJson($saved);
        $this->patchJson($url, array_replace($data, ['pallet_slots' => 2]))->assertConflict();
        $this->patchJson($url, array_replace($data, ['operation_key' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame(1, ShipmentPlanOperation::where('operation_key', $data['operation_key'])->count());
        $this->assertSame($data['version'] + 1, $plan->refresh()->version);
        $this->assertSame($before, $this->stockState());
        $this->assertSame(1, $this->cargo($plan)['confirmation']['pallet_slots']);
    }

    public function test_item_writes_are_scoped_to_active_assignments_on_the_selected_draft(): void
    {
        $a = $this->intent(20, $this->pecs);
        $b = $this->intent(10, $this->pecs);
        $plan = $this->plan([$a]);
        $other = $this->plan([$b]);
        $remote = $this->assignment($other, $b);
        $fields = ['source' => 'manual', 'gross_weight_grams' => 8000, 'confirmed' => true];
        $this->edit($plan, "cargo/items/{$remote}", $fields)->assertNotFound();
        $this->edit($plan, 'cargo/items/999999999999999999999', $fields)->assertNotFound();
        $this->assertNull($this->item($other, $b)['cargo']);

        $local = $this->assignment($plan, $a);
        $this->deleteJson(route('shipment-plans.intents.detach', [$plan, $a]), $this->command($plan->refresh()->version, [
            'reason' => 'Tervezési módosítás.',
        ]))->assertOk();
        $this->edit($plan, "cargo/items/{$local}", $fields)->assertNotFound();
        $this->assertSame([], $this->cargo($plan)['items']);

        $other->update(['status' => 'in_transit']);
        $this->edit($other, "cargo/items/{$remote}", $fields)->assertConflict();
    }

    private function intent(int $quantity, Warehouse $destination): TransferIntent
    {
        $service = app(TransferIntentService::class);
        $created = $service->create($this->admin, [
            'operation_key' => (string) Str::uuid(), 'product_id' => $this->product->getKey(),
            'from_warehouse_id' => $this->origin->getKey(), 'to_warehouse_id' => $destination->getKey(),
            'quantity' => $quantity, 'shipping_preference' => 'groupable',
        ]);
        $intent = TransferIntent::findOrFail($created['intent']['id']);
        $service->approve($this->admin, $intent, $this->command($intent->version));

        return $intent->refresh();
    }

    private function pick(TransferIntent $intent): void
    {
        app(TransferStockReservationService::class)->reserve($this->admin, $intent, $this->command($intent->refresh()->version));
        $service = app(WarehouseTransferTaskService::class);
        $service->create($this->admin, $intent, $this->command($intent->refresh()->version));
        $service->pick($this->admin, $intent, $this->command($intent->refresh()->version, [
            'quantity' => $intent->quantity,
            'source_allocations' => [['source_type' => 'unassigned', 'location_id' => null, 'quantity' => $intent->quantity]],
            'unassigned_confirmation' => ['found' => true, 'description' => 'A kiadási területen összekészítve.'],
        ]));
    }

    private function plan(array $intents): Shipment
    {
        $id = $this->postJson(route('shipment-plans.store'), [
            'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $this->origin->getKey(),
            'reference' => 'Közös bemutatófuvar', 'vehicle_label' => 'Régi szabad szöveges rendszám',
            'intent_ids' => array_map(fn (TransferIntent $intent) => $intent->id, $intents),
        ])->assertCreated()->json('shipment.id');

        return Shipment::findOrFail($id);
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $user = User::factory()->create(['role' => 'worker']);
        $user->addWarehouseMembership($warehouse->getKey(), [$role]);

        return $user;
    }

    private function vehicleData(int|string|null $weight, ?int $slots): array
    {
        return [
            'name' => 'Tesztjármű', 'payload_capacity_kg' => $weight, 'pallet_slots_capacity' => $slots,
            'active' => true, 'note' => 'Ellenőrzött járműadat.',
        ];
    }

    private function createVehicle(int|string|null $weight, ?int $slots): array
    {
        return $this->postJson('/shipment-plans/vehicles', $this->vehicleData($weight, $slots) + [
            'operation_key' => (string) Str::uuid(),
        ])->assertCreated()->json('vehicle');
    }

    private function assignment(Shipment $shipment, TransferIntent $intent): int
    {
        return $shipment->intentAssignments()->active()->where('transfer_intent_id', $intent->id)->sole()->id;
    }

    private function url(Shipment $shipment, string $path): string
    {
        return "/shipment-plans/{$shipment->id}/{$path}";
    }

    private function command(int $version, array $data = []): array
    {
        return $data + ['operation_key' => (string) Str::uuid(), 'version' => $version];
    }

    private function edit(Shipment $shipment, string $path, array $data): TestResponse
    {
        return $this->patchJson($this->url($shipment, $path), $this->command($shipment->refresh()->version, $data));
    }

    private function assignVehicle(Shipment $shipment, ?int $id): TestResponse
    {
        return $this->edit($shipment, 'vehicle', ['vehicle_id' => $id]);
    }

    private function cargo(Shipment $shipment): array
    {
        return $this->getJson($this->url($shipment, 'cargo'))->assertOk()->json();
    }

    private function item(Shipment $shipment, TransferIntent $intent): array
    {
        return collect($this->cargo($shipment)['items'])->firstWhere('transfer_intent_id', $intent->id);
    }

    private function manualItem(Shipment $shipment, TransferIntent $intent, int $weight, array $data = []): TestResponse
    {
        $assignment = $this->assignment($shipment, $intent);

        return $this->edit($shipment, "cargo/items/{$assignment}", $data + [
            'source' => 'manual', 'gross_weight_grams' => $weight, 'confirmed' => true,
        ]);
    }

    private function confirmTotals(Shipment $shipment, int $weight, int $slots, array $data = []): TestResponse
    {
        return $this->edit($shipment, 'cargo', $data + [
            'basis_revision' => $this->cargo($shipment)['basis_revision'], 'gross_weight_grams' => $weight, 'pallet_slots' => $slots,
        ]);
    }

    private function readiness(Shipment $shipment): TestResponse
    {
        return $this->getJson(route('shipment-plans.readiness', $shipment))->assertOk();
    }

    private function stock(Warehouse $warehouse, int $quantity): void
    {
        Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $this->product->getKey(), 'quantity' => $quantity]);
    }

    private function stockState(): array
    {
        $tables = ['stocks', 'inventory_movements', 'inventory_transfers', 'warehouse_placements',
            'transfer_stock_reservations', 'warehouse_transfer_tasks', 'warehouse_transfer_allocations'];

        return array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    }
}
