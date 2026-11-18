<?php

namespace Tests\Feature;

use App\Models\{InventoryMovement, InventoryTransfer, Shipment, ShipmentIntentAssignment, Stock, User, Warehouse};
use Database\Seeders\{DatabaseSeeder, ShipmentCargoDemoSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShipmentCargoDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_seeder_populates_four_publicly_distinguishable_cargo_demo_scenarios(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@raktar.hu')->sole();
        $origin = Warehouse::where('warehouse_name', 'Bemutató raktár – Fuvar-előkészítés')->sole();
        $this->actingAs($admin);

        $ready = $this->plan('Kapacitás rendben');
        $exceeded = $this->plan('Járműkapacitás túllépve');
        $picking = $this->plan('Kitárolás folyamatban');
        $missing = $this->plan('Rakományadat hiányzik');
        $listing = $this->get(route('shipments.index'))->assertOk();
        foreach ([$ready, $exceeded, $picking, $missing] as $plan) {
            $listing->assertSeeText($plan->reference);
            $this->assertSame($origin->getKey(), (int) $plan->origin_warehouse_id);
            $this->assertSame('draft', $plan->status);
            $this->assertNull($plan->started_at);
        }

        $this->getJson(route('shipment-plans.show', $ready))->assertOk()
            ->assertJsonCount(3, 'assignments')->assertJsonCount(2, 'stops');
        $this->getJson(route('shipment-plans.cargo.show', $ready))->assertOk()
            ->assertJsonPath('confirmation.status', 'confirmed')
            ->assertJsonPath('confirmation.gross_weight_grams', 1000000)
            ->assertJsonPath('confirmation.pallet_slots', 4)
            ->assertJsonPath('confirmation.shared_materials_weight_grams', 25000)
            ->assertJsonPath('confirmed_item_weight_grams', 975000)
            ->assertJsonPath('capacity.weight_grams.limit', 1200000)
            ->assertJsonPath('capacity.weight_grams.remaining', 200000)
            ->assertJsonPath('capacity.pallet_slots.limit', 6)
            ->assertJsonPath('capacity.pallet_slots.remaining', 2);
        $this->getJson(route('shipment-plans.readiness', $ready))->assertOk()
            ->assertJsonPath('stock_ready_count', 3)
            ->assertJsonPath('dispatch_prerequisites_met', true)
            ->assertJsonPath('can_dispatch', true);

        $this->getJson(route('shipment-plans.readiness', $exceeded))->assertOk()
            ->assertJsonPath('stock_ready', true)
            ->assertJsonPath('cargo_ready', true)
            ->assertJsonPath('vehicle_ready', false)
            ->assertJsonPath('capacity.weight_grams.limit', 800000)
            ->assertJsonPath('capacity.weight_grams.required', 1000000)
            ->assertJsonPath('capacity.weight_grams.status', 'exceeded')
            ->assertJsonPath('capacity.pallet_slots.limit', 3)
            ->assertJsonPath('capacity.pallet_slots.required', 4)
            ->assertJsonPath('capacity.pallet_slots.status', 'exceeded')
            ->assertJsonPath('dispatch_prerequisites_met', false);
        $this->getJson(route('shipment-plans.readiness', $picking))->assertOk()
            ->assertJsonPath('transport_ready', true)
            ->assertJsonPath('stock_ready', false)
            ->assertJsonFragment(['required_quantity' => 20, 'staged_quantity' => 10, 'picking_status' => 'in_progress', 'reservation_status' => 'active'])
            ->assertJsonPath('dispatch_prerequisites_met', false);
        $missingCargo = $this->getJson(route('shipment-plans.cargo.show', $missing))->assertOk()
            ->assertJsonPath('confirmation', null)
            ->assertJsonFragment(['gross_weight_grams' => 11000, 'package_count' => 2]);
        $this->assertFalse($missingCargo->json('cargo_ready'));
        $this->getJson(route('shipment-plans.readiness', $missing))->assertOk()
            ->assertJsonPath('stock_ready', true)
            ->assertJsonPath('cargo_ready', false)
            ->assertJsonPath('dispatch_prerequisites_met', false);

        $assignments = ShipmentIntentAssignment::whereIn('shipment_id', [$ready->id, $exceeded->id, $picking->id, $missing->id])
            ->active()->with('intent')->get();
        $this->assertNotEmpty($assignments);
        $this->assertSame(['approved'], $assignments->pluck('intent.status')->unique()->values()->all());
        $products = $assignments->pluck('intent.product_id')->unique()->values();
        $this->assertFalse(InventoryTransfer::whereIn('product_id', $products)->exists());
        $this->assertFalse(Stock::whereIn('product_id', $products)->where('warehouse_id', '!=', $origin->getKey())->where('quantity', '>', 0)->exists());
        foreach ($products as $productId) {
            $received = (int) InventoryMovement::where('product_id', $productId)->where('warehouse_id', $origin->getKey())
                ->where('type', 'in')->sum('quantity');
            $this->assertGreaterThan(0, $received);
            $this->assertSame($received, (int) Stock::where('product_id', $productId)->where('warehouse_id', $origin->getKey())->sole()->quantity);
        }

        $before = $this->snapshot();
        $this->postJson(route('shipments.start', $ready), ['version' => $ready->version])->assertConflict();
        $this->assertSame($before, $this->snapshot());

        foreach (['manager' => 'supervisor', 'worker' => 'dispatch_operator'] as $username => $role) {
            $viewer = User::where('email', $username.'@raktar.hu')->sole();
            $this->assertDatabaseHas('warehouse_memberships', ['user_id' => $viewer->id, 'warehouse_id' => $origin->getKey(), 'role' => $role]);
            $this->actingAs($viewer)->getJson(route('shipment-plans.cargo.show', $ready))->assertOk()
                ->assertJsonPath('can_manage', $role === 'supervisor');
        }
    }

    public function test_reseeding_keeps_edited_demo_rows_and_the_stock_and_audit_history(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ready = $this->plan('Kapacitás rendben');
        $ready->update(['reference' => 'Oktatói, módosított fuvarnév']);
        $ready->vehicle->update(['payload_capacity_grams' => 950000]);
        $before = $this->snapshot();

        $this->travel(2)->hours();
        $this->seed(ShipmentCargoDemoSeeder::class);
        $this->seed(DatabaseSeeder::class);

        foreach ($this->snapshot() as $table => $rows) {
            $this->assertSame($before[$table], $rows, $table);
        }
        $this->assertSame('Oktatói, módosított fuvarnév', $ready->fresh()->reference);
        $this->assertSame(950000, $ready->vehicle()->sole()->payload_capacity_grams);
    }

    public function test_existing_demo_backfills_only_missing_destinations_using_the_original_warehouse_ids(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$pecs, $gyor] = $this->plan('Kapacitás rendben')->stops()->with('warehouse')->get()->pluck('warehouse')->all();
        $shelf = $pecs->storageLocations()->where('code', 'FUV-A-01')->sole();
        $shelf->update(['name' => 'Saját csavarpolc', 'max_weight_grams' => 42000]);
        $pecs->update(['warehouse_name' => 'Átnevezett pécsi telephely']);
        $pecs->storageLocations()->where('code', 'FUV-A-02')->delete();
        $gyor->storageLocations()->where('code', 'FUV-GY-01')->delete();

        $before = $this->snapshot();
        $this->seed(ShipmentCargoDemoSeeder::class);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('Saját csavarpolc', $shelf->fresh()->name);
        $this->assertSame(42000, $shelf->fresh()->max_weight_grams);
        $this->assertDatabaseHas('warehouse_locations', [
            'warehouse_id' => $pecs->getKey(), 'code' => 'FUV-A-02', 'kind' => 'shelf',
            'active' => true, 'max_weight_grams' => 100000, 'width_cm' => 100,
        ]);
        $this->assertDatabaseHas('warehouse_locations', [
            'warehouse_id' => $gyor->getKey(), 'code' => 'FUV-GY-01', 'kind' => 'pallet',
            'active' => true, 'max_weight_grams' => 1800000, 'max_pallet_slots' => 2,
        ]);
        $locations = DB::table('warehouse_locations')->orderBy('id')->get()->toJson();
        $this->seed(ShipmentCargoDemoSeeder::class);
        $this->assertSame($locations, DB::table('warehouse_locations')->orderBy('id')->get()->toJson());
        $this->assertSame($before, $this->snapshot());
    }

    private function plan(string $scenario): Shipment
    {
        return Shipment::where('planning_mode', Shipment::MODE_TRANSFER_INTENT)
            ->where('reference', '4/A bemutató – '.$scenario)->sole();
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach ([
            'vehicles', 'vehicle_operations', 'shipments', 'shipment_stops', 'shipment_items', 'shipment_intent_assignments',
            'shipment_plan_operations', 'shipment_assignment_cargos', 'shipment_cargo_confirmations', 'warehouse_memberships',
            'shipment_dispatches', 'shipment_transit_items', 'shipment_receipts', 'shipment_receipt_items', 'shipment_receipt_putaways',
            'transfer_intents', 'transfer_workflow_events', 'transfer_stock_reservations', 'warehouse_transfer_tasks',
            'warehouse_transfer_allocations', 'stocks', 'warehouse_placements', 'inventory_movement_requests', 'inventory_movements', 'inventory_transfers',
        ] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
