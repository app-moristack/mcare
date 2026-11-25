<?php

namespace Tests\Feature;

use App\Models\CareEpisode;
use App\Models\CarePlan;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\User;
use App\Services\PatientAccess;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EpisodeAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $reader;

    private User $lead;

    private Patient $patient;

    private CareEpisode $episode;

    private CareEpisode $otherEpisode;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->reader = User::where('email', 'demo.episode-reader@example.test')->firstOrFail();
        $this->lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
        $this->patient = Patient::where('name', 'Demo Discharge Patient')->firstOrFail();
        $this->episode = CareEpisode::where('patient_id', $this->patient->id)->where('title', 'Demo episode ready for discharge')->firstOrFail();
        $this->otherEpisode = CareEpisode::where('patient_id', $this->patient->id)->whereKeyNot($this->episode->id)->firstOrFail();
        $this->base = '/patient-records/'.$this->patient->id;
    }

    private function workspace(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->patient->organisation_id]);
    }

    private function grant(array $overrides = []): array
    {
        return [
            'request_id' => Str::uuid()->toString(), 'user_id' => $this->reader->id,
            'kind' => 'grant', 'scope' => 'plans.read', 'episode_id' => $this->episode->id,
            'purpose' => 'Synthetic episode review', 'authority_type' => 'patient_consent',
            'evidence' => 'Synthetic consent to read one care plan.', 'wording_version' => 'TEST-v1',
            'starts_at' => now()->format(DATE_ATOM), 'ends_at' => now()->addDays(2)->format(DATE_ATOM), 'attested' => true,
            ...$overrides,
        ];
    }

    public function test_episode_bound_authority_never_grants_unrelated_rows_or_mutations_and_respects_patient_blocks(): void
    {
        $this->assertFalse(PatientAccess::allowed($this->reader, $this->patient, 'plans.read'));
        $this->assertSame([$this->episode->id], PatientAccess::readablePlanEpisodeIds($this->reader, $this->patient));
        $this->workspace($this->reader)->get($this->base.'/clinical')->assertInertia(fn (Assert $page) => $page
            ->where('scopedPlanRead', true)->where('permissions.plans.read', true)->where('permissions.plans.write', false)
            ->where('permissions.plans.publish', false)->where('permissions.plans.approve', false)
            ->has('episodes', 1)->where('episodes.0.id', $this->episode->id)
            ->has('rows', 1)->where('rows.0.episode_id', $this->episode->id)->has('rows.0.content')
            ->has('staff', 0)->has('referrals', 0)->has('visits', 0)->has('reviewBackups', 0)->where('handover', null))
            ->assertDontSee($this->otherEpisode->title);
        $this->postJson($this->base.'/clinical/plans', [])->assertForbidden();
        $this->getJson('/search?q='.urlencode($this->episode->title))->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.category', 'Care plans')->assertJsonPath('results.0.url', $this->base.'/clinical');
        $this->getJson('/search?q='.urlencode($this->otherEpisode->title))->assertJsonCount(0, 'results');

        $this->workspace($this->lead)->post($this->base.'/access', $this->grant([
            'kind' => 'block', 'scope' => 'all', 'episode_id' => null, 'authority_type' => 'patient_block',
        ]))->assertSessionHasNoErrors();
        $this->workspace($this->reader)->get($this->base.'/clinical')->assertForbidden();
        $this->getJson('/search?q='.urlencode($this->episode->title))->assertJsonCount(0, 'results');
    }

    public function test_discharge_ends_only_its_episode_grants_and_denies_them_even_if_not_marked_revoked(): void
    {
        $sibling = $this->grant(['episode_id' => $this->otherEpisode->id]);
        $this->workspace($this->lead)->post($this->base.'/access', $sibling)->assertSessionHasNoErrors();
        $plan = CarePlan::where('episode_id', $this->episode->id)->firstOrFail();
        $payload = [
            'request_id' => Str::uuid()->toString(), 'plan_id' => $plan->id, 'plan_version' => $plan->version,
            'outcomes' => 'Synthetic outcomes', 'patient_discussion' => 'Synthetic discussion', 'medications' => 'Synthetic reconciliation',
            'results_review' => 'Synthetic review', 'summary' => 'Synthetic discharge summary', 'next_steps' => 'Synthetic follow-up',
            'password' => DemoDataSeeder::PASSWORD, 'declaration' => true,
        ];
        $discharge = $this->base.'/clinical/episodes/'.$this->episode->id.'/discharge';
        $this->post($discharge, $payload)->assertSessionHasNoErrors();
        $this->post($discharge, $payload)->assertSessionHasNoErrors();
        $closedGrant = PatientAccessRule::where('episode_id', $this->episode->id)->where('user_id', $this->reader->id)->firstOrFail();
        $signedAt = $this->episode->discharge()->firstOrFail()->signed_at;
        $this->assertEquals($signedAt, $closedGrant->revoked_at);
        $this->assertSame($this->lead->id, $closedGrant->revoked_by);
        $this->assertNull(PatientAccessRule::where('request_id', $sibling['request_id'])->firstOrFail()->revoked_at);
        $this->assertTrue(PatientAccess::allowed($this->lead, $this->patient, 'plans.read'));
        $this->assertDatabaseHas('audit_events', ['event' => 'patient.access.episode_grants.revoked', 'resource_id' => $this->patient->id, 'actor_id' => $this->lead->id]);

        $this->get($this->base)->assertInertia(fn (Assert $page) => $page
            ->where('grantEpisodes', [['id' => $this->otherEpisode->id, 'title' => $this->otherEpisode->title]])
            ->where('rules', fn ($rules) => collect($rules)->contains(fn ($rule) =>
                $rule['id'] === $closedGrant->id && $rule['episode_title'] === $this->episode->title
                && $rule['episode_discharged_at'] === $signedAt->toJSON())));
        $this->postJson($this->base.'/access', $this->grant())->assertUnprocessable()->assertJsonValidationErrors('episode_id');
        $closedGrant->update(['revoked_at' => null]);
        $this->assertSame([$this->otherEpisode->id], PatientAccess::readablePlanEpisodeIds($this->reader, $this->patient));
        $this->workspace($this->reader)->get($this->base.'/clinical')->assertInertia(fn (Assert $page) => $page
            ->has('episodes', 1)->where('episodes.0.id', $this->otherEpisode->id)->where('episodes.0.discharge', null))
            ->assertDontSee('Synthetic discharge summary');
    }

    public function test_staff_and_linked_patient_cannot_bind_other_record_actions_or_another_patients_episode(): void
    {
        $this->workspace($this->lead)->postJson($this->base.'/access', $this->grant(['scope' => 'plans.publish']))
            ->assertUnprocessable()->assertJsonValidationErrors('episode_id');
        $patientUser = User::where('email', 'demo.patient@example.test')->firstOrFail();
        $own = Patient::where('user_id', $patientUser->id)->firstOrFail();
        $foreignEpisode = CareEpisode::where('patient_id', $own->id)->firstOrFail();
        $this->postJson($this->base.'/access', $this->grant(['episode_id' => $foreignEpisode->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('episode_id');

        $nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $portal = '/my-care/patients/'.$own->id;
        $grant = ['user_id' => $nurse->id, 'reason' => 'Synthetic patient authority', 'request_id' => Str::uuid()->toString(),
            'scope' => 'plans.read', 'episode_id' => $foreignEpisode->id, 'ends_at' => now()->addDays(2)->format(DATE_ATOM)];
        $this->actingAs($patientUser)->get($portal)->assertInertia(fn (Assert $page) => $page->where('grantEpisodes.0.id', $foreignEpisode->id));
        $this->postJson($portal.'/grants', [...$grant, 'scope' => 'notes.read'])->assertUnprocessable()->assertJsonValidationErrors('episode_id');
        $this->postJson($portal.'/grants', [...$grant, 'episode_id' => $this->episode->id])->assertUnprocessable()->assertJsonValidationErrors('episode_id');
        $this->post($portal.'/grants', $grant)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('patient_access_rules', ['recorded_by' => $patientUser->id, 'request_id' => $grant['request_id'], 'episode_id' => $foreignEpisode->id]);

        PatientAccessRule::where('patient_id', $own->id)->where('user_id', $nurse->id)->where('scope', 'plans.read')->whereNull('episode_id')
            ->update(['revoked_at' => now()]);
        $this->workspace($nurse)->get('/patient-records/'.$own->id.'/clinical')->assertInertia(fn (Assert $page) => $page
            ->where('scopedPlanRead', true)->where('permissions.notes.read', true)->where('permissions.plans.write', false)
            ->where('rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['kind'] === 'notes' && isset($row['content']))));
    }
}
