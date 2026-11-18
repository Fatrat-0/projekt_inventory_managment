<?php

namespace Tests\Feature;

use App\Models\{Shipment, User};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShipmentDispatchWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_workspace_offers_the_actions_per_role_and_retains_historical_actor_and_products(): void
    {
        $this->seed(DatabaseSeeder::class);
        $plan = Shipment::where('reference', '4/A bemutató – Kapacitás rendben')->sole();
        $admin = User::where('email', 'admin@raktar.hu')->sole();
        $supervisor = User::where('email', 'manager@raktar.hu')->sole();
        $operator = User::where('email', 'worker@raktar.hu')->sole();
        $show = route('shipments.show', $plan);
        $readiness = route('shipment-plans.readiness', $plan);

        $this->actingAs($supervisor)->get($show)->assertOk()->assertSee('Fuvar feladása')->assertDontSee('Járműtörzs');
        $this->getJson($readiness)->assertOk()->assertJsonPath('has_dispatch_permission', true)->assertJsonPath('can_dispatch', true);
        $this->actingAs($operator)->get($show)->assertOk()->assertSee('Indulási ellenőrzés')->assertDontSee('Fuvar feladása')->assertDontSee('Járműtörzs');
        $this->getJson($readiness)->assertOk()->assertJsonPath('has_dispatch_permission', false)->assertJsonPath('can_dispatch', false);

        $this->actingAs($admin)->get($show)->assertOk()->assertSee('href="'.route('vehicle-pages.index').'"', false)
            ->assertSee('Járműtörzs kezelése')->assertDontSee('plan-vehicle-registry-title');
        $cargo = $this->getJson(route('shipment-plans.cargo.show', $plan))->assertOk()->assertJsonPath('confirmation.confirmed_by_name', $admin->name)->json();
        $this->getJson($readiness)->assertOk()->assertJsonPath('basis_revision', $cargo['basis_revision'])
            ->assertJsonPath('vehicle.version', $cargo['vehicle']['version']);
        $dispatch = $this->postJson(route('shipment-plans.dispatch.store', $plan), [
            'operation_key' => (string) Str::uuid(), 'version' => $plan->version, 'confirmed' => true,
        ])->assertOk()->assertJsonPath('dispatch.actor_name', $admin->name)->assertJsonCount(3, 'transit_items');
        $line = $plan->intentAssignments()->orderBy('id')->firstOrFail()->intent;
        $oldProduct = $line->product->product_name;
        $oldSku = $line->product->sku;
        $oldName = $admin->name;
        $admin->update(['name' => 'Átnevezett feladó']);
        $line->product()->update(['product_name' => 'Átnevezett termék', 'sku' => 'UJ-BEMUTATO-SKU']);

        $this->getJson(route('shipment-plans.dispatch.show', $plan))->assertOk()->assertJsonPath('dispatch.id', $dispatch->json('dispatch.id'))
            ->assertJsonPath('dispatch.actor_name', $oldName)->assertJsonPath('dispatch.snapshot.intents.0.product_name', $oldProduct)
            ->assertJsonPath('dispatch.snapshot.intents.0.sku', $oldSku);
        $this->get($show)->assertOk()->assertSee('Szállítás alatt álló tételek')->assertDontSee('Fuvar feladása');
        $this->getJson($readiness)->assertOk()->assertJsonPath('dispatch_status', 'dispatched')->assertJsonPath('can_dispatch', false);
    }
}
