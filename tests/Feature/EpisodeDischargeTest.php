<?php

namespace Tests\Feature;

use App\Models\CareEpisode;
use App\Models\CarePlan;
use App\Models\EpisodeDischarge;
use App\Models\InvestigationResult;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EpisodeDischargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_discharge_requires_scoped_authority_password_and_completed_work_and_retains_the_signed_record(): void
    {
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $patient = Patient::where('name', 'Demo Discharge Patient')->firstOrFail();
        $episode = CareEpisode::where('patient_id', $patient->id)->firstOrFail();
        $plan = CarePlan::where('episode_id', $episode->id)->firstOrFail();
        $lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
        $coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $patientUser = User::factory()->create();
        $patient->update(['user_id' => $patientUser->id]);
        $base = '/patient-records/'.$patient->id.'/clinical';
        $url = $base.'/episodes/'.$episode->id.'/discharge';
        $payload = [
            'request_id' => (string) Str::uuid(), 'plan_id' => $plan->id, 'plan_version' => $plan->version,
            'outcomes' => 'Synthetic private outcome', 'patient_discussion' => 'Synthetic discussion',
            'medications' => 'Synthetic reconciliation', 'results_review' => 'Synthetic review',
            'summary' => 'Synthetic patient discharge summary', 'next_steps' => 'Synthetic follow-up',
            'password' => DemoDataSeeder::PASSWORD, 'declaration' => true,
        ];

        $this->actingAs($coordinator)->withSession(['organisation_id' => $patient->organisation_id])->postJson($url, $payload)->assertForbidden();
        $this->actingAs($lead)->postJson($url, [...$payload, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson($url, [...$payload, 'plan_version' => $plan->version + 1])->assertConflict();

        $result = InvestigationResult::firstOrFail()->replicate();
        $result->fill(['patient_id' => $patient->id, 'request_id' => (string) Str::uuid(), 'task_id' => null])->save();
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('episode');
        $this->assertDatabaseCount('episode_discharges', 0);
        $result->update(['status' => 'completed']);

        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->postJson($url, [...$payload, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('episode_discharges', 1);
        $discharge = EpisodeDischarge::firstOrFail();
        $this->assertSame($lead->id, $discharge->signed_by);
        $this->assertSame($discharge->content_hash, EpisodeDischarge::digest($episode->id, $plan->id, $lead->id, $discharge->content));
        $this->assertStringNotContainsString('Synthetic private outcome', DB::table('episode_discharges')->value('content'));

        $this->get($base)->assertInertia(fn (Assert $page) => $page->where('episodes.0.discharge.content.outcomes', 'Synthetic private outcome'));
        PatientAccessRule::where('patient_id', $patient->id)->where('user_id', $lead->id)->where('scope', 'plans.approve')->update(['revoked_at' => now()]);
        $this->postJson($url, $payload)->assertForbidden();
        $this->actingAs($patientUser)->get('/my-care/patients/'.$patient->id.'/clinical')->assertInertia(fn (Assert $page) => $page
            ->where('items.0.kind', 'discharge')->where('items.0.content.summary', $payload['summary'])->missing('items.0.content.outcomes'));
    }
}
