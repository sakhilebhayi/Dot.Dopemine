<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationClosedTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_closed_by_default(): void
    {
        $this->assertFalse(Features::enabled(Features::registration()));
        $this->get('/register')->assertNotFound();
    }

    public function test_registration_post_creates_no_account_by_default(): void
    {
        $this->post('/register', [
            'name' => 'Stranger',
            'email' => 'stranger@example.com',
            'password' => 'a-long-password-123',
            'password_confirmation' => 'a-long-password-123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'stranger@example.com']);
        $this->assertGuest();
    }

    public function test_login_and_password_reset_still_work(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
    }

    public function test_the_welcome_page_does_not_offer_to_create_an_account(): void
    {
        $this->get('/')->assertOk()->assertDontSee('/register', false)->assertSee('Sign in');
    }
}
