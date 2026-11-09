<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\CareEpisode;
use App\Models\CarePlan;
use App\Models\CareTask;
use App\Models\ClinicalNote;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ClinicalRecordDemoSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClinicalRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    private User $lead;

    private User $patientUser;

    private User $admin;

    private User $coordinator;

    private Patient $patient;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->org = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
        foreach (['nurse' => 'demo.nurse@example.test', 'lead' => 'demo.lead@example.test', 'patientUser' => 'demo.patient@example.test', 'admin' => 'admin@mauricare.mu', 'coordinator' => 'demo.coordinator@example.test'] as $key => $email) {
            $this->{$key} = User::where('email', $email)->firstOrFail();
        }$this->patient = Patient::where('user_id', $this->patientUser->id)->firstOrFail();
    }

    private function staff(User $u): static
    {
        return $this->actingAs($u)->withSession(['organisation_id' => $this->org->id]);
    }

    private function base(): string
    {
        return '/patient-records/'.$this->patient->id.'/clinical';
    }

    private function noteDraft(): ClinicalNote
    {
        $this->staff($this->nurse)->post($this->base().'/notes', ['note_type' => 'nursing', 'visibility' => 'discipline_only', 'appointment_id' => Appointment::where('patient_id', $this->patient->id)->where('provider_id', $this->nurse->id)->firstOrFail()->id, 'request_id' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return ClinicalNote::latest('id')->firstOrFail();
    }

    private function decision(CarePlan $p, string $action = 'approve', ?int $version = null): array
    {
        return ['version' => $version ?? $p->version, 'action' => $action, 'reason' => 'Synthetic clinical decision', 'review_backup_id' => $this->coordinator->id, 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
    }

    public function test_admin_primary_menus_survive_demo_seeding_and_explicit_context_is_respected(): void
    {
        $primary = Organisation::where('name', 'MauriCare')->firstOrFail();
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('workspace.organisation_id', $primary->id)->where('workspace.permissions', fn ($values) => collect($values)->contains('patients.manage')));
        $this->get('/workspace/patients')->assertOk();
        $this->withSession(['organisation_id' => $this->org->id])->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('workspace.organisation_id', $this->org->id));
        $this->withSession(['organisation_id' => 999999])->get('/workspace/patients')->assertForbidden();
    }

    public function test_operational_status_and_published_portal_never_contain_private_records(): void
    {
        $this->staff($this->admin)->get($this->base())->assertOk()->assertInertia(fn (Assert $p) => $p->component('clinical/index', false)->where('permissions.notes.read', false)->where('rows', fn ($rows) => collect($rows)->every(fn ($row) => ! array_key_exists('content', $row))));
        $this->actingAs($this->patientUser)->get('/my-care/patients/'.$this->patient->id.'/clinical')->assertOk()->assertInertia(fn (Assert $p) => $p->component('clinical/portal', false)->has('items', 3));
        $response = $this->get('/my-care/patients/'.$this->patient->id.'/clinical');
        $this->assertStringNotContainsString('SYNTHETIC PRIVATE', $response->getContent());
        $this->assertStringNotContainsString('content_hash', $response->getContent());
        $this->actingAs(User::factory()->create())->get('/my-care/patients/'.$this->patient->id.'/clinical')->assertNotFound();
        $this->staff(User::where('email', 'demo.finance@example.test')->firstOrFail())->get($this->base())->assertForbidden();
    }

    public function test_note_autosave_is_versioned_encrypted_and_checks_each_request(): void
    {
        $a = $this->noteDraft();
        $url = $this->base().'/notes/'.$a->id;
        $this->patchJson($url, ['version' => 1, 'action' => 'save', 'content' => ['subjective' => 'Synthetic autosaved draft']])->assertOk()->assertJsonPath('version', 2);
        $this->patchJson($url, ['version' => 1, 'action' => 'save', 'content' => ['subjective' => 'Stale draft']])->assertConflict();
        $this->assertSame('Synthetic autosaved draft', $a->fresh()->content['subjective']);
        $this->assertStringNotContainsString('Synthetic autosaved draft', DB::table('clinical_notes')->where('id', $a->id)->value('content'));
        PatientAccessRule::where('patient_id', $this->patient->id)->where('user_id', $this->nurse->id)->where('scope', 'notes.write')->update(['revoked_at' => now()]);
        $this->patchJson($url, ['version' => 2, 'action' => 'save', 'content' => ['subjective' => 'Denied']])->assertForbidden();
    }

    public function test_signing_validates_password_late_entry_and_locks_the_original(): void
    {
        $a = $this->noteDraft();
        $url = $this->base().'/notes/'.$a->id;
        $c = ClinicalRecordDemoSeeder::noteContent();
        $d = ['version' => 1, 'action' => 'sign', 'password' => 'wrong', 'declaration' => true, 'content' => $c];
        $this->patchJson($url, $d)->assertUnprocessable()->assertJsonValidationErrors('password');
        $d['password'] = DemoDataSeeder::PASSWORD;
        $d['content']['late_entry_reason'] = '';
        $this->patchJson($url, $d)->assertUnprocessable()->assertJsonValidationErrors('content.late_entry_reason');
        $d['content'] = $c;
        $this->patchJson($url, $d)->assertOk()->assertJsonPath('status', 'signed');
        $hash = $a->fresh()->content_hash;
        $this->assertSame($a->fresh()->digest($a->fresh()->content), $hash);
        $this->patchJson($url, ['version' => 2, 'action' => 'save', 'content' => ['subjective' => 'Overwrite']])->assertConflict();
        $this->assertSame($hash, $a->fresh()->content_hash);
    }

    public function test_addendum_links_an_immutable_original_and_cannot_impersonate_another_author(): void
    {
        $original = ClinicalNote::where('patient_id', $this->patient->id)->whereNull('original_id')->where('status', 'signed')->firstOrFail();
        $hash = $original->content_hash;
        $d = ['note_type' => 'nursing', 'visibility' => 'discipline_only', 'appointment_id' => $original->appointment_id, 'original_id' => $original->id, 'request_id' => (string) Str::uuid()];
        $this->staff($this->nurse)->post($this->base().'/notes', $d)->assertSessionHasErrors('addendum_reason');
        $d['addendum_reason'] = 'Synthetic attributed correction';
        $this->post($this->base().'/notes', $d)->assertSessionHasNoErrors();
        $a = ClinicalNote::where('request_id', $d['request_id'])->firstOrFail();
        $this->assertSame($original->id, $a->original_id);
        $this->assertSame($hash, $original->fresh()->content_hash);
        $count = ClinicalNote::count();
        $this->post($this->base().'/notes', $d)->assertSessionHasNoErrors();
        $this->assertSame($count, ClinicalNote::count());
        $this->staff($this->lead)->patchJson($this->base().'/notes/'.$a->id, ['version' => 1, 'action' => 'save', 'content' => ['subjective' => 'No']])->assertForbidden();
    }

    public function test_discipline_classification_and_role_templates_are_enforced(): void
    {
        $physio = User::where('email', 'demo.physio@example.test')->firstOrFail();
        $this->staff($physio)->get($this->base())->assertInertia(fn (Assert $p) => $p->where('noteTypes', ['physiotherapy'])->where('rows', fn ($rows) => collect($rows)->where('kind', 'notes')->where('visibility', 'discipline_only')->every(fn ($row) => ! isset($row['content']))));
        $this->staff($this->nurse)->post($this->base().'/notes', ['note_type' => 'doctor', 'visibility' => 'clinical_team', 'appointment_id' => Appointment::where('patient_id', $this->patient->id)->value('id'), 'request_id' => (string) Str::uuid()])->assertSessionHasErrors('note_type');
        $this->getJson('/search?q=Aline')->assertOk();
        $this->assertStringNotContainsString('SYNTHETIC PRIVATE', $this->getJson('/search?q=Aline')->getContent());
    }

    public function test_plan_approval_supersedes_one_current_version_and_creates_a_routine_review_task(): void
    {
        $plan = CarePlan::where('patient_id', $this->patient->id)->where('status', 'review')->firstOrFail();
        $old = CarePlan::findOrFail($plan->previous_id);
        $oldHash = $old->content_hash;
        $beforeInvoice = DB::table('invoices')->pluck('status', 'id')->all();
        $this->staff($this->lead)->post($this->base().'/plans/'.$plan->id.'/transition', $this->decision($plan))->assertSessionHasNoErrors();
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertSame($oldHash, $old->fresh()->content_hash);
        $this->assertSame($plan->id, CareEpisode::find($plan->episode_id)->current_plan_id);
        $this->assertSame('cancelled', CareTask::find($old->review_task_id)->status);
        $task = CareTask::findOrFail($plan->fresh()->review_task_id);
        $this->assertSame($this->lead->id, $task->owner_id);
        $this->assertSame($this->coordinator->id, $task->backup_id);
        $this->assertStringNotContainsString('SYNTHETIC PRIVATE', $task->description);
        $this->assertSame($beforeInvoice, DB::table('invoices')->pluck('status', 'id')->all());
        $count = CareTask::count();
        $this->post($this->base().'/plans/'.$plan->id.'/transition', $this->decision($plan, 'approve', 1))->assertConflict();
        $this->assertSame($count, CareTask::count());
    }

    public function test_stale_competing_plan_cannot_replace_a_newer_approved_version(): void
    {
        $review = CarePlan::where('patient_id', $this->patient->id)->where('status', 'review')->firstOrFail();
        $draft = CarePlan::where('patient_id', $this->patient->id)->where('status', 'draft')->firstOrFail();
        $this->staff($this->lead)->post($this->base().'/plans/'.$review->id.'/transition', $this->decision($review))->assertSessionHasNoErrors();
        $this->staff($this->nurse)->patchJson($this->base().'/plans/'.$draft->id, ['version' => $draft->version, 'action' => 'sign', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true, 'content' => $draft->content])->assertOk();
        $this->staff($this->lead)->post($this->base().'/plans/'.$draft->id.'/transition', $this->decision($draft->fresh()))->assertConflict();
        $this->assertSame(1, CarePlan::where('episode_id', $review->episode_id)->where('status', 'active')->count());
    }

    public function test_plan_signing_requires_goals_assigned_responsibility_and_actual_agreement(): void
    {
        $draft = CarePlan::where('patient_id', $this->patient->id)->where('status', 'draft')->firstOrFail();
        $url = $this->base().'/plans/'.$draft->id;
        $c = ClinicalRecordDemoSeeder::planContent($this->nurse->id);
        $d = ['version' => 1, 'action' => 'sign', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true, 'content' => $c];
        $this->staff($this->nurse)->patchJson($url, [...$d, 'content' => [...$c, 'goals' => []]])->assertUnprocessable()->assertJsonValidationErrors('content.goals');
        $c['interventions'][0]['responsible_id'] = (string) $this->coordinator->id;
        $this->patchJson($url, [...$d, 'content' => $c])->assertUnprocessable()->assertJsonValidationErrors('content.interventions.0.responsible_id');
        $c = ClinicalRecordDemoSeeder::planContent($this->nurse->id);
        $c['patient_agreement'] = 'capacity_route';
        $this->patchJson($url, [...$d, 'content' => $c])->assertUnprocessable()->assertJsonValidationErrors('content.capacity_evidence');
        $this->patchJson($url, $d)->assertOk()->assertJsonPath('status', 'review');
        $this->assertNotSame($draft->id, CareEpisode::find($draft->episode_id)->current_plan_id);
    }

    public function test_pause_resume_and_completion_preserve_content_and_are_separate_from_discharge(): void
    {
        $p = CarePlan::where('patient_id', $this->patient->id)->where('status', 'active')->firstOrFail();
        $hash = $p->content_hash;
        $this->staff($this->lead);
        foreach (['pause' => 'paused', 'resume' => 'active', 'complete' => 'completed'] as $action => $status) {
            $this->post($this->base().'/plans/'.$p->id.'/transition', $this->decision($p->fresh(), $action))->assertSessionHasNoErrors();
            $this->assertSame($status, $p->fresh()->status);
            $this->assertSame($hash, $p->fresh()->content_hash);
        }
        $this->assertNull(CareEpisode::find($p->episode_id)->current_plan_id);
        $this->assertSame('active', $this->patient->fresh()->status);
        $this->assertSame('cancelled', CareTask::find($p->review_task_id)->status);
    }

    public function test_publication_requires_lead_review_and_never_auto_copies_private_content(): void
    {
        $a = $this->noteDraft();
        $this->patchJson($this->base().'/notes/'.$a->id, ['version' => 1, 'action' => 'sign', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true, 'content' => ClinicalRecordDemoSeeder::noteContent()])->assertOk();
        $a->refresh();
        $url = $this->base().'/notes/'.$a->id.'/publish';
        $d = ['request_id' => (string) Str::uuid(), 'version' => $a->version, 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true, 'summary' => 'Reviewed plain-language synthetic summary only.'];
        $this->post($url, $d)->assertForbidden();
        $this->staff($this->lead)->post($url, $d)->assertSessionHasNoErrors();
        $this->post($url, $d)->assertSessionHasNoErrors();
        $this->actingAs($this->patientUser)->get('/my-care/patients/'.$this->patient->id.'/clinical')->assertInertia(fn (Assert $p) => $p->where('items', fn ($items) => collect($items)->contains(fn ($v) => $v['content']['summary'] === $d['summary'])));
        $this->assertStringNotContainsString('SYNTHETIC PRIVATE', $this->get('/my-care/patients/'.$this->patient->id.'/clinical')->getContent());
        $this->staff($this->lead)->post($url, [...$d, 'request_id' => (string) Str::uuid(), 'summary' => 'Overwrite'])->assertSessionHasErrors('summary');
    }

    public function test_cross_tenant_blocks_expired_credentials_and_unknown_content_are_denied(): void
    {
        $a = $this->noteDraft();
        $url = $this->base().'/notes/'.$a->id;
        $this->patchJson($url, ['version' => 1, 'action' => 'save', 'content' => ['subjective' => 'Synthetic', 'signed_by' => $this->nurse->id]])->assertUnprocessable()->assertJsonValidationErrors('content');
        PatientAccessRule::create(['patient_id' => $this->patient->id, 'user_id' => $this->nurse->id, 'kind' => 'block', 'scope' => 'all', 'purpose' => 'Synthetic restriction', 'authority_type' => 'patient_block', 'evidence' => 'Synthetic block', 'wording_version' => 'test', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(), 'recorded_by' => $this->patientUser->id, 'request_id' => (string) Str::uuid()]);
        $this->patchJson($url, ['version' => 1, 'action' => 'save', 'content' => ['subjective' => 'Blocked']])->assertForbidden();
        $this->staff($this->lead)->withSession(['organisation_id' => Organisation::create(['name' => 'Foreign'])->id])->get($this->base())->assertNotFound();
    }

    public function test_first_plan_proposal_creates_an_episode_and_only_an_independent_lead_activates_it(): void
    {
        $key = (string) Str::uuid();
        $this->staff($this->nurse)->post($this->base().'/plans', ['request_id' => $key, 'episode_title' => 'Synthetic second care programme'])->assertSessionHasNoErrors();
        $p = CarePlan::where('request_id', $key)->firstOrFail();
        $this->assertNull(CareEpisode::find($p->episode_id)->current_plan_id);
        $this->patchJson($this->base().'/plans/'.$p->id, ['version' => 1, 'action' => 'sign', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true, 'content' => ClinicalRecordDemoSeeder::planContent($this->nurse->id)])->assertOk();
        $this->post($this->base().'/plans/'.$p->id.'/transition', $this->decision($p->fresh()))->assertForbidden();
        $this->staff($this->lead)->post($this->base().'/plans/'.$p->id.'/transition', $this->decision($p->fresh()))->assertSessionHasNoErrors();
        $this->assertSame($p->id, CareEpisode::find($p->episode_id)->current_plan_id);
    }

    public function test_credential_date_expiry_uses_the_mauritius_day_boundary(): void
    {
        $a = $this->noteDraft();
        $mauritiusNow = CarbonImmutable::now(config('mauricare.time_zone'));
        $this->nurse->application()->update(['credential_expires_at' => $mauritiusNow->toDateString()]);
        $this->travelTo($mauritiusNow->addDay()->startOfDay()->utc());
        $this->patchJson($this->base().'/notes/'.$a->id, ['version' => 1, 'action' => 'save', 'content' => ['subjective' => 'Denied after local midnight']])->assertForbidden();
        $this->travelBack();
    }

    public function test_declined_proposal_cannot_become_active_and_tampered_proposal_cannot_be_approved(): void
    {
        $draft = CarePlan::where('patient_id', $this->patient->id)->where('status', 'draft')->firstOrFail();
        $c = $draft->content;
        $c['patient_agreement'] = 'declined';
        $this->staff($this->nurse)->patchJson($this->base().'/plans/'.$draft->id, ['version' => 1, 'action' => 'sign', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true, 'content' => $c])->assertOk();
        $this->staff($this->lead)->post($this->base().'/plans/'.$draft->id.'/transition', $this->decision($draft->fresh()))->assertSessionHasErrors('reason');
        $review = CarePlan::where('patient_id', $this->patient->id)->where('status', 'review')->whereKeyNot($draft->id)->firstOrFail();
        $current = CareEpisode::find($review->episode_id)->current_plan_id;
        $review->update(['content' => [...$review->content, 'service' => 'Unexpected tampering']]);
        $this->post($this->base().'/plans/'.$review->id.'/transition', $this->decision($review->fresh()))->assertConflict();
        $this->assertSame($current, CareEpisode::find($review->episode_id)->current_plan_id);
    }
}
