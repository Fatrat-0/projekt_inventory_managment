<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, Hash, Notification, Password};
use Tests\TestCase;

class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = [
        'name' => 'Új munkatárs',
        'email' => 'new@example.test',
        'password' => 'new-account-password',
        'password_confirmation' => 'new-account-password',
    ];

    public function test_only_an_active_admin_can_issue_an_account_and_web_accounts_start_as_workers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->postJson('/users', self::ACCOUNT)->assertUnauthorized();
        foreach (['worker', 'manager'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/users')->assertForbidden();
            $this->postJson('/users', self::ACCOUNT)->assertForbidden();
            $this->patchJson(route('users.access', $admin), ['active' => false])->assertForbidden();
        }
        $this->assertTrue($admin->fresh()->isActive());
        $this->assertDatabaseMissing('users', ['email' => self::ACCOUNT['email']]);
        $this->actingAs($admin)->post('/users', self::ACCOUNT)->assertRedirectToRoute('users.index');
        $account = User::where('email', self::ACCOUNT['email'])->sole();
        $this->assertSame('worker', $account->role);
        $this->assertTrue(Hash::check(self::ACCOUNT['password'], $account->password));
        $this->assertTrue($account->isActive());
    }

    public function test_last_active_admin_is_protected_even_if_another_admin_is_inactive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patch(route('users.access', $other), ['active' => false, 'lock_version' => 1])->assertRedirect();
        $this->assertFalse($other->fresh()->isActive());
        $this->get('/users')->assertOk()->assertSee('Az utolsó használható adminisztrátori fiók.');
        $this->patchJson(route('users.access', $admin), ['active' => false, 'lock_version' => 1])
            ->assertUnprocessable()->assertJsonPath('errors.user.0', 'Az utolsó használható adminisztrátori fiók nem inaktiválható.');
        $this->deleteJson('/users/'.$admin->id)->assertMethodNotAllowed();
        $this->deleteJson('/profile', ['password' => 'password'])->assertMethodNotAllowed();
        $this->assertTrue($admin->fresh()->isActive());
    }

    public function test_inactivation_revokes_login_existing_sessions_and_reset_links(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $worker = User::factory()->create(['role' => 'worker']);
        $oldRememberToken = $worker->getRememberToken();
        $oldResetToken = Password::createToken($worker);
        $this->actingAs($admin)->patch(route('users.access', $worker), ['active' => false, 'lock_version' => 1])->assertRedirect();
        $worker->refresh();
        $this->assertNotSame($oldRememberToken, $worker->getRememberToken());
        $this->assertFalse(Password::tokenExists($worker, $oldResetToken));

        $this->actingAs($worker)->getJson('/profile')->assertUnauthorized();
        $this->assertGuest();
        $this->post('/login', ['email' => $worker->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/forgot-password', ['email' => $worker->email])->assertSessionHas('status');
        Notification::assertNotSentTo($worker, ResetPassword::class);

        $this->actingAs($admin)->patch(route('users.access', $worker), ['active' => true, 'lock_version' => 2])->assertRedirect();
        $this->assertTrue($worker->refresh()->isActive());
        $this->actingAs($worker)->getJson('/profile')->assertUnauthorized(); // Pre-revocation session.
        $this->assertGuest();
        Auth::forgetGuards();
        $this->post('/login', ['email' => $worker->email, 'password' => 'password'])->assertRedirectToRoute('dashboard');
        $this->get('/profile')->assertOk();
    }

    public function test_cli_can_bootstrap_only_the_first_active_admin(): void
    {
        $args = ['name' => 'Első admin', 'email' => 'first@example.test'];
        $this->artisan('inventory:create-admin', $args)
            ->expectsQuestion('Az adminisztrátor jelszava', 'initial-long-password')
            ->expectsQuestion('Jelszó megerősítése', 'initial-long-password')
            ->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => $args['email'], 'role' => 'admin', 'deactivated_at' => null]);
        $this->artisan('inventory:create-admin', $args)->assertFailed();
    }
}
