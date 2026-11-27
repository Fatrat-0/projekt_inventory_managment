<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryMovement, InventoryTransfer, Product, Stock, User, Warehouse};
use App\Services\UserAccess;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Event, Hash, Notification, Password};
use Illuminate\Support\Str;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_issue_a_manager_with_local_roles_and_a_single_use_link(): void
    {
        Notification::fake();
        config(['app.url' => 'https://warehouse.example.test']);
        $admin = User::factory()->create(['role' => 'admin']);
        [$source, $target] = Warehouse::factory()->count(2)->create()->all();
        $data = [
            'name' => 'Próba munkatárs', 'email' => 'NEW@example.test', 'role' => 'manager', 'password_delivery' => 'link',
            'memberships' => [$this->membership($source, 'supervisor'), $this->membership($target, 'receiving_operator')],
        ];
        $this->actingAs($admin)->post('/users', $data)->assertRedirectToRoute('users.index');
        $account = User::where('email', 'new@example.test')->sole();
        $this->assertSame('manager', $account->role);
        $this->assertTrue($account->password_change_required);
        $this->assertSame([['supervisor'], ['receiving_operator']], $account->warehouseMemberships()->orderBy('warehouse_id')->get()->map->roleCodes()->all());

        $token = '';
        Notification::assertSentTo($account, ResetPassword::class, function ($notification) use (&$token, $account) {
            $token = $notification->token;
            $this->assertStringStartsWith('https://warehouse.example.test/reset-password/', $notification->toMail($account)->actionUrl);

            return true;
        });
        $this->get(route('users.show', $account))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Fiók létrehozása')->assertSee($source->warehouse_name)
            ->assertDontSee($account->password)->assertDontSee($token);
        $this->assertSame(['created', 'password_link'], $account->accessEvents()->orderBy('id')->pluck('action')->all());

        $this->post('/logout');
        Auth::forgetGuards();
        $password = 'new-fictional-password';
        $reset = ['email' => $account->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];
        $this->post('/reset-password', $reset)->assertRedirectToRoute('login');
        $this->assertFalse($account->fresh()->password_change_required);
        $this->post('/reset-password', $reset)->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $account->email, 'password' => $password])->assertRedirectToRoute('dashboard');
    }

    public function test_membership_and_identity_changes_revoke_sessions_and_reset_tokens_for_both_addresses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $actor = User::factory()->create(['email' => 'former@example.test']);
        [$removed, $changed, $added] = Warehouse::factory()->count(3)->create()->all();
        $actor->addWarehouseMembership($removed->getKey(), ['receiving_operator']);
        $actor->addWarehouseMembership($changed->getKey(), ['requester']);
        $oldToken = Password::createToken($actor);
        $identity = ['name' => 'Frissített név', 'email' => 'updated@example.test'];
        $newAddress = new User(['email' => $identity['email']]);
        $otherToken = Password::createToken($newAddress);
        $data = $identity + ['current_password' => 'password']
            + $this->edit($actor, 'manager', [$this->membership($changed, 'dispatch_operator'), $this->membership($added, 'receiving_operator')]);
        $this->actingAs($admin)->patch(route('users.update', $actor), $data)->assertRedirectToRoute('users.show', $actor);
        $fresh = $actor->fresh();
        $this->assertSame($identity, $fresh->only(['name', 'email']));
        $this->assertSame('manager', $fresh->role);
        $this->assertSame([$changed->getKey() => ['dispatch_operator'], $added->getKey() => ['receiving_operator']],
            $fresh->warehouseMemberships()->orderBy('warehouse_id')->get()->keyBy('warehouse_id')->map->roleCodes()->all());
        $this->assertNull($fresh->email_verified_at);
        $this->assertSame(1, $fresh->access_version);
        $this->assertFalse(Password::tokenExists($actor, $oldToken));
        $this->assertFalse(Password::tokenExists($newAddress, $otherToken));
        $audit = $fresh->accessEvents()->sole()->changes;
        $this->assertSame(['before' => 'worker', 'after' => 'manager'], $audit['role']);
        $this->assertCount(3, $audit['memberships']);
        $this->assertStringNotContainsString('former@example.test', json_encode($audit));
        $this->assertStringNotContainsString($oldToken, json_encode($audit));
        $this->actingAs($fresh)->getJson('/profile')->assertUnauthorized(); // The retained browser session has version 0.
    }

    public function test_stale_edits_role_manipulation_duplicate_memberships_and_non_admin_requests_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create();
        $manager = User::factory()->create(['role' => 'manager']);
        $warehouse = Warehouse::factory()->create();
        $data = $this->edit($worker, 'worker', [$this->membership($warehouse, 'requester')]);
        $url = route('users.update', $worker);
        foreach ([$worker, $manager] as $actor) {
            $this->actingAs($actor)->get(route('users.show', $worker))->assertForbidden();
            $this->patchJson($url, $data)->assertForbidden();
        }
        $this->actingAs($admin);
        $this->patchJson($url, array_replace($data, ['email' => $manager->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson($url, array_replace($data, ['role' => 'root']))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson($url, array_replace_recursive($data, ['memberships' => [['roles' => ['admin']]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('memberships.0.roles.0');
        $this->patchJson($url, array_replace_recursive($data, ['memberships' => [['warehouse_id' => 999999]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('memberships.0.warehouse_id');
        $this->patchJson($url, array_replace($data, ['memberships' => [$data['memberships'][0], $data['memberships'][0]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('memberships.1.warehouse_id');
        $this->patchJson($url, array_replace($data, ['role' => 'admin']))->assertUnprocessable()->assertJsonValidationErrors('memberships');

        $this->patch($url, $data)->assertRedirect();
        $this->patchJson($url, array_replace($data, ['name' => 'Elavult módosítás']))->assertConflict();
        $this->patch($url, array_replace($data, ['name' => 'Elavult módosítás', 'memberships_submitted' => 1, 'memberships_complete' => 1]))
            ->assertConflict()->assertSee('Az aktuális adatok átnézése szükséges')->assertSee('Elavult módosítás');
        $this->patchJson($url, array_replace($data, ['review_version' => 2, 'confirm_edit_conflict' => 0]))->assertUnprocessable();
        $this->assertSame($worker->name, $worker->fresh()->name);
        $this->assertDatabaseCount('user_access_events', 1);
        $this->patchJson(route('users.update', $admin), $this->edit($admin, 'worker'))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_audit_failure_rolls_back_identity_role_and_membership_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $worker->addWarehouseMembership($warehouse->getKey(), ['requester']);
        DB::unprepared("CREATE TRIGGER reject_access_event BEFORE INSERT ON user_access_events BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
        try {
            app(UserAccess::class)->update($admin, $worker, ['name' => 'Megváltozott'] + $this->edit($worker, 'manager'));
            $this->fail('Audit insert should have failed.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Audit unavailable', $error->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_access_event');
        }
        $this->assertSame($worker->name, $worker->fresh()->name);
        $this->assertSame('worker', $worker->fresh()->role);
        $this->assertSame(['requester'], $worker->warehouseMemberships()->sole()->roleCodes());
        $this->assertSame(1, $worker->fresh()->lock_version);
    }

    public function test_list_filters_and_sorts_the_full_result_and_escapes_literal_wildcards(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $warehouse = Warehouse::factory()->create();
        $expected = [];
        foreach (range(12, 1) as $i) {
            $user = User::factory()->create(['name' => sprintf('Keresett %02d', $i), 'email' => "literal%{$i}@example.test"]);
            $user->addWarehouseMembership($warehouse->getKey(), ['requester']);
            $expected[$i] = $user->id;
        }
        $inactive = User::factory()->create(['name' => 'Keresett inaktív', 'email' => 'inactive@example.test']);
        $inactive->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($admin);
        $params = ['search' => 'Keresett literal%', 'role' => 'worker', 'state' => 'active', 'warehouse' => $warehouse->getKey(), 'sort_by' => 'name', 'sort_dir' => 'asc'];
        $response = $this->get(route('users.index', $params + ['page' => 2]))->assertOk();
        $this->assertSame([$expected[11], $expected[12]], $response->viewData('users')->modelKeys());
        $this->get(route('users.index', ['warehouse' => 'none', 'state' => 'inactive']))->assertOk()->assertSee($inactive->name);
        $this->getJson(route('users.index', ['sort_by' => 'password']))->assertUnprocessable();
    }

    public function test_local_access_is_rechecked_before_issue_and_after_membership_revocation_including_replay(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create(['role' => 'worker']);
        [$source, $other] = Warehouse::factory()->count(2)->create()->all();
        Category::create(['category_name' => 'Felhasználókezelési próba']);
        $product = Product::factory()->create();
        $stock = Stock::create(['product_id' => $product->getKey(), 'warehouse_id' => $source->getKey(), 'quantity' => 10]);
        $worker->addWarehouseMembership($source->getKey(), ['dispatch_operator']);
        $data = ['warehouse_id' => $source->getKey(), 'product_id' => $product->getKey(), 'type' => 'out', 'quantity' => 2, 'request_key' => (string) Str::uuid()];
        $this->actingAs($worker)->getJson(route('movements.out.sources', $data))->assertOk();
        $this->getJson(route('movements.out.sources', array_replace($data, ['warehouse_id' => $other->getKey()])))->assertForbidden();
        $this->post(route('movements.store'), $data)->assertRedirect();
        app(UserAccess::class)->update($admin, $worker, $this->edit($worker, 'worker'));
        $this->actingAs($worker->fresh())->getJson(route('movements.out.sources', $data))->assertUnauthorized();
        $this->withSession(['account_access_version' => 1])->actingAs($worker->fresh())->postJson(route('movements.store'), $data)->assertForbidden();
        $this->getJson(route('movements.out.sources', $data))->assertForbidden();
        $this->assertSame(8, $stock->fresh()->quantity);
        $this->assertSame($worker->id, (int) InventoryMovement::sole()->user_id);
    }

    public function test_revoked_membership_blocks_transfer_pdf_and_direct_receipt_with_fresh_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create(['role' => 'worker']);
        [$source, $target] = Warehouse::factory()->count(2)->create()->all();
        $worker->addWarehouseMembership($target->getKey(), ['receiving_operator']);
        Category::create(['category_name' => 'PDF jogosultsági próba']);
        $product = Product::factory()->create();
        Stock::create(['product_id' => $product->getKey(), 'warehouse_id' => $source->getKey(), 'quantity' => 10]);
        $this->actingAs($admin)->post(route('transfers.store'), [
            'from_warehouse_id' => $source->getKey(), 'to_warehouse_id' => $target->getKey(), 'product_id' => $product->getKey(),
            'quantity' => 2, 'request_key' => (string) Str::uuid(),
        ])->assertRedirectToRoute('transfers.index');
        $transfer = InventoryTransfer::sole();
        $pdf = route('export.transfer', $transfer);
        $receipt = route('transfers.complete', $transfer);
        $this->actingAs($worker)->get($pdf)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        app(UserAccess::class)->update($admin, $worker, $this->edit($worker, 'worker'));
        $this->actingAs($worker->fresh())->getJson($pdf)->assertUnauthorized();
        $this->withSession(['account_access_version' => 1])->actingAs($worker->fresh())->get($pdf)->assertForbidden();
        $this->patchJson($receipt)->assertForbidden();
        $this->assertSame('pending', $transfer->fresh()->status);
        $this->assertDatabaseMissing('stocks', ['warehouse_id' => $target->getKey(), 'product_id' => $product->getKey()]);
    }

    public function test_an_admin_who_steps_down_must_sign_in_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patch(route('users.update', $admin), $this->edit($admin, 'worker'))
            ->assertRedirectToRoute('login')->assertSessionHas('status');
        $this->assertGuest();
        $this->assertSame('worker', $admin->fresh()->role);
        $this->assertSame([$other->id], UserAccess::activeAdmins()->pluck('id')->all());
    }

    public function test_admin_awaiting_a_personal_password_does_not_replace_the_last_usable_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pending = User::factory()->create(['role' => 'admin', 'password_change_required' => true]);
        $this->actingAs($admin)->patchJson(route('users.update', $admin), $this->edit($admin, 'worker'))
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertDatabaseCount('user_access_events', 0);
        $this->patch(route('users.access', $pending), ['active' => false, 'lock_version' => 1])->assertRedirect();
        $this->assertFalse($pending->fresh()->isActive());
    }

    public function test_first_password_gate_covers_exports_search_and_direct_business_actions(): void
    {
        $pending = User::factory()->create(['role' => 'admin', 'password_change_required' => true]);
        $this->actingAs($pending)->get(route('export.inventory'))->assertRedirectToRoute('password.setup');
        $this->getJson(route('app-search.index', ['q' => 'keresés']))->assertForbidden();
        $this->postJson(route('movements.store'), [])->assertForbidden();
        $this->get(route('users.index'))->assertRedirectToRoute('password.setup');
        $this->get(route('password.setup'))->assertOk();
        $this->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'my-new-password', 'password_confirmation' => 'my-new-password',
        ])->assertRedirectToRoute('dashboard');
        $this->assertFalse($pending->fresh()->password_change_required);
        $this->assertSame(2, $pending->fresh()->lock_version);
        $this->get(route('users.index'))->assertOk();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_unchanged_save_preserves_sessions_tokens_and_audit_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create(['role' => 'worker', 'email' => 'Legacy@example.test']);
        [$first, $second] = Warehouse::factory()->count(2)->create()->all();
        $memberships = [$this->membership($first, 'requester'), $this->membership($second, 'receiving_operator')];
        foreach ($memberships as $membership) {
            $worker->addWarehouseMembership($membership['warehouse_id'], $membership['roles']);
        }
        $token = Password::createToken($worker);
        $this->actingAs($admin)->patch(route('users.update', $worker), $this->edit($worker, 'worker', array_reverse($memberships)))
            ->assertRedirectToRoute('users.show', $worker);
        $this->assertEquals(['access_version' => 0, 'lock_version' => 1, 'remember_token' => $worker->remember_token],
            $worker->fresh()->only(['access_version', 'lock_version', 'remember_token']));
        $this->assertTrue(Password::tokenExists($worker, $token));
        $this->assertSame('Legacy@example.test', $worker->fresh()->email);
        $this->assertDatabaseCount('user_access_events', 0);
    }

    public function test_failed_invitation_can_be_retried_and_lost_create_response_does_not_duplicate_the_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Event::listen(\Illuminate\Notifications\Events\NotificationSending::class, function () {
            throw new \Symfony\Component\Mailer\Exception\TransportException('Test transport failure.');
        });
        $data = ['name' => 'Meghívott munkatárs', 'email' => 'invite@example.test', 'role' => 'worker', 'password_delivery' => 'link'];
        $response = $this->actingAs($admin)->post(route('users.store'), $data);
        $account = User::where('email', $data['email'])->sole();
        $response->assertRedirectToRoute('users.show', $account)->assertSessionHasErrors('password_delivery');
        $this->assertSame(['created'], $account->accessEvents()->pluck('action')->all());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $account->email]);
        $this->postJson(route('users.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 2);

        Notification::fake();
        $first = '';
        $link = route('users.password-link', $account);
        $this->post($link, ['lock_version' => 1])->assertRedirectToRoute('users.show', $account);
        Notification::assertSentTo($account, ResetPassword::class, function (ResetPassword $notification) use (&$first) {
            $first = $notification->token;
            return true;
        });
        $this->assertTrue(Password::tokenExists($account, $first));
        $this->postJson($link, ['lock_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('password_delivery');
        $this->travel(2)->minutes();
        $this->post($link, ['lock_version' => 1])->assertRedirectToRoute('users.show', $account);
        $this->assertFalse(Password::tokenExists($account, $first));
        $this->assertSame(['created', 'password_link', 'password_link'], $account->accessEvents()->orderBy('id')->pluck('action')->all());
    }

    public function test_promotion_and_demotion_preserve_existing_warehouse_memberships_and_stale_confirmations_fail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $account = User::factory()->create(['role' => 'manager']);
        $warehouse = Warehouse::factory()->create();
        $membership = ['warehouse_id' => $warehouse->getKey(), 'roles' => ['dispatch_operator', 'receiving_operator']];
        $account->addWarehouseMembership($membership['warehouse_id'], $membership['roles']);
        $promotion = $this->edit($account, 'admin');
        unset($promotion['memberships']);
        $this->actingAs($admin)->patch(route('users.update', $account), $promotion)
            ->assertRedirectToRoute('users.show', $account);
        $this->assertSame(['dispatch_operator', 'receiving_operator'], $account->warehouseMemberships()->sole()->roleCodes());
        $this->patchJson(route('users.access', $account), ['active' => false, 'lock_version' => 1])->assertConflict();
        $this->postJson(route('users.password-link', $account), ['lock_version' => 1])->assertConflict();
        $this->patch(route('users.update', $account), $this->edit($account, 'worker', [$membership]))
            ->assertRedirectToRoute('users.show', $account);
        $this->assertSame('worker', $account->fresh()->role);
        $this->assertSame(['dispatch_operator', 'receiving_operator'], $account->warehouseMemberships()->sole()->roleCodes());
        $this->assertSame(2, $account->fresh()->access_version);
    }

    public function test_reset_token_for_a_previous_owner_fails_after_reassigning_the_email_address(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $original = User::factory()->create(['role' => 'worker', 'email' => 'reused@example.test']);
        $oldToken = Password::createToken($original);
        $this->actingAs($admin)->patch(route('users.update', $original), ['email' => 'former-owner@example.test', 'current_password' => 'password'] + $this->edit($original, 'worker'))
            ->assertRedirectToRoute('users.show', $original);
        $this->post(route('users.store'), ['name' => 'Új tulajdonos', 'email' => 'reused@example.test', 'role' => 'worker',
            'password_delivery' => 'temporary', 'password' => 'temporary-account-password', 'password_confirmation' => 'temporary-account-password'])
            ->assertRedirectToRoute('users.index');
        $replacement = User::where('email', 'reused@example.test')->sole();
        $this->post('/logout');
        Auth::forgetGuards();
        $this->post('/reset-password', ['email' => $replacement->email, 'token' => $oldToken,
            'password' => 'must-never-be-applied', 'password_confirmation' => 'must-never-be-applied'])->assertSessionHasErrors('email');
        $this->assertTrue($replacement->fresh()->password_change_required);
        $this->assertTrue(Hash::check('temporary-account-password', $replacement->fresh()->password));
    }

    public function test_unassigned_summary_and_filter_exclude_inactive_and_admin_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $unassigned = User::factory()->create(['role' => 'manager']);
        User::factory()->create(['role' => 'worker', 'deactivated_at' => now()]);
        User::factory()->create(['role' => 'worker'])->addWarehouseMembership(Warehouse::factory()->create()->getKey(), ['requester']);
        $response = $this->actingAs($admin)->get(route('users.index', ['state' => 'unassigned']))->assertOk();
        $this->assertSame([$unassigned->id], $response->viewData('users')->modelKeys());
        $this->assertSame(4, (int) $response->viewData('totals')->total);
        $this->assertSame(1, $response->viewData('totals')->unassigned);
    }

    public function test_cli_can_recover_when_administrator_accounts_are_waiting_for_first_password_setup(): void
    {
        $pending = User::factory()->create(['role' => 'admin', 'password_change_required' => true]);
        $this->artisan('inventory:create-admin', ['name' => 'Helyreállítási próba', 'email' => 'recover@example.test'])
            ->expectsQuestion('Az adminisztrátor jelszava', 'initial-safe-password')
            ->expectsQuestion('Jelszó megerősítése', 'initial-safe-password')
            ->assertSuccessful();
        $this->assertTrue($pending->fresh()->password_change_required);
        $this->assertSame('recover@example.test', UserAccess::usableAdmins()->sole()->email);
    }

    private function membership(Warehouse $warehouse, string $role): array
    {
        return ['warehouse_id' => $warehouse->getKey(), 'roles' => [$role]];
    }

    private function edit(User $user, string $role, array $memberships = []): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'role' => $role,
            'lock_version' => $user->fresh()->lock_version, 'memberships' => $memberships];
    }
}
