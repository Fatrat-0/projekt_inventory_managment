<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpiredLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise real CSRF validation, normally disabled in PHPUnit.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests() { return false; }
        });
    }

    public function test_login_form_cannot_be_stored_in_http_cache(): void
    {
        $response = $this->get('/login')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertSee('name="_token"', false);
    }

    public function test_expired_login_recovers_with_email_but_without_authentication_or_password_replay(): void
    {
        $user = User::factory()->create();
        $response = $this->withSession(['_token' => 'current-token', 'url.intended' => '/products'])
            ->post('/login', ['_token' => 'expired-token', 'email' => $user->email, 'password' => 'password', 'remember' => 'on']);
        $response->assertStatus(303)->assertRedirect(route('login'))
            ->assertSessionHas('_old_input', ['email' => $user->email])
            ->assertSessionHas('status')->assertSessionHas('url.intended', '/products');
        $this->assertGuest();
        $this->get('/login')->assertOk()->assertSee('A bejelentkezési űrlap lejárt.')->assertSee($user->email)->assertDontSee('value="password"', false);
        $this->withSession(['_token' => 'current-token'])->post('/login', [
            '_token' => 'current-token', 'email' => $user->email, 'password' => 'password',
        ])->assertRedirect('/products');
        $this->assertAuthenticatedAs($user);
    }

    public function test_missing_csrf_token_does_not_authenticate_even_with_valid_credentials(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(303)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_json_login_keeps_419_without_flashing_credentials(): void
    {
        $this->withSession(['_token' => 'current-token'])->postJson('/login', ['_token' => 'old', 'email' => 'test@example.test', 'password' => 'private-password'])
            ->assertStatus(419)->assertSessionMissing('_old_input')->assertSessionMissing('status');
        $this->assertGuest();
    }

    public function test_other_expired_forms_keep_419_instead_of_being_replayed_or_redirected(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->withSession(['_token' => 'current-token'])
            ->post('/warehouses', ['_token' => 'old', 'warehouse_name' => 'Should not exist', 'location' => 'Test'])->assertStatus(419);
        $this->assertDatabaseCount('warehouses', 0);
    }

    public function test_malformed_email_is_not_flashed_as_an_array(): void
    {
        $this->post('/login', ['email' => ['invalid'], 'password' => 'private-password'])->assertStatus(303)
            ->assertSessionHas('_old_input', ['email' => '']);
        $this->get('/login')->assertOk();
    }
}
