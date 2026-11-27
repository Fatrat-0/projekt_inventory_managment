<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_not_publicly_accessible(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/login')->assertOk()->assertDontSee('/register', false);
    }

    public function test_direct_registration_request_cannot_issue_an_account(): void
    {
        $this->postJson('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }
}
