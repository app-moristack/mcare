<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IdentityFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_replace(['name' => 'Test Patient', 'email' => 'new@example.test', 'password' => 'a long test password', 'password_confirmation' => 'a long test password',
            'account_kind' => 'self_patient', 'preferred_language' => 'en', 'terms_acceptance' => true, 'privacy_acknowledgement' => true], $overrides);
    }

    public function test_patient_registration_records_acknowledgements_and_sends_verification(): void
    {
        Notification::fake();
        $this->post('/register', $this->registration())->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $user = User::first();
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertNotNull($user->patientProfile);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(config('mauricare.terms_version'), $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'event' => 'account.registered']);
    }

    public function test_provider_application_never_creates_memberships_or_a_patient_chart(): void
    {
        Notification::fake();
        $this->post('/register', $this->registration(['account_kind' => 'provider_application', 'role' => 'owner', 'account_status' => 'approved']))->assertSessionHasNoErrors();
        $user = User::first();
        $this->assertSame('active', $user->account_status);
        $this->assertSame('pending', $user->application->status);
        $this->assertNull($user->patientProfile);
        $this->assertDatabaseCount('memberships', 0);
        $this->actingAs($user->forceFill(['email_verified_at' => now()]))->get('/onboarding')->assertForbidden();
    }

    public function test_admin_registration_is_rejected(): void
    {
        $this->post('/register', $this->registration(['account_kind' => 'admin']))->assertSessionHasErrors('account_kind');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_partner_and_proxy_accounts_do_not_get_patient_records(): void
    {
        Notification::fake();
        foreach (['partner_application', 'proxy'] as $kind) {
            $this->post('/register', $this->registration(['email' => $kind.'@example.test', 'account_kind' => $kind]))->assertSessionHasNoErrors();
            $this->assertDatabaseCount('patient_profiles', 0);
            $this->post('/logout');
        }
        $this->assertDatabaseCount('account_applications', 1);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_registration_requires_explicit_acknowledgements_and_long_password(): void
    {
        $this->post('/register', $this->registration(['terms_acceptance' => false, 'privacy_acknowledgement' => false, 'password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors(['terms_acceptance', 'privacy_acknowledgement', 'password']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unverified_patient_can_see_onboarding_tasks_but_cannot_enter_details(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->get('/onboarding')->assertRedirect('/verify-email');
        $this->patch('/onboarding', [])->assertRedirect('/verify-email');
    }

    public function test_profile_update_uses_authenticated_patient_and_ignores_foreign_identifiers(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$user, $other] as $u) {
            $u->patientProfile()->create(['public_id' => (string) Str::uuid()]);
        }
        $this->actingAs($user)->patch('/onboarding', ['user_id' => $other->id, 'legal_name' => 'My Name', 'date_of_birth' => '1990-05-20', 'mobile_phone' => '+23051234567', 'locality' => 'Port Louis', 'identity_status' => 'verified'])
            ->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertSame('My Name', $user->patientProfile->fresh()->legal_name);
        $this->assertNull($other->patientProfile->fresh()->legal_name);
        $this->assertSame('unverified', $user->fresh()->identity_status);
        $this->assertNotNull($user->patientProfile->fresh()->onboarding_completed_at);
    }

    public function test_dashboard_does_not_expose_another_patients_profile(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $other->patientProfile()->create(['public_id' => (string) Str::uuid(), 'legal_name' => 'Private Patient']);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('dashboard', false)->where('patientProfile', null));
    }

    public function test_invalid_demographics_cannot_complete_onboarding(): void
    {
        $user = User::factory()->create();
        $user->patientProfile()->create(['public_id' => (string) Str::uuid()]);
        $this->actingAs($user)->patch('/onboarding', ['legal_name' => 'Test', 'date_of_birth' => now()->addDay()->toDateString(), 'locality' => 'Test', 'mobile_phone' => '123'])
            ->assertSessionHasErrors(['date_of_birth', 'mobile_phone']);
        $this->assertNull($user->patientProfile->fresh()->onboarding_completed_at);
    }

    public function test_suspended_account_is_denied_during_an_existing_session(): void
    {
        $user = User::factory()->create(['account_status' => 'suspended']);
        $this->actingAs($user)->get('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_disabled_account_cannot_login(): void
    {
        $user = User::factory()->create(['account_status' => 'disabled']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_closing_registered_account_retains_profile_and_audit_records(): void
    {
        $user = User::factory()->create();
        $user->patientProfile()->create(['public_id' => (string) Str::uuid()]);
        $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertSame('closed', $user->fresh()->account_status);
        $this->assertDatabaseCount('patient_profiles', 1);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_owner_provisioning_requires_verified_identity_and_creates_only_pending_membership(): void
    {
        $user = User::factory()->unverified()->create();
        $this->artisan('mauricare:provision-owner', ['email' => $user->email, 'organisation' => 'Test Care'])->assertExitCode(1);
        $this->assertDatabaseCount('memberships', 0);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->artisan('mauricare:provision-owner', ['email' => $user->email, 'organisation' => 'Test Care'])->assertExitCode(0);
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'role' => 'owner', 'status' => 'pending']);
        $this->artisan('mauricare:provision-owner', ['email' => $user->email, 'organisation' => 'Test Care'])->assertExitCode(0);
        $this->assertDatabaseCount('memberships', 1);
    }
}
