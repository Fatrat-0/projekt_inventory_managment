<?php

namespace Tests\Feature;

use App\Models\{Shipment, User, Vehicle, Warehouse};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleRegistryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_activity_with_a_versioned_audited_reason_without_overwriting_the_vehicle_note(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $vehicle = $this->actingAs($admin)->postJson(route('shipment-plans.vehicles.store'), [
            'operation_key' => (string) Str::uuid(),
            'name' => 'Budapesti teherautó', 'registration_number' => 'AB-CD 123',
            'note' => 'Oldalfalas teherautó', 'pallet_slots_capacity' => 0,
        ])->assertCreated()->assertJsonPath('vehicle.payload_capacity_grams', null)
            ->assertJsonPath('vehicle.pallet_slots_capacity', 0)->json('vehicle');
        $url = route('shipment-plans.vehicles.update', $vehicle['id']);

        $this->patchJson($url, $this->command($vehicle['version']) + [
            'active' => false, 'inactivation_reason' => str_repeat('é', 501),
        ])->assertUnprocessable()->assertJsonValidationErrors('inactivation_reason');
        $this->patchJson($url, $this->command($vehicle['version']) + [
            'name' => 'Átnevezés', 'inactivation_reason' => 'Szervizben',
        ])->assertUnprocessable()->assertJsonValidationErrors('inactivation_reason');

        $payload = $this->command($vehicle['version']) + ['active' => false, 'inactivation_reason' => '  Szervizben  '];
        $change = ['previous_active' => true, 'active' => false, 'reason' => 'Szervizben'];
        $inactivation = $this->patchJson($url, $payload)->assertOk()
            ->assertJsonPath('vehicle.active', false)->assertJsonPath('vehicle.version', $vehicle['version'] + 1)
            ->assertJsonPath('vehicle.note', 'Oldalfalas teherautó')->assertJsonPath('event.actor_id', $admin->id)
            ->assertJsonPath('event.status_change', $change)->assertJsonMissingPath('vehicle.status_change');

        $stored = Vehicle::findOrFail($vehicle['id'])->operations()->where('operation_key', $payload['operation_key'])->sole();
        $this->assertSame($change, $stored->result['status_change']);
        $this->assertSame($vehicle['id'], $stored->vehicle_id);
        $this->assertSame($stored->occurred_at->toISOString(), $inactivation->json('event.occurred_at'));
        $this->patchJson($url, array_replace($payload, ['inactivation_reason' => 'Szervizben']))
            ->assertOk()->assertExactJson($inactivation->json());
        $this->patchJson($url, array_replace($payload, ['inactivation_reason' => 'Másik indok']))->assertConflict();

        $this->actingAs($otherAdmin)->patchJson($url, $this->command($vehicle['version']) + [
            'name' => 'Elavult szerkesztés',
        ])->assertConflict();
        $this->patchJson($url, $this->command($vehicle['version'] + 1) + [
            'active' => true, 'inactivation_reason' => 'Az aktiválás nem inaktiválás.',
        ])->assertUnprocessable()->assertJsonValidationErrors('inactivation_reason');

        $edited = $this->patchJson($url, $this->command($vehicle['version'] + 1) + [
            'note' => 'Friss megjegyzés', 'payload_capacity_kg' => '1200.125',
        ])->assertOk()->assertJsonPath('vehicle.active', false)->assertJsonPath('vehicle.payload_capacity_grams', 1200125)
            ->assertJsonMissingPath('event.status_change')->json('vehicle');
        $this->patchJson($url, $this->command($edited['version']) + ['active' => true])->assertOk()
            ->assertJsonPath('vehicle.active', true)->assertJsonPath('vehicle.note', 'Friss megjegyzés')
            ->assertJsonPath('event.actor_id', $otherAdmin->id)
            ->assertJsonPath('event.status_change', ['previous_active' => false, 'active' => true, 'reason' => null]);
        $this->assertSame($change, $stored->refresh()->result['status_change']);
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseCount('vehicle_operations', 4);
    }

    public function test_an_operation_recorded_before_status_auditing_is_still_replayable(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $vehicle = Vehicle::create(['name' => 'Korábban inaktivált jármű', 'active' => false, 'version' => 2]);
        $payload = $this->command(1) + ['active' => false];
        $vehicle->operations()->create([
            'actor_id' => $actor->id,
            'operation_key' => $payload['operation_key'],
            'action' => 'updated',
            'request_hash' => hash('sha256', json_encode(['updated', $actor->id, $vehicle->id, 1, ['active' => false]], JSON_THROW_ON_ERROR)),
            'result' => $vehicle->registrySnapshot(),
            'occurred_at' => now(),
        ]);

        $this->actingAs($actor)->patchJson(route('shipment-plans.vehicles.update', $vehicle), $payload)->assertOk()
            ->assertJsonPath('vehicle.id', $vehicle->id)->assertJsonPath('vehicle.version', 2)
            ->assertJsonMissingPath('event.status_change');
        $this->assertDatabaseCount('vehicle_operations', 1);
    }

    public function test_activity_controls_selection_and_dispatch_without_changing_existing_transit_or_snapshot(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@raktar.hu')->sole();
        $supervisor = User::where('email', 'manager@raktar.hu')->sole();
        $draft = Shipment::where('reference', '4/A bemutató – Kapacitás rendben')->sole();
        $vehicle = $draft->vehicle;
        $vehicleUrl = route('shipment-plans.vehicles.update', $vehicle);
        $readiness = route('shipment-plans.readiness', $draft);
        $dispatch = route('shipment-plans.dispatch.store', $draft);
        $catalog = route('shipment-plans.vehicles.index');
        $draftCommand = $this->command($draft->version) + ['confirmed' => true];

        $this->actingAs($admin)->getJson($readiness)->assertOk()->assertJsonPath('can_dispatch', true);
        $newId = $this->postJson(route('shipment-plans.store'), [
            'operation_key' => (string) Str::uuid(), 'origin_warehouse_id' => $draft->origin_warehouse_id,
            'reference' => 'Másik aktív fuvarterv',
        ])->assertCreated()->json('shipment.id');
        $new = Shipment::findOrFail($newId);

        $this->actingAs($supervisor)->patchJson($vehicleUrl, $this->command($vehicle->version) + [
            'active' => false, 'inactivation_reason' => 'Jogosulatlan kérés',
        ])->assertForbidden();

        $this->actingAs($admin)->patchJson($vehicleUrl, $this->command($vehicle->version) + [
            'active' => false, 'inactivation_reason' => 'Szervizben',
        ])->assertOk();
        $this->getJson($catalog)->assertOk()->assertJsonFragment(['id' => $vehicle->id, 'active' => false]);
        $visibleIds = collect($this->actingAs($supervisor)->getJson($catalog)->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($visibleIds->containsStrict($vehicle->id));
        $this->getJson($readiness)->assertOk()->assertJsonPath('vehicle.id', $vehicle->id)->assertJsonPath('vehicle.active', false)
            ->assertJsonPath('can_dispatch', false)->assertJsonFragment(['code' => 'vehicle_inactive']);
        $this->patchJson(route('shipment-plans.vehicle.update', $new), $this->command($new->version) + [
            'vehicle_id' => $vehicle->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('vehicle_id');
        $this->postJson($dispatch, $draftCommand)->assertConflict()->assertJsonFragment(['code' => 'vehicle_inactive']);
        $this->assertSame($vehicle->id, $draft->refresh()->vehicle_id);
        $this->assertDatabaseCount('shipment_dispatches', 0);
        $this->assertDatabaseCount('shipment_transit_items', 0);

        $this->actingAs($admin)->patchJson($vehicleUrl, $this->command($vehicle->refresh()->version) + ['active' => true])->assertOk();
        $this->actingAs($supervisor)->getJson($readiness)->assertOk()->assertJsonPath('can_dispatch', true);
        $this->patchJson(route('shipment-plans.vehicle.update', $new), $this->command($new->version) + [
            'vehicle_id' => $vehicle->id,
        ])->assertOk()->assertJsonPath('cargo.vehicle.id', $vehicle->id);
        $state = route('shipment-plans.dispatch.show', $draft);
        $this->postJson($dispatch, $this->command($draft->version) + ['confirmed' => true])
            ->assertOk()->assertJsonPath('shipment.status', 'in_transit')->assertJsonCount(3, 'transit_items');
        $historical = $this->getJson($state)->assertOk()->assertJsonPath('dispatch.snapshot.vehicle.active', true)->json();
        $transit = $draft->transitItems()->get()->toArray();
        $stock = DB::table('stocks')->orderBy('id')->get()->toArray();
        $movementCount = DB::table('inventory_movements')->count();

        $this->actingAs($admin)->patchJson($vehicleUrl, $this->command($vehicle->refresh()->version) + [
            'active' => false, 'inactivation_reason' => 'Ismét szervizben',
        ])->assertOk();
        $this->getJson($state)->assertOk()->assertExactJson($historical);
        $this->assertSame('in_transit', $draft->refresh()->status);
        $this->assertSame($transit, $draft->transitItems()->get()->toArray());
        $this->assertEquals($stock, DB::table('stocks')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $movementCount);
    }

    public function test_vehicle_tab_is_active_for_authorized_viewers_and_only_admin_can_manage(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'worker']);
        $requester = User::factory()->create(['role' => 'worker']);
        $warehouse = Warehouse::factory()->create();
        $operator->warehouseMemberships()->create(['warehouse_id' => $warehouse->getKey(), 'role' => 'dispatch_operator']);
        $requester->warehouseMemberships()->create(['warehouse_id' => $warehouse->getKey(), 'role' => 'requester']);
        $url = route('vehicle-pages.index');

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($requester)->get($url)->assertForbidden();

        $operatorPage = $this->actingAs($operator)->get($url)->assertOk()
            ->assertViewHas('canManageVehicles', false)->assertSee('Járművek')->assertDontSee('Új jármű');
        $this->assertMatchesRegularExpression('~href="'.preg_quote($url, '~').'"\s+aria-current="page"~', $operatorPage->getContent());

        $this->actingAs($admin)->get($url)->assertOk()->assertViewHas('canManageVehicles', true)
            ->assertSeeInOrder(['Igények', 'Fuvarok és szállítmányok', 'Raktári feladatok', 'Járművek'])
            ->assertSee('Új jármű');
    }

    public function test_related_shipments_keep_status_filters_and_warehouse_visibility(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'worker']);
        $unauthorized = User::factory()->create(['role' => 'worker']);
        $myOrigin = Warehouse::factory()->create();
        $outside = Warehouse::factory()->create();
        $operator->warehouseMemberships()->create(['warehouse_id' => $myOrigin->getKey(), 'role' => 'dispatch_operator']);
        $vehicle = Vehicle::create(['name' => 'Kiadható']);
        $common = ['vehicle_id' => $vehicle->id, 'user_id' => $admin->id, 'planning_mode' => Shipment::MODE_TRANSFER_INTENT];
        $localDraft = Shipment::create($common + [
            'origin_warehouse_id' => $myOrigin->getKey(), 'status' => 'draft', 'reference' => 'Helyi tervezet',
        ]);
        $localHistory = Shipment::create($common + [
            'origin_warehouse_id' => $myOrigin->getKey(), 'status' => 'completed', 'reference' => 'Helyi korábbi fuvar',
        ]);
        $remoteTransit = Shipment::create($common + [
            'origin_warehouse_id' => $outside->getKey(), 'status' => 'in_transit', 'reference' => 'Másik raktár fuvarja',
        ]);
        $url = route('shipment-plans.vehicles.shipments', $vehicle);

        $this->actingAs($unauthorized)->getJson($url)->assertForbidden();
        $this->actingAs($operator)->getJson($url)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissing(['reference' => $remoteTransit->reference]);
        $this->getJson($url.'?status=draft')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $localDraft->id)->assertJsonPath('data.0.shipment_number', $localDraft->shipment_number)
            ->assertJsonPath('data.0.origin_warehouse_name', $myOrigin->warehouse_name)
            ->assertJsonPath('data.0.url', route('shipments.show', $localDraft));
        $this->getJson($url.'?status=history')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $localHistory->id)->assertJsonPath('data.0.status_label', 'Teljesítve');
        $this->getJson($url.'?status=in_transit')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin)->getJson($url.'?status=in_transit')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $remoteTransit->id)->assertJsonPath('data.0.status_label', 'Úton');
        $this->getJson($url.'?status=missing&page=-1')->assertUnprocessable()->assertJsonValidationErrors(['status', 'page']);
        $this->patchJson(route('shipment-plans.vehicles.update', $vehicle), $this->command($vehicle->version) + [
            'active' => false,
        ])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($operator)->getJson($url)->assertNotFound();
    }

    private function command(int $version): array
    {
        return ['version' => $version, 'operation_key' => (string) Str::uuid()];
    }
}
