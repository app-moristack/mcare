<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\PatientAssessment;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssessmentAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    private User $lead;

    private User $patientUser;

    private Patient $patient;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
        $this->patientUser = User::where('email', 'demo.patient@example.test')->firstOrFail();
        $this->patient = Patient::where('user_id', $this->patientUser->id)->firstOrFail();
        $this->org = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
    }

    private function staff(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->org->id]);
    }

    private function url(bool $own = false): string
    {
        return ($own ? '/my-care/patients/' : '/patient-records/').$this->patient->id.'/assessments';
    }

    private function freshDraft(string $kind = 'basic'): PatientAssessment
    {
        $this->staff($kind === 'basic' ? $this->patientUser : $this->nurse)->post($this->url($kind === 'basic'), ['kind' => $kind, 'request_id' => (string) Str::uuid(), 'appointment_id' => Appointment::where('patient_id', $this->patient->id)->value('id')])->assertSessionHasNoErrors();

        return PatientAssessment::latest('id')->firstOrFail();
    }

    public function test_demo_seeder_is_idempotent_and_covers_every_delivered_module(): void
    {
        $tables = ['users', 'organisations', 'memberships', 'patients', 'patient_profiles', 'account_applications', 'referrals', 'appointments', 'attendance_events', 'care_tasks', 'invoices', 'invoice_items', 'payments', 'patient_access_rules', 'patient_summaries', 'patient_summary_revisions', 'patient_assessments', 'clinical_observations', 'operation_notifications', 'clinical_notes', 'care_episodes', 'care_plans', 'care_plan_events', 'clinical_publications'];
        $counts = [];
        foreach ($tables as $t) {
            $counts[$t] = DB::table($t)->count();
            $this->assertGreaterThan(0, $counts[$t], $t);
        }
        $hashes = PatientAssessment::whereNotNull('content_hash')->pluck('content_hash', 'id')->all();
        $clinicalHashes = ['notes' => DB::table('clinical_notes')->pluck('content_hash', 'id')->all(), 'plans' => DB::table('care_plans')->pluck('content_hash', 'id')->all()];
        $this->seed(DemoDataSeeder::class);
        $this->assertSame($clinicalHashes['notes'], DB::table('clinical_notes')->pluck('content_hash', 'id')->all());
        $this->assertSame($clinicalHashes['plans'], DB::table('care_plans')->pluck('content_hash', 'id')->all());
        foreach ($counts as $t => $count) {
            $this->assertDatabaseCount($t, $count);
        }
        $this->assertSame($hashes, PatientAssessment::whereNotNull('content_hash')->pluck('content_hash', 'id')->all());
        foreach (PatientAssessment::whereNotNull('content_hash')->get() as $a) {
            $this->assertSame($a->digest($a->content), $a->content_hash);
        }
    }

    public function test_search_finds_records_and_users_without_returning_private_payloads(): void
    {
        $admin = User::where('email', 'admin@mauricare.mu')->firstOrFail();
        $response = $this->staff($admin)->getJson('/search?q=Demo')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $categories = collect($response->json('results'))->pluck('category');
        foreach (['Patients / clients', 'Users / team', 'Referrals', 'Appointments', 'Tasks', 'Invoices', 'Assessments'] as $c) {
            $this->assertTrue($categories->contains($c), $c);
        }
        foreach ($response->json('results') as $row) {
            $this->assertEqualsCanonicalizing(['category', 'title', 'subtitle', 'url'], array_keys($row));
        }
        $this->assertStringNotContainsString('Synthetic demonstration:', $response->getContent());
    }

    public function test_search_respects_tenant_role_own_record_and_clinical_blocks(): void
    {
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson('/search?q=Demo')->assertJsonCount(0, 'results');
        $finance = User::where('email', 'demo.finance@example.test')->firstOrFail();
        $r = $this->staff($finance)->getJson('/search?q=Demo')->assertOk();
        $this->assertSame(['Invoices', 'My account'], collect($r->json('results'))->pluck('category')->unique()->values()->all());
        $r = $this->staff($this->patientUser)->getJson('/search?q=Demo')->assertOk();
        $this->assertFalse(collect($r->json('results'))->contains(fn ($v) => $v['category'] === 'Users / team' || str_starts_with($v['title'], 'Nursing assessment')));
        $this->staff($this->nurse)->getJson('/search?q=Aline')->assertOk();
        PatientAccessRule::where('patient_id', $this->patient->id)->where('user_id', $this->nurse->id)->update(['revoked_at' => now()]);
        $r = $this->getJson('/search?q=Aline')->assertOk();
        $this->assertFalse(collect($r->json('results'))->contains(fn ($v) => in_array($v['category'], ['Patients / clients', 'Assessments'])));
        $this->withSession(['organisation_id' => Organisation::create(['name' => 'Other'])->id])->getJson('/search?q=Aline')->assertOk()->assertJsonMissing(['category' => 'Patients / clients']);
    }

    public function test_patient_never_receives_private_nursing_content_and_coordinator_gets_status_only(): void
    {
        $this->staff($this->patientUser)->get($this->url(true))->assertOk()->assertInertia(fn (Assert $p) => $p->component('assessments/index', false)->where('rows', fn ($rows) => collect($rows)->every(fn ($a) => $a['kind'] === 'basic'))->missing('schema.nursing'));
        $coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $this->staff($coordinator)->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('permissions.nursing.read', false)->missing('rows.0.content'));
        $a = PatientAssessment::where('patient_id', $this->patient->id)->where('kind', 'nursing')->firstOrFail();
        $this->staff($this->patientUser)->patch($this->url(true).'/'.$a->id, ['action' => 'save', 'version' => 1, 'content' => ['summary' => 'intrusion']])->assertNotFound();
    }

    public function test_basic_patient_submission_validates_locks_and_retains_amendments(): void
    {
        $a = $this->freshDraft();
        $url = $this->url(true).'/'.$a->id;
        $this->patch($url, ['version' => 1, 'action' => 'save', 'content' => ['main_reason' => 'Synthetic partial draft']])->assertSessionHasNoErrors();
        $this->patch($url, ['version' => 1, 'action' => 'save', 'content' => ['main_reason' => 'stale']])->assertConflict();
        $c = DemoDataSeeder::assessmentContent('basic');
        $c['pain_reported'] = 'yes';
        $c['pain_scoring'] = 'scored';
        $c['pain_score'] = '11';
        $this->patch($url, ['version' => 2, 'action' => 'submit', 'declaration' => true, 'content' => $c])->assertSessionHasErrors('content.pain_score');
        $c = DemoDataSeeder::assessmentContent('basic');
        $this->patch($url, ['version' => 2, 'action' => 'submit', 'declaration' => true, 'content' => $c])->assertSessionHasNoErrors();
        $this->assertSame('submitted', $a->fresh()->status);
        $this->assertSame($this->patientUser->id, $a->fresh()->confirmed_by);
        $this->assertStringNotContainsString('Synthetic', DB::table('patient_assessments')->where('id', $a->id)->value('content'));
        $this->patch($url, ['version' => 3, 'action' => 'save', 'content' => $c])->assertConflict();
        $this->post($this->url(true), ['kind' => 'basic', 'previous_id' => $a->id, 'amendment_reason' => 'Correction of synthetic response', 'request_id' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('patient_assessments', ['previous_id' => $a->id, 'status' => 'draft']);
        $this->assertSame('submitted', $a->fresh()->status);
    }

    public function test_assisted_draft_requires_linked_patient_confirmation(): void
    {
        $this->staff($this->nurse)->post($this->url(), ['kind' => 'basic', 'request_id' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $a = PatientAssessment::latest('id')->firstOrFail();
        $this->patch($this->url().'/'.$a->id, ['version' => 1, 'action' => 'submit', 'declaration' => true, 'password' => DemoDataSeeder::PASSWORD, 'content' => DemoDataSeeder::assessmentContent('basic')])->assertForbidden();
        $this->staff($this->patientUser)->patch($this->url(true).'/'.$a->id, ['version' => 1, 'action' => 'submit', 'declaration' => true, 'content' => DemoDataSeeder::assessmentContent('basic')])->assertSessionHasNoErrors();
        $this->assertSame($this->nurse->id, $a->fresh()->author_id);
        $this->assertSame($this->patientUser->id, $a->fresh()->confirmed_by);
    }

    public function test_nursing_signing_requires_password_distinct_identifiers_and_separate_cosign(): void
    {
        $a = $this->freshDraft('nursing');
        $url = $this->url().'/'.$a->id;
        $c = DemoDataSeeder::assessmentContent('nursing');
        $c['identifier_two_type'] = $c['identifier_one_type'];
        $d = ['version' => 1, 'action' => 'sign', 'declaration' => true, 'password' => 'wrong', 'content' => $c];
        $this->patch($url, $d)->assertSessionHasErrors('password');
        $d['password'] = DemoDataSeeder::PASSWORD;
        $this->patch($url, $d)->assertSessionHasErrors('content.identifier_two_type');
        $d['content'] = DemoDataSeeder::assessmentContent('nursing');
        $this->patch($url, $d)->assertSessionHasNoErrors();
        $this->assertSame('awaiting_cosign', $a->fresh()->status);
        $hash = $a->fresh()->content_hash;
        $this->patch($url, ['version' => 2, 'action' => 'save', 'content' => $d['content']])->assertConflict();
        $this->staff($this->lead)->post($url.'/cosign', ['version' => 2, 'declaration' => true, 'password' => DemoDataSeeder::PASSWORD])->assertSessionHasNoErrors();
        $this->assertSame('signed', $a->fresh()->status);
        $this->assertSame($hash, $a->fresh()->content_hash);
        $this->assertSame($this->lead->id, $a->fresh()->cosigned_by);
    }

    public function test_scopes_are_separate_and_revocation_or_wrong_discipline_stops_assessment_access(): void
    {
        $physio = User::where('email', 'demo.physio@example.test')->firstOrFail();
        $this->staff($physio)->get($this->url())->assertForbidden();
        $this->staff($this->nurse)->get($this->url())->assertOk();
        PatientAccessRule::where('patient_id', $this->patient->id)->where('user_id', $this->nurse->id)->whereIn('scope', ['basic.read', 'nursing.read'])->update(['ends_at' => now()]);
        $this->get($this->url())->assertForbidden();
        $this->staff($this->lead)->get($this->url())->assertOk();
        $this->lead->application()->update(['credential_expires_at' => now()->subDay()->toDateString()]);
        $this->get($this->url())->assertOk()->assertInertia(fn (Assert $p) => $p->where('permissions.nursing.read', false)->where('permissions.basic.read', false)->missing('rows.0.content'));
    }

    public function test_nursing_visit_tenant_and_author_cannot_be_forged_and_input_cannot_add_hidden_fields(): void
    {
        $this->staff($this->nurse)->post($this->url(), ['kind' => 'nursing', 'appointment_id' => 999999, 'request_id' => (string) Str::uuid()])->assertNotFound();
        $a = $this->freshDraft('nursing');
        $this->patch($this->url().'/'.$a->id, ['version' => 1, 'action' => 'save', 'content' => ['summary' => 'Synthetic', 'signed_by' => $this->nurse->id]])->assertSessionHasErrors('content');
        $this->staff($this->lead)->patch($this->url().'/'.$a->id, ['version' => 1, 'action' => 'save', 'content' => ['summary' => 'Synthetic']])->assertForbidden();
        $this->withSession(['organisation_id' => Organisation::create(['name' => 'Foreign'])->id])->get($this->url())->assertNotFound();
    }

    public function test_cosign_checks_password_integrity_and_current_authority(): void
    {
        $a = PatientAssessment::where('status', 'awaiting_cosign')->firstOrFail();
        $this->patient = Patient::findOrFail($a->patient_id);
        $url = $this->url().'/'.$a->id.'/cosign';
        $this->staff($this->lead)->post($url, ['version' => $a->version, 'password' => 'wrong', 'declaration' => true])->assertSessionHasErrors('password');
        $a->update(['content' => [...$a->content, 'summary' => 'Unexpected alteration']]);
        $this->post($url, ['version' => $a->version, 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true])->assertConflict();
        PatientAccessRule::where('patient_id', $this->patient->id)->where('user_id', $this->lead->id)->where('scope', 'nursing.cosign')->update(['revoked_at' => now()]);
        $this->post($url, ['version' => $a->version, 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true])->assertForbidden();
    }

    public function test_assisted_verbal_confirmation_supports_patients_without_personal_accounts(): void
    {
        $this->patient = Patient::where('organisation_id', $this->org->id)->whereNull('user_id')->firstOrFail();
        $this->staff($this->nurse)->post($this->url(), ['kind' => 'basic', 'request_id' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $a = PatientAssessment::latest('id')->firstOrFail();
        $c = DemoDataSeeder::assessmentContent('basic');
        $c['respondent_relationship'] = 'assisted';
        $c['assistance_details'] = 'Named nurse recorded synthetic verbal patient response';
        $d = ['version' => 1, 'action' => 'submit', 'declaration' => true, 'password' => DemoDataSeeder::PASSWORD, 'content' => $c];
        $this->patch($this->url().'/'.$a->id, $d)->assertSessionHasErrors('confirmation_evidence');
        $this->patch($this->url().'/'.$a->id, [...$d, 'confirmation_evidence' => 'Actual synthetic patient confirmed their responses verbally.'])->assertSessionHasNoErrors();
        $this->assertSame('assisted_verbal', $a->fresh()->confirmation_method);
    }

    public function test_draft_creation_retry_is_idempotent_and_future_assessment_time_is_rejected(): void
    {
        $d = ['kind' => 'nursing', 'request_id' => (string) Str::uuid(), 'appointment_id' => Appointment::where('patient_id', $this->patient->id)->value('id')];
        $this->staff($this->nurse)->post($this->url(), $d)->assertSessionHasNoErrors();
        $count = PatientAssessment::count();
        $this->post($this->url(), $d)->assertSessionHasNoErrors();
        $this->assertSame($count, PatientAssessment::count());
        $a = PatientAssessment::where('request_id', $d['request_id'])->firstOrFail();
        $c = DemoDataSeeder::assessmentContent('nursing');
        $c['assessment_at'] = now('Indian/Mauritius')->addDay()->format('Y-m-d\TH:i');
        $this->patch($this->url().'/'.$a->id, ['version' => 1, 'action' => 'sign', 'declaration' => true, 'password' => DemoDataSeeder::PASSWORD, 'content' => $c])->assertSessionHasErrors('content.assessment_at');
        $c['assessment_at'] = 'invalid-time';
        $this->patch($this->url().'/'.$a->id, ['version' => 1, 'action' => 'sign', 'declaration' => true, 'password' => DemoDataSeeder::PASSWORD, 'content' => $c])->assertSessionHasErrors('content.assessment_at');
    }

    public function test_authenticated_history_is_encrypted_and_logout_invalidates_cached_history(): void
    {
        $this->staff($this->nurse)->get($this->url())->assertOk()->assertViewHas('page', fn ($page) => $page['encryptHistory'] === true);
        $this->post('/logout')->assertRedirect('/');
        $this->get('/')->assertOk()->assertViewHas('page', fn ($page) => $page['clearHistory'] === true && $page['encryptHistory'] === false);
    }
}
