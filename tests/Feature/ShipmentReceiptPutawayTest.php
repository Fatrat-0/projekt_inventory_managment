<?php

namespace Tests\Feature;

use App\Models\{InventoryMovement, Shipment, ShipmentReceiptItem, User, WarehouseLocation};
use App\Services\{WarehouseCapacity, WarehouseStockAvailability, WarehouseStorageDesigner};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShipmentReceiptPutawayTest extends TestCase
{
    use RefreshDatabase;

    private ShipmentReceiptItem $screws;
    private ShipmentReceiptItem $remote;
    private Shipment $shipment;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->shipment = Shipment::where('reference', '4/A bemutató – Kapacitás rendben')->sole();
        $this->admin = User::where('email', 'admin@raktar.hu')->sole();
        $this->actingAs($this->admin)->postJson(route('shipment-plans.dispatch.store', $this->shipment), [
            'operation_key' => (string) Str::uuid(), 'version' => $this->shipment->version, 'confirmed' => true,
        ])->assertOk();
        foreach ($this->shipment->stops as $stop) {
            $this->postJson(route('shipment-plans.stops.receipts.store', [$this->shipment, $stop]), [
                'operation_key' => (string) Str::uuid(), 'version' => $stop->receiving_version, 'confirmed' => true,
                'items' => $this->shipment->transitItems()->where('shipment_stop_id', $stop->id)->get()
                    ->map(fn ($item) => ['transit_item_id' => $item->id, 'quantity' => $item->remaining_quantity])->all(),
            ])->assertCreated();
        }
        $this->screws = ShipmentReceiptItem::where('received_quantity', 200)->sole();
        $this->remote = ShipmentReceiptItem::where('received_quantity', 24)->sole();
    }

    public function test_manual_and_shipment_tasks_share_filters_order_pagination_and_warehouse_permissions(): void
    {
        $warehouse = $this->screws->warehouse;
        $product = $this->screws->product;
        $created = $this->postJson(route('movements.store'), [
            'type' => 'in', 'product_id' => $product->getKey(), 'warehouse_id' => $warehouse->getKey(),
            'quantity' => 40, 'confirmed' => true, 'request_key' => (string) Str::uuid(),
        ])->assertCreated();
        $batchId = $created->json('batch.id');
        $reference = $created->json('movement.movement_number');
        $work = route('warehouse-putaway-pages.manual', $batchId);
        $created->assertJsonPath('putaway_url', $work);
        $this->assertNotEmpty($reference);
        $movementUrl = $created->json('movement_url');
        $this->get($movementUrl)->assertOk()->assertSeeText('A bevételezés betárolása')
            ->assertSeeText('Betárolás folytatása')->assertSee($work);
        $detail = $this->get($work)->assertOk()->assertSeeText($reference)->viewData('detail');
        $this->assertSame(40, $detail['item']['pending_putaway_quantity']);

        $local = $this->member($warehouse->getKey(), 'receiving_operator');
        $this->actingAs($local);
        $queue = 'warehouse-putaway-pages.index';
        $filters = ['search' => $product->sku, 'sort_by' => 'received_quantity', 'sort_dir' => 'asc'];
        $first = $this->get(route($queue, $filters + ['per_page' => 1]))->assertOk()->viewData('items');
        $this->assertSame(2, $first->total());
        $this->assertSame(['manual'], array_column($first->items(), 'origin'));
        $this->assertSame(40, (int) $first->items()[0]['pending_putaway_quantity']);
        $second = $this->get(route($queue, $filters + ['per_page' => 1, 'page' => 2]))->assertOk()->viewData('items');
        $this->assertSame(['shipment'], array_column($second->items(), 'origin'));
        $this->assertSame(200, (int) $second->items()[0]['pending_putaway_quantity']);
        foreach (['manual' => 'Kézi bevételezés', 'shipment' => 'Fuvarátvétel'] as $origin => $label) {
            $response = $this->get(route($queue, $filters + ['origin' => $origin]))->assertOk()->assertSeeText($label);
            $this->assertSame([$origin], array_column($response->viewData('items')->items(), 'origin'));
        }
        $found = $this->get(route($queue, ['search' => $reference]))->assertOk();
        $this->assertSame([$reference], array_column($found->viewData('items')->items(), 'reference_number'));
        $this->getJson(route('warehouse-task-counts'))->assertJsonPath('counts.putaway', 3);

        $rack = app(WarehouseStorageDesigner::class)->create($warehouse, [
            'version' => $warehouse->floor_version, 'kind' => 'rack', 'code' => '6C2-R',
            'name' => 'Bevételezési próbaállvány', 'zone' => 'A', 'width' => 2, 'height' => 2,
            'rack_height' => 2, 'x' => 0, 'y' => 0, 'levels' => 1, 'bins' => 1,
            'bin_weight' => 50, 'level_weight' => 50, 'system_weight' => 50,
        ]);
        $location = $warehouse->storageSystems()->findOrFail($rack['id'])->locations()->sole();
        $plan = route('warehouse-putaway-pages.manual-plan', [$batchId, $location]);
        $this->get($plan)->assertOk()->assertViewHas('plan', fn ($view) => $view['canEdit'] === false
            && $view['focus'] === 'system-'.$location->system_id && $view['focusBin'] === $location->id);

        $this->actingAs($this->member($this->remote->to_warehouse_id, 'receiving_operator'));
        $this->get($work)->assertForbidden();
        $this->get($plan)->assertForbidden();
        $noManual = $this->get(route($queue, ['origin' => 'manual']))->assertOk()->viewData('items');
        $this->assertSame(0, $noManual->total());
        $this->getJson(route('warehouse-task-counts'))->assertJsonPath('counts.putaway', 1);

        $this->actingAs($local);
        $this->postJson(route('movements.in.receipts.putaway', $batchId), $this->command($location, 40, 4))
            ->assertCreated()->assertJsonPath('batch.status', 'completed')->assertJsonPath('location_view_url', $plan);
        $this->getJson(route('warehouse-task-counts'))->assertJsonPath('counts.putaway', 2);
        $done = $this->get(route($queue, ['origin' => 'manual', 'status' => 'completed']))->assertOk()->viewData('items');
        $this->assertSame(40, (int) $done->items()[0]['placed_quantity']);
        $this->get($movementUrl)->assertOk()->assertSeeText('Teljesen betárolva')
            ->assertSeeText('Betárolási előzmények')->assertSeeText($location->code)
            ->assertDontSeeText('Betárolás folytatása');
        $this->getJson(route('movements.in.receipts.history', $batchId))->assertOk()->assertJsonPath('data.0.location_view_url', $plan);
    }

    public function test_completed_shipment_puts_200_pecs_screws_into_two_bins_without_booking_stock_again(): void
    {
        $local = $this->member($this->screws->to_warehouse_id, 'receiving_operator');
        $receiver = $this->member($this->remote->to_warehouse_id, 'supervisor');
        $first = $this->screws->warehouse->storageLocations()->where('code', 'FUV-A-01')->sole();
        $second = $this->screws->warehouse->storageLocations()->where('code', 'FUV-A-02')->sole();
        $gyor = $this->remote->warehouse->storageLocations()->where('code', 'FUV-GY-01')->sole();
        $gyorCargo = array_replace($this->command($gyor, 24, 564), [
            'pallet_slots' => 1, 'dimensions_cm' => ['width' => 120, 'depth' => 80, 'height' => 120],
        ]);
        $this->assertSame('completed', $this->shipment->fresh()->status);
        $movements = InventoryMovement::count();
        $before = $this->stock($this->screws);
        $this->assertSame([200, 200, 0], $before);

        $this->actingAs($local)->getJson($this->url())->assertOk()
            ->assertJsonPath('item.status', 'pending')->assertJsonPath('item.pending_putaway_quantity', 200)
            ->assertJsonPath('item.can_putaway', true)->assertJsonPath('shipment.status', 'completed');
        $pending = route('shipment-plans.receiving.pending-putaway');
        $this->getJson($pending)->assertOk()->assertJsonCount(2, 'data');
        $command = $this->command($first, 120, 12);
        $this->postJson($this->url('recommendations'), $this->cargo(120, 12))->assertOk()
            ->assertJsonCount(2, 'recommendations')->assertJsonPath('recommendations.0.status', 'verified');
        $this->assertSame($before, $this->stock($this->screws));
        $this->assertDatabaseCount('shipment_receipt_putaways', 0);
        $firstResult = $this->postJson($this->url(), $command)->assertCreated()
            ->assertJsonPath('item.status', 'partial')->assertJsonPath('item.pending_putaway_quantity', 80)
            ->assertJsonPath('item.placed_quantity', 120)->assertJsonPath('item.version', 2)
            ->assertJsonPath('event.geometry_status', 'verified')->assertJsonPath('event.cargo_weight_grams', 12000)
            ->assertJsonPath('placement.location_id', $first->id);
        $this->assertSame([200, 80, 120], $this->stock($this->screws));
        $this->postJson($this->url(), $command)->assertCreated()->assertExactJson($firstResult->json());
        $this->postJson($this->url(), array_replace($command, ['quantity' => 119]))->assertConflict();
        $this->postJson($this->url(), $this->command($second, 80, 8))->assertConflict();
        $this->postJson($this->url(), $this->command($second, 81, 8, 2))->assertConflict();
        $this->postJson($this->url(), $this->command($second, 80, 8, 2))->assertCreated()
            ->assertJsonPath('item.status', 'completed')->assertJsonPath('item.pending_putaway_quantity', 0)
            ->assertJsonPath('item.placed_quantity', 200)->assertJsonPath('item.can_putaway', false);
        $this->assertSame([200, 0, 200], $this->stock($this->screws));
        $this->assertSame([120, 12000], [$first->placements()->sole()->quantity, $first->placements()->sole()->cargo_weight_grams]);
        $this->assertSame([80, 8000], [$second->placements()->sole()->quantity, $second->placements()->sole()->cargo_weight_grams]);
        $this->assertSame(1, InventoryMovement::where('shipment_receipt_item_id', $this->screws->id)->where('type', 'in')->count());
        $this->getJson($pending)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url('history'))->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.location_code', 'FUV-A-02')->assertJsonPath('data.1.location_code', 'FUV-A-01')
            ->assertJsonPath('data.0.transfer_intent_id', $this->screws->transfer_intent_id)
            ->assertJsonPath('data.1.receipt_item_id', $this->screws->id)->assertJsonPath('data.1.actor_id', $local->id);

        $this->postJson($this->url('show', $this->remote), $gyorCargo)->assertForbidden();
        $this->actingAs($receiver)->getJson($pending)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->remote->id);
        $this->postJson($this->url('show', $this->remote), $gyorCargo)->assertCreated()
            ->assertJsonPath('item.status', 'completed');
        $this->assertSame([24, 0, 24], $this->stock($this->remote));
        $this->assertDatabaseCount('inventory_movements', $movements);

        app(WarehouseCapacity::class)->release($first, $first->placements()->sole());
        $this->actingAs($local)->getJson($this->url('history'))->assertOk()
            ->assertJsonPath('data.1.placement_exists', false)->assertJsonPath('data.1.location_code', 'FUV-A-01');
    }

    public function test_confirmed_placements_and_history_link_to_the_original_bin_when_it_can_be_viewed(): void
    {
        $local = $this->member($this->screws->to_warehouse_id, 'receiving_operator');
        $remote = $this->member($this->remote->to_warehouse_id, 'receiving_operator');
        $warehouse = $this->screws->warehouse;
        $rack = app(WarehouseStorageDesigner::class)->create($warehouse, [
            'version' => $warehouse->floor_version, 'kind' => 'rack', 'code' => '5C1-R',
            'name' => 'Előzmény állvány', 'zone' => 'A', 'width' => 2, 'height' => 2, 'rack_height' => 2, 'x' => 0, 'y' => 0,
            'levels' => 1, 'bins' => 1, 'bin_weight' => 50, 'level_weight' => 50, 'system_weight' => 50,
        ]);
        $positioned = $warehouse->storageSystems()->findOrFail($rack['id'])->locations()->sole();
        $unplaced = $this->bin('5C1-UNPLACED');
        $plan = route('warehouse-putaway-pages.plan', [$this->screws, $positioned]);
        $detail = route('warehouses.locations.show', [$unplaced->warehouse_id, $unplaced]);
        $before = InventoryMovement::count();
        $history = $this->url('history');
        $this->actingAs($local)->postJson($this->url(), $this->command($positioned, 120, 12))->assertCreated()
            ->assertJsonPath('item.status', 'partial')->assertJsonPath('placement.location_id', $positioned->id)
            ->assertJsonPath('location_view_url', $plan);
        $this->get($plan)->assertOk()->assertViewHas('plan', fn ($view) => $view['canEdit'] === false
            && $view['focus'] === 'system-'.$positioned->system_id && $view['focusBin'] === $positioned->id);
        $this->postJson($this->url(), $this->command($unplaced, 80, 8))->assertConflict()->assertJsonMissingPath('location_view_url');

        $command = $this->command($unplaced, 80, 8, 2);
        $this->postJson($this->url(), $command)->assertCreated()
            ->assertJsonPath('item.status', 'completed')->assertJsonPath('placement.location_id', $unplaced->id)
            ->assertJsonPath('location_view_url', $detail);
        $this->get($detail)->assertOk();
        $this->getJson($history)->assertOk()
            ->assertJsonPath('data.0.location_view_url', $detail)->assertJsonPath('data.1.location_view_url', $plan);
        $this->actingAs($remote)->getJson($history)->assertForbidden()->assertJsonMissingPath('location_view_url');
        $this->get($plan)->assertForbidden();

        // A warehouse membership does not grant access to the general location page by itself.
        $unsupported = clone $local;
        $unsupported->role = 'unsupported';
        $this->actingAs($unsupported)->get($detail)->assertForbidden();
        $this->getJson($history)->assertOk()
            ->assertJsonPath('data.0.location_code', $unplaced->code)->assertJsonPath('data.0.location_view_url', null)
            ->assertJsonPath('data.1.location_view_url', $plan);

        app(WarehouseCapacity::class)->release($unplaced, $unplaced->placements()->sole());
        $unplaced->delete();
        $replacement = $this->bin($unplaced->code);
        $this->assertNotSame($unplaced->id, $replacement->id);
        $this->actingAs($local)->getJson($history)->assertOk()
            ->assertJsonPath('data.0.location_id', $unplaced->id)->assertJsonPath('data.0.placement_exists', false)
            ->assertJsonPath('data.0.location_code', $unplaced->code)->assertJsonPath('data.0.location_view_url', null);
        $this->postJson($this->url(), $command)->assertCreated()
            ->assertJsonPath('placement.location_id', $unplaced->id)->assertJsonPath('location_view_url', null);
        $this->assertDatabaseCount('inventory_movements', $before);
    }

    public function test_recommendations_and_manual_checks_use_the_receipt_destination_and_keep_unknown_data_explicit(): void
    {
        $eligible = $this->bin('B-01', attributes: ['floor_x' => 10, 'floor_y' => 10]);
        $inactive = $this->bin('B-BLOCKED', attributes: ['active' => false]);
        $tooSmall = $this->bin('B-SMALL', attributes: ['width_cm' => 4]);
        $foreign = $this->bin('B-REMOTE', $this->remote);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url())->assertUnauthorized();
        foreach (['requester', 'dispatch_operator'] as $role) {
            $this->actingAs($this->member($this->screws->to_warehouse_id, $role))
                ->getJson($this->url())->assertForbidden();
            $this->postJson($this->url(), $this->command($eligible, 10, 3))->assertForbidden();
        }
        $this->actingAs($this->member($this->remote->to_warehouse_id, 'receiving_operator'))
            ->getJson($this->url('history'))->assertForbidden();

        $this->actingAs($this->admin)->postJson($this->url('recommendations'), ['quantity' => 20])->assertOk()
            ->assertJsonPath('cargo.cargo_weight_grams', null)->assertJsonPath('cargo.pallet_slots', null)
            ->assertJsonPath('recommendations.0.status', 'conditional');
        $data = $this->postJson($this->url('recommendations'), $this->cargo(20, 2))->assertOk()->json();
        $locations = collect([...$data['recommendations'], ...$data['rejected']])->keyBy('location_id');
        $this->assertSame('verified', $locations[$eligible->id]['status']);
        $this->assertSame(['before' => 0, 'after' => 20], $locations[$eligible->id]['product_stock']['bin']);
        $this->assertSame('rejected', $locations[$inactive->id]['status']);
        $this->assertSame('rejected', $locations[$tooSmall->id]['status']);
        $this->assertFalse($locations->has($foreign->id));
        $this->assertStringContainsString('location='.$eligible->id, $locations[$eligible->id]['floor_plan_url']);
        $this->postJson($this->url('locations'), $this->cargo(20, 2) + ['search' => 'b-01'])->assertOk()
            ->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.location_id', $eligible->id)
            ->assertJsonPath('locations.0.product_stock.bin', ['before' => 0, 'after' => 20]);
        $this->postJson($this->url('locations'), $this->cargo(20, 2) + ['to_location_id' => $foreign->id])->assertOk()
            ->assertJsonCount(0, 'locations');
        $this->postJson($this->url(), $this->command($foreign, 10, 2))->assertUnprocessable()
            ->assertJsonValidationErrors('to_location_id');
        $this->postJson($this->url(), $this->command($inactive, 10, 2))->assertUnprocessable()
            ->assertJsonValidationErrors('to_location_id');
        $this->postJson($this->url(), $this->command($eligible, 10, 2) + ['to_warehouse_id' => $foreign->warehouse_id])
            ->assertUnprocessable()->assertJsonValidationErrors('to_warehouse_id');
        $this->assertDatabaseCount('shipment_receipt_putaways', 0);
    }

    public function test_commit_rechecks_capacity_and_known_geometry_and_requires_a_site_check_for_unknown_fit(): void
    {
        $bin = $this->bin('C-01', attributes: ['max_weight_grams' => 5000]);
        $command = $this->command($bin, 10, 5);
        $this->postJson($this->url('recommendations'), $this->cargo(10, 5))->assertOk()
            ->assertJsonPath('recommendations.0.location_id', $bin->id);
        $bin->update(['max_weight_grams' => 4900]);
        $this->postJson($this->url(), $command + ['site_verified' => true])->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $bin->update(['max_weight_grams' => 15000]);
        foreach ([0, -1, 1.5] as $value) {
            $this->postJson($this->url(), array_replace($command, ['quantity' => $value]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        foreach (['cargo_weight_kg', 'pallet_slots'] as $field) {
            $this->postJson($this->url(), array_diff_key($command, [$field => true]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($this->url(), array_replace($command, ['dimensions_cm' => ['width' => 101], 'site_verified' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('dimensions_cm');
        $unknown = array_diff_key($command, ['dimensions_cm' => true]);
        $this->postJson($this->url(), $unknown)->assertUnprocessable()->assertJsonValidationErrors('site_verified');
        $this->assertSame([200, 200, 0], $this->stock($this->screws));
        $this->postJson($this->url(), $unknown + ['site_verified' => true])->assertCreated()
            ->assertJsonPath('event.geometry_status', 'site_verified')->assertJsonPath('event.dimensions_cm', null);
        $this->postJson($this->url('locations'), $this->cargo(10, 5) + ['to_location_id' => $bin->id])->assertOk()
            ->assertJsonPath('locations.0.status', 'conditional')
            ->assertJsonPath('locations.0.checks.missing.remaining_physical_space', 'A tárolt vagy lefoglalt áru mellett a szabad hely nem ismert. Ellenőrizd a helyszínen, hogy van-e egybefüggő szabad hely a rakománynak.');
        $this->postJson($this->url(), $this->command($bin, 10, 5, 2))->assertUnprocessable()->assertJsonValidationErrors('site_verified');
    }

    public function test_the_packaging_revision_and_whole_packages_control_calculated_weight_for_a_partial_putaway(): void
    {
        $bin = $this->bin('D-01');
        $version = $this->getJson($this->url())->assertOk()->assertJsonPath('packaging.pieces_per_package', 100)->json('packaging.revision');
        $calculation = ['weight_source' => 'calculated', 'cargo_weight_packaging' => $version, 'packaging_confirmed' => true];
        $command = array_diff_key($this->command($bin, 100, 0), ['cargo_weight_kg' => true]) + $calculation;
        $this->postJson($this->url(), array_replace($command, ['quantity' => 50]))->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->postJson($this->url('recommendations'), ['quantity' => 100, 'pallet_slots' => 0] + $calculation)
            ->assertOk()->assertJsonPath('cargo.cargo_weight_grams', 5500)->assertJsonPath('recommendations.0.status', 'conditional');
        $this->postJson($this->url(), $command)->assertCreated()->assertJsonPath('event.cargo_weight_grams', 5500);
        $this->getJson($this->url('history'))->assertOk()->assertJsonPath('data.0.packaging_revision', $version);
        $this->screws->product->defaultPackaging()->update(['gross_weight_kg' => 6]);
        $this->postJson($this->url(), array_replace($command, ['version' => 2, 'operation_key' => (string) Str::uuid(), 'site_verified' => true]))
            ->assertConflict();
        $this->assertSame([200, 100, 100], $this->stock($this->screws));
    }

    public function test_a_late_audit_failure_rolls_back_and_committed_events_cannot_be_rewritten(): void
    {
        $receiver = $this->member($this->screws->to_warehouse_id, 'receiving_operator');
        $bin = $this->bin('E-01');
        $this->actingAs($receiver);
        $command = $this->command($bin, 10, 2) + ['note' => 'Ellenőrzött csomag'];
        DB::unprepared("CREATE TRIGGER test_abort_putaway BEFORE INSERT ON shipment_receipt_putaways BEGIN SELECT RAISE(ABORT, 'forced audit error'); END");
        try {
            $this->postJson($this->url(), $command)->assertServerError();
            $this->assertSame([200, 200, 0], $this->stock($this->screws));
            $this->assertSame(1, $this->screws->refresh()->putaway_version);
            $this->assertFalse($bin->placements()->exists());
        } finally {
            DB::unprepared('DROP TRIGGER test_abort_putaway');
        }
        $id = $this->postJson($this->url(), $command)->assertCreated()->json('event.id');
        foreach ([
            fn () => DB::table('shipment_receipt_putaways')->where('id', $id)->update(['note' => 'Átírt megjegyzés']),
            fn () => DB::table('shipment_receipt_putaways')->where('id', $id)->delete(),
            fn () => DB::table('users')->where('id', $receiver->id)->delete(),
        ] as $mutate) {
            try {
                $mutate();
                $this->fail('A betárolási audit módosítható.');
            } catch (QueryException) {
                $this->assertDatabaseHas('shipment_receipt_putaways', ['id' => $id, 'note' => 'Ellenőrzött csomag']);
            }
        }
    }

    public function test_manual_and_suggested_putaway_share_pallet_level_and_rack_limits(): void
    {
        $pallet = $this->bin('PAL', attributes: ['kind' => 'pallet', 'max_pallet_slots' => 1]);
        $tooManyPallets = array_replace($this->command($pallet, 10, 3), ['pallet_slots' => 2, 'site_verified' => true]);
        $this->postJson($this->url('locations'), $tooManyPallets)->assertOk()
            ->assertJsonPath('locations.0.status', 'rejected')
            ->assertJsonPath('locations.0.checks.failed.pallet_space', 'PAL tárhelyen 1 szabad raklaphely van. Hiányzik 1 hely.');
        $this->postJson($this->url(), $tooManyPallets)->assertUnprocessable()->assertJsonValidationErrors('pallet_slots');
        $this->postJson($this->url(), array_replace($tooManyPallets, ['pallet_slots' => 1]))->assertCreated();
        $this->assertSame(1, $pallet->placements()->sole()->pallet_slots);
        $this->assertSame(0, WarehouseLocation::withCapacityUsage()->findOrFail($pallet->id)->freeSlots());

        $warehouse = $this->screws->warehouse;
        $result = app(WarehouseStorageDesigner::class)->create($warehouse, [
            'version' => $warehouse->floor_version, 'kind' => 'rack', 'name' => 'Próbaállvány',
            'code' => '5B-R', 'zone' => 'A', 'width' => 4, 'height' => 2, 'rack_height' => 2, 'x' => 0, 'y' => 0,
            'levels' => 2, 'bins' => 2, 'bin_weight' => 20, 'level_weight' => 30, 'system_weight' => 35,
        ]);
        $system = $warehouse->storageSystems()->findOrFail($result['id']);
        [$filled, $sameLevel, $otherLevel] = $system->locations()->orderBy('code')->get()->all();
        $this->postJson($this->url(), $this->command($filled, 15, 20, 2))->assertCreated();
        $this->postJson($this->url(), $this->command($sameLevel, 10, 11, 3) + ['site_verified' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->postJson($this->url('locations'), $this->command($sameLevel, 10, 11, 3))->assertOk()
            ->assertJsonPath('locations.0.free_weight_grams', 10000)
            ->assertJsonPath('locations.0.checks.failed.level_weight', 'A tétel meghaladná az állványszint szabad teherbírását.');
        $this->postJson($this->url(), $this->command($otherLevel, 10, 16, 3) + ['site_verified' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('cargo_weight_kg');
        $this->postJson($this->url('locations'), $this->command($otherLevel, 10, 16, 3))->assertOk()
            ->assertJsonPath('locations.0.free_weight_grams', 15000)
            ->assertJsonPath('locations.0.status', 'rejected')
            ->assertJsonStructure(['locations' => [['checks' => ['failed' => ['system_weight']]]]]);
        $this->postJson($this->url(), $this->command($otherLevel, 10, 15, 3))->assertCreated();
        $this->assertSame(35000, (int) DB::table('warehouse_placements')->whereIn('warehouse_location_id', $system->locations()->pluck('id'))
            ->sum('cargo_weight_grams'));
        $this->assertSame([200, 165, 35], $this->stock($this->screws));
    }

    private function bin(string $code, ?ShipmentReceiptItem $item = null, array $attributes = []): WarehouseLocation
    {
        $bin = ($item ?? $this->screws)->warehouse->storageLocations()->create([
            'code' => $code, 'name' => $code, 'kind' => 'shelf', 'active' => true, 'max_weight_grams' => 50000,
        ]);
        $bin->forceFill($attributes + ['width_cm' => 100, 'depth_cm' => 100, 'height_cm' => 100])->save();

        return $bin;
    }

    private function cargo(int $quantity, int $weight): array
    {
        return ['quantity' => $quantity, 'cargo_weight_kg' => $weight, 'pallet_slots' => 0,
            'dimensions_cm' => ['width' => 20, 'depth' => 10, 'height' => 10]];
    }

    private function command(WarehouseLocation $bin, int $quantity, int $weight, int $version = 1): array
    {
        return $this->cargo($quantity, $weight) + ['to_location_id' => $bin->id, 'operation_key' => (string) Str::uuid(),
            'version' => $version, 'confirmed' => true];
    }

    private function member(int $warehouse, string $role): User
    {
        $member = User::factory()->create(['role' => 'worker']);
        $member->warehouseMemberships()->create(['warehouse_id' => $warehouse, 'role' => $role]);

        return $member;
    }

    private function url(string $action = 'show', ?ShipmentReceiptItem $item = null): string
    {
        return route('shipment-receipt-items.putaway.'.$action, $item ?? $this->screws);
    }

    private function stock(ShipmentReceiptItem $item): array
    {
        $snapshot = app(WarehouseStockAvailability::class)->snapshot($item->to_warehouse_id, $item->product_id);

        return [$snapshot['physical_quantity'], $snapshot['pending_putaway_quantity'], $snapshot['assigned_quantity']];
    }
}

