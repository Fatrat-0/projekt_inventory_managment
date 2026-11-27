<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, InventoryTransfer, Partner, Product, Shipment, Stock, User, Warehouse};
use App\Services\InventoryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Notification, Password};
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivacyAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_role_matrix_keeps_common_views_and_scopes_warehouse_work(): void
    {
        $source = Warehouse::factory()->create();
        $target = Warehouse::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create(['role' => 'worker']);
        $manager = User::factory()->create(['role' => 'manager']);
        $requester = $this->member($source, 'requester');
        $dispatcher = $this->member($source, 'dispatch_operator');
        $receiver = $this->member($source, 'receiving_operator');
        $supervisor = $this->member($source, 'supervisor');
        $multi = $this->member($source, 'dispatch_operator');
        $multi->addWarehouseMembership($target->getKey(), ['receiving_operator']);
        $partner = Partner::create(['name' => 'Audit partner', 'type' => 'supplier',
            'contact_name' => 'Fiktív kapcsolattartó', 'email' => 'contact@example.test']);

        $this->get('/stock')->assertRedirectToRoute('login');
        $this->getJson(route('app-search.index'))->assertUnauthorized();
        $this->getJson(route('shipment-plans.index'))->assertUnauthorized();

        $inactive = User::factory()->create(['role' => 'worker', 'deactivated_at' => now()]);
        $pending = User::factory()->create(['role' => 'admin', 'password_change_required' => true]);
        $this->actingAs($inactive)->getJson('/search')->assertUnauthorized();
        $this->actingAs($pending)->get('/dashboard')->assertRedirectToRoute('password.setup');
        $this->getJson(route('export.inventory'))->assertForbidden();

        // Flags are based on the scope of the existing route and policy, not on the account label.
        foreach ([
            [$worker, false, false, false, false, false], [$manager, true, false, false, false, false],
            [$requester, false, false, false, false, false], [$dispatcher, false, true, false, true, false],
            [$receiver, false, false, true, true, false], [$supervisor, false, true, true, true, false],
            [$multi, false, true, true, true, false], [$admin, true, true, true, true, true],
        ] as [$actor, $statistics, $sourceWork, $targetWork, $measurements, $manageUsers]) {
            $this->actingAs($actor)->get('/dashboard')->assertOk();
            $this->get('/stock')->assertOk();
            $this->get('/products')->assertOk();
            $this->get(route('partners.index', ['search' => $partner->name]))->assertOk()
                ->assertViewHas('suggestions', fn ($suggestions) => $suggestions->contains(fn ($item) => str_contains($item['detail'], $partner->contact_name)));
            $this->get(route('partners.edit', $partner))->assertOk()->assertSee($partner->email);
            $this->get('/movements')->assertOk();
            $this->get(route('warehouses.floor-plan.show', $source))->assertOk();
            $this->get(route('warehouses.locations.index', $source))->assertOk();
            $this->get(route('warehouses.statistics'))->assertStatus($statistics ? 200 : 403);
            $this->getJson(route('transfer-intents.index'))->assertOk();
            $this->getJson(route('shipment-plans.index'))->assertStatus($sourceWork ? 200 : 403);
            $this->getJson(route('shipment-plans.receiving.index'))->assertStatus($targetWork ? 200 : 403);
            $this->get(route('warehouse-picking-pages.index'))->assertStatus($sourceWork ? 200 : 403);
            $this->getJson(route('warehouse-load-verifications.index', $source))->assertStatus($measurements ? 200 : 403);
            $this->get(route('users.show', $admin))->assertStatus($manageUsers ? 200 : 403);
            $search = $this->getJson(route('app-search.index', ['q' => 'Felhasználók']))->assertOk()->assertDontSee($admin->email);
            $this->assertSame($manageUsers ? ['Felhasználók'] : [], array_column($search->json('results'), 'label'));
        }
        $this->actingAs($multi)->getJson(route('warehouse-load-verifications.index', $target))->assertOk();
        $this->getJson(route('warehouse-load-verifications.index', Warehouse::factory()->create()))->assertForbidden();
    }

    public function test_membership_free_stock_commands_and_master_data_access_follow_existing_policy(): void
    {
        $warehouse = Warehouse::factory()->create();
        $worker = User::factory()->create(['role' => 'worker']);
        $manager = User::factory()->create(['role' => 'manager']);
        $issuer = $this->member($warehouse, 'dispatch_operator');
        Category::create(['category_name' => 'Teszt kategória']);
        $product = Product::factory()->create();
        Stock::create(['warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(), 'quantity' => 10]);
        $data = ['warehouse_id' => $warehouse->getKey(), 'product_id' => $product->getKey(), 'quantity' => 2];
        $issue = app(InventoryService::class)->recordMovement($data + ['type' => 'out', 'user_id' => $issuer->id]);

        $this->actingAs($worker)->postJson(route('movements.store'), $data + [
            'type' => 'in', 'confirmed' => 1, 'request_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('putaway_url', null);
        $this->postJson(route('movements.store'), $data + ['type' => 'out', 'request_key' => (string) Str::uuid()])->assertForbidden();
        $this->post(route('movements.return.store', $issue), ['quantity' => 2, 'return_reason' => 'Teszt visszáru',
            'request_key' => (string) Str::uuid()])->assertRedirect();
        $returned = InventoryMovement::where('return_of_id', $issue->id)->sole();
        $this->post(route('movements.quality.store', $returned), ['action' => 'release', 'quantity' => 1,
            'reason' => 'Teszt minősítés', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseHas('return_quality_actions', ['return_movement_id' => $returned->id, 'user_id' => $worker->id, 'action' => 'release']);
        $this->assertSame(12, Stock::sole()->quantity);

        $change = ['warehouse_name' => 'Audit név', 'location' => 'Audit cím', 'lock_version' => $warehouse->lock_version ?? 1];
        $this->putJson(route('warehouses.update', $warehouse), $change)->assertForbidden();
        $this->actingAs($manager)->put(route('warehouses.update', $warehouse), $change)->assertRedirect();
        $this->assertSame('Audit név', $warehouse->fresh()->warehouse_name);
    }

    public function test_pdf_permissions_apply_to_both_modes_and_every_response_disables_storage(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create(['role' => 'worker']);
        $manager = User::factory()->create(['role' => 'manager']);
        [$source, $target] = Warehouse::factory()->count(2)->create()->all();
        $dispatcher = $this->member($source, 'dispatch_operator');
        Category::create(['category_name' => 'PDF teszt kategória']);
        $product = Product::factory()->create();
        Stock::create(['warehouse_id' => $source->getKey(), 'product_id' => $product->getKey(), 'quantity' => 8]);
        $command = ['from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $target->getKey(),
            'product_id' => $product->getKey(), 'quantity' => 1, 'request_key' => (string) Str::uuid()];
        $this->actingAs($admin)->post(route('transfers.store'), $command)->assertRedirectToRoute('transfers.index');
        $transfer = InventoryTransfer::sole();
        $this->post(route('shipments.store'), ['origin_warehouse_id' => $source->getKey(), 'reference' => 'Audit fuvar',
            'stops' => [['warehouse_id' => $target->getKey(), 'items' => [['product_id' => $product->getKey(), 'quantity' => 1]]]]])
            ->assertRedirect();
        $shipment = Shipment::sole();
        $receiver = $this->member($target, 'receiving_operator');
        foreach ([false, true] as $preview) {
            foreach ([
                [$worker, 'export.inventory', []], [$worker, 'export.movements', []],
                [$manager, 'warehouses.statistics-pdf', []], [$manager, 'warehouses.inventory-pdf', ['warehouse' => $target]],
                [$receiver, 'export.transfer', ['transfer' => $transfer]], [$dispatcher, 'shipments.pdf', ['shipment' => $shipment]],
            ] as [$actor, $name, $params]) {
                $response = $this->actingAs($actor)->get(route($name, $params + ['preview' => (int) $preview]))
                    ->assertOk()->assertHeader('Content-Type', 'application/pdf');
                $this->assertTrue($response->headers->hasCacheControlDirective('private'));
                $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            }
            $this->actingAs($worker)->get(route('warehouses.inventory-pdf', ['warehouse' => $target, 'preview' => (int) $preview]))->assertForbidden();
            $this->get(route('export.transfer', ['transfer' => $transfer, 'preview' => (int) $preview]))->assertForbidden();
            $this->actingAs($receiver)->get(route('shipments.pdf', ['shipment' => $shipment, 'preview' => (int) $preview]))->assertForbidden();
        }
        $this->actingAs($worker)->getJson(route('export.movements', ['sort_by' => 'password']))->assertUnprocessable();
        $this->actingAs($manager)->get(route('warehouses.inventory-pdf', ['warehouse' => 999999]))->assertNotFound();
    }

    public function test_hidden_pdf_columns_do_not_reappear_in_the_filter_summary(): void
    {
        $actor = User::factory()->create(['role' => 'worker', 'name' => 'Fiktív kezelő']);
        $partner = Partner::create(['name' => 'Fiktív egyéni partner', 'type' => 'supplier']);
        $warehouse = Warehouse::factory()->create(['warehouse_name' => 'Fiktív zárt telephely']);
        Pdf::shouldReceive('setPaper')->with('a4', 'portrait')->andReturnSelf();
        Pdf::shouldReceive('stream')->andReturn(response('test document', 200, ['Content-Type' => 'application/pdf']));
        Pdf::shouldReceive('loadView')->once()->withArgs(function (string $name, array $data): bool {
            $html = view($name, $data)->render();
            $this->assertStringContainsString('Egyes szűrőértékek elrejtve', $html);
            foreach (['Fiktív kezelő', 'Fiktív egyéni partner', 'Fiktív zárt telephely', 'Fiktív keresőkifejezés'] as $label) {
                $this->assertStringNotContainsString($label, $html);
            }
            return true;
        })->andReturnSelf();

        $this->actingAs($actor)->get(route('export.movements', [
            'user_id' => $actor->id, 'partner_id' => $partner->id, 'warehouse_id' => $warehouse->getKey(), 'preview' => 1,
            'search' => 'Fiktív keresőkifejezés', 'show_user' => 0, 'show_partner' => 0, 'show_warehouse' => 0, 'show_reference' => 0,
        ]))->assertOk();
    }

    public function test_email_changes_require_the_acting_users_password_and_keep_it_out_of_responses_and_flash(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create(['role' => 'worker']);
        $resetToken = Password::createToken($admin);
        $change = ['name' => $admin->name, 'email' => 'updated@example.test'];
        $profile = $this->actingAs($admin)->get(route('profile.edit'))->assertOk();
        $this->assertTrue($profile->headers->hasCacheControlDirective('private'));
        $this->assertTrue($profile->headers->hasCacheControlDirective('no-store'));
        $this->patchJson(route('profile.update'), $change)->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->patch(route('profile.update'), $change + ['current_password' => 'wrong-private-test-value'])
            ->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input.current_password');
        $this->patchJson(route('users.update', $admin), $change + ['role' => 'admin', 'lock_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password')->assertDontSee($resetToken);
        $this->assertTrue(Password::tokenExists($admin, $resetToken));
        $this->assertSame($admin->email, $admin->fresh()->email);
        $this->patch(route('users.update', $worker), ['name' => $worker->name, 'email' => 'worker-updated@example.test',
            'role' => 'worker', 'lock_version' => 1, 'current_password' => 'password'])->assertRedirectToRoute('users.show', $worker);
        $this->assertSame('worker-updated@example.test', $worker->fresh()->email);
        $event = $worker->accessEvents()->sole();
        $this->assertSame(['email'], $event->changes['fields']);
    }

    public function test_log_mailer_is_not_used_for_optional_email_verification_or_reset_links(): void
    {
        $account = User::factory()->unverified()->create(['role' => 'worker']);
        config(['mail.default' => 'log']);
        Notification::fake();
        $this->actingAs($account)->post(route('verification.send'))->assertSessionHasErrors('email');
        $this->post(route('logout'));
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->post(route('password.email'), ['email' => $account->email])->assertSessionHasErrors('email');
        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $account->email]);
    }

    private function member(Warehouse $warehouse, string $role): User
    {
        $actor = User::factory()->create(['role' => 'worker']);
        $actor->addWarehouseMembership($warehouse->getKey(), [$role]);

        return $actor;
    }
}
