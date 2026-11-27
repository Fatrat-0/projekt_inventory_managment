<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Shipment, Stock, TransferIntent, User, Warehouse};
use App\Policies\{ShipmentPolicy, TransferIntentPolicy, WarehouseLoadVerificationPolicy};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseMembershipRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_operating_roles_work_together_only_in_the_warehouse_where_they_are_assigned(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'worker']);
        [$source, $target] = Warehouse::factory()->count(2)->create()->all();
        $operator->addWarehouseMembership($source->getKey(), ['dispatch_operator', 'receiving_operator']);
        $operator->addWarehouseMembership($target->getKey(), ['receiving_operator']);
        Category::create(['category_name' => 'Szerepkörpróba']);
        $product = Product::factory()->create();
        Stock::create(['product_id' => $product->getKey(), 'warehouse_id' => $source->getKey(), 'quantity' => 10]);
        $transfer = ['from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $target->getKey(),
            'product_id' => $product->getKey(), 'quantity' => 1, 'request_key' => (string) Str::uuid()];
        $this->actingAs($admin)->post(route('transfers.store'), $transfer)->assertRedirectToRoute('transfers.index');
        $this->post(route('shipments.store'), ['origin_warehouse_id' => $source->getKey(), 'reference' => 'Kombinált szerepkör próba',
            'stops' => [['warehouse_id' => $target->getKey(), 'items' => [['product_id' => $product->getKey(), 'quantity' => 1]]]]])->assertRedirect();
        $shipment = Shipment::sole();
        $filtered = $this->get(route('users.index', ['warehouse' => $source->getKey()]))->assertOk()->viewData('users');
        $this->assertSame([$operator->id], $filtered->modelKeys());

        $policy = app(ShipmentPolicy::class);
        $this->assertTrue($policy->receiveInWarehouse($operator, $source->getKey())->allowed());
        $this->assertTrue($policy->putawayInWarehouse($operator, $source->getKey())->allowed());
        $this->assertTrue($policy->canViewOrigin($operator, $source->getKey()));
        $this->assertFalse($policy->canViewOrigin($operator, $target->getKey()));
        $this->assertFalse($policy->dispatchPlan($operator, $shipment)->allowed());
        $this->assertFalse($policy->createPlan($operator, $source->getKey())->allowed());
        $this->assertFalse(app(WarehouseLoadVerificationPolicy::class)->canVerify($operator, $source->getKey(), locked: true));
        $this->assertEqualsCanonicalizing([(string) $source->getKey(), (string) $target->getKey()], app(TransferIntentPolicy::class)->requestWarehouseIds($operator));

        $this->actingAs($operator)->getJson(route('shipment-plans.index'))->assertOk();
        $this->getJson(route('shipment-plans.receiving.index'))->assertOk();
        $this->getJson(route('warehouse-load-verifications.index', $source))->assertOk();
        $this->get(route('shipments.pdf', [$shipment, 'preview' => 1]))->assertOk();
        $this->getJson(route('app-search.index', ['q' => 'Raktári feladatok']))->assertOk()
            ->assertJsonCount(1, 'results')->assertJsonPath('results.0.label', 'Raktári feladatok');
        $this->postJson(route('transfer-intents.store'), array_diff_key($transfer, ['request_key' => true]) + [
            'reason' => 'Teszt áthelyezési igény', 'operation_key' => (string) Str::uuid(),
        ])->assertCreated();
        $intent = TransferIntent::sole();
        $this->patchJson(route('transfer-intents.approve', $intent), ['version' => $intent->version, 'operation_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame('pending_approval', $intent->fresh()->status);

        $data = ['warehouse_id' => $source->getKey(), 'product_id' => $product->getKey(), 'quantity' => 1,
            'type' => 'out', 'request_key' => (string) Str::uuid()];
        $this->postJson(route('movements.store'), $data)->assertRedirectToRoute('movements.index');
        $this->assertDatabaseHas('stocks', ['warehouse_id' => $source->getKey(), 'product_id' => $product->getKey(), 'quantity' => 8]);
        $this->postJson(route('movements.store'), array_replace($data, ['warehouse_id' => $target->getKey(), 'request_key' => (string) Str::uuid()]))->assertForbidden();
    }

    public function test_role_set_order_is_a_no_op_and_revocation_keeps_other_roles_and_historical_audit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'worker', 'remember_token' => 'fixture-token'])->fresh();
        $source = Warehouse::factory()->create();
        $membership = $operator->addWarehouseMembership($source->getKey(), ['dispatch_operator', 'receiving_operator']);
        $legacy = $operator->accessEvents()->create(['actor_id' => $admin->id, 'action' => 'updated', 'occurred_at' => now(), 'changes' => [
            'memberships' => [['warehouse_id' => $source->getKey(), 'before' => null, 'after' => 'dispatch_operator']],
        ]]);
        $token = Password::createToken($operator);
        $data = $operator->only(['name', 'email', 'role', 'lock_version']);
        $this->actingAs($admin)->patch(route('users.update', $operator), $data + [
            'memberships' => [['warehouse_id' => $source->getKey(), 'roles' => ['receiving_operator', 'dispatch_operator']]],
        ])->assertRedirectToRoute('users.show', $operator)->assertSessionHas('success', 'A fiók adatai változatlanok.');
        $this->assertSame(0, $operator->fresh()->access_version);
        $this->assertTrue(Password::tokenExists($operator, $token));
        $this->assertSame(1, $operator->accessEvents()->count());

        $this->patch(route('users.update', $operator), $data + [
            'memberships' => [['warehouse_id' => $source->getKey(), 'roles' => ['receiving_operator']]],
        ])->assertRedirectToRoute('users.show', $operator);
        $operator->refresh();
        $this->assertSame(['receiving_operator'], $membership->roleCodes());
        $this->assertFalse($operator->hasWarehouseCapability('pick', $source->getKey()));
        $this->assertTrue($operator->hasWarehouseCapability('receive', $source->getKey()));
        $this->assertSame(1, $operator->access_version);
        $this->assertNotSame('fixture-token', $operator->remember_token);
        $this->assertFalse(Password::tokenExists($operator, $token));
        $this->assertSame([['warehouse_id' => $source->getKey(), 'before' => ['dispatch_operator', 'receiving_operator'], 'after' => ['receiving_operator']]],
            $operator->accessEvents()->latest('id')->firstOrFail()->changes['memberships']);
        $this->assertSame('dispatch_operator', $legacy->fresh()->changes['memberships'][0]['after']);
        $this->get(route('users.show', $operator))->assertOk()->assertSee('Tagság nélkül → Kitároló')->assertSee('Kitároló, Átvevő → Átvevő');

        $this->patch(route('users.update', $operator), $operator->only(['name', 'email', 'role', 'lock_version']) + ['memberships' => []])
            ->assertRedirectToRoute('users.show', $operator);
        $this->assertModelMissing($membership);
        $this->assertDatabaseCount('warehouse_membership_roles', 0);
    }

    public function test_role_set_validation_rejects_invalid_or_incomplete_changes_without_changing_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'worker'])->fresh();
        $warehouse = Warehouse::factory()->create();
        $membership = $operator->addWarehouseMembership($warehouse->getKey(), ['dispatch_operator']);
        $data = $operator->only(['name', 'email', 'role', 'lock_version']);
        $url = route('users.update', $operator);
        $this->actingAs($operator)->patchJson($url, $data + [
            'memberships' => [['warehouse_id' => $warehouse->getKey(), 'roles' => ['supervisor']]],
        ])->assertForbidden();
        $this->actingAs($admin);
        foreach ([[[], 'roles'], [['root'], 'roles.0'], [['requester', 'requester'], 'roles'], [['nested' => 'requester'], 'roles']] as [$set, $error]) {
            $this->patchJson($url, $data + ['memberships' => [['warehouse_id' => $warehouse->getKey(), 'roles' => $set]]])
                ->assertUnprocessable()->assertJsonValidationErrors('memberships.0.'.$error);
        }
        $this->patchJson($url, $data + ['memberships_submitted' => 1, 'memberships' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('memberships_complete');
        $this->assertSame(['dispatch_operator'], $membership->roleCodes());
        $this->assertSame(0, $operator->accessEvents()->count());
    }
}
