<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RejectedInertiaSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function inertia(): static
    {
        return $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    private function providerWithReviewedApplication(): User
    {
        $user = User::factory()->create(['account_kind' => 'provider_application']);
        $user->application()->create(['kind' => 'provider_application', 'status' => 'approved']);

        return $user;
    }

    private function application(): array
    {
        return ['organisation_id' => Organisation::create(['name' => 'Synthetic care'])->id, 'discipline' => 'nurse',
            'credential_reference' => 'NC-1', 'credential_expires_at' => now()->addYear()->toDateString()];
    }

    public function test_an_authored_conflict_returns_to_the_form_with_its_message()
    {
        $this->actingAs($this->providerWithReviewedApplication());
        $this->inertia()->get('/application')->assertOk();

        $this->inertia()->post('/application', $this->application())
            ->assertRedirect('/application')
            ->assertSessionHasErrors(['form' => 'This application has already been reviewed.']);
    }

    public function test_a_silent_refusal_returns_to_the_form_with_a_readable_message()
    {
        $this->actingAs(User::factory()->create(['account_kind' => 'self_patient']));
        $this->inertia()->get('/dashboard')->assertOk();

        $this->inertia()->post('/application', $this->application())
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['form' => 'You do not have permission to do this.']);
    }

    public function test_requests_outside_inertia_keep_their_http_status()
    {
        $this->actingAs($this->providerWithReviewedApplication());

        $this->post('/application', $this->application())->assertStatus(409);
    }
}
