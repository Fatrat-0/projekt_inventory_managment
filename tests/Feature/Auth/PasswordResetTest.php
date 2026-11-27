<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->postJson('/reset-password', [
                'token' => ['invalid-shape'], 'email' => $user->email, 'password' => 'new-password', 'password_confirmation' => 'new-password',
            ])->assertUnprocessable()->assertJsonValidationErrors('token');

            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_public_request_uses_the_same_response_for_active_inactive_and_unknown_addresses(): void
    {
        Notification::fake();
        $active = User::factory()->create();
        $inactive = User::factory()->create(['deactivated_at' => now()]);
        $message = 'Ha az e-mail-címhez aktív fiók tartozik, elküldjük a jelszóbeállító linket.';
        foreach ([$active->email, $inactive->email, 'unknown@example.test', $active->email] as $email) {
            $this->post('/forgot-password', ['email' => $email])
                ->assertSessionHas('status', $message)->assertSessionHasNoErrors();
        }
        Notification::assertSentToTimes($active, ResetPassword::class, 1);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
    }
}
