<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'a long test password',
            'account_kind' => 'self_patient',
            'preferred_language' => 'en',
            'terms_acceptance' => true,
            'privacy_acknowledgement' => true,
            'password_confirmation' => 'a long test password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_rejected_registration_returns_to_the_form_when_the_referer_is_withheld()
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $this->get('/')->assertOk();
        $version = app(HandleInertiaRequests::class)->version(request());
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->get('/register')->assertOk();

        $response = $this->post('/register', [
            'name' => 'Care Professional',
            'email' => 'taken@example.com',
            'password' => 'a long test password',
            'password_confirmation' => 'a long test password',
            'account_kind' => 'provider_application',
            'preferred_language' => 'en',
            'terms_acceptance' => true,
            'privacy_acknowledgement' => true,
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
