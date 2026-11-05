<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\PatientSummary;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PatientRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $nurse;

    private User $patientUser;

    private Patient $patient;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(AdminUserSeeder::class);
        $this->admin = User::where('email', 'admin@mauricare.mu')->firstOrFail();
        $this->org = Organisation::first();
        $this->nurse = User::factory()->create();
        $this->patientUser = User::factory()->create();
        $this->nurse->memberships()->create(['organisation_id' => $this->org->id, 'role' => 'nurse', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'approved_by' => $this->admin->id]);
        $this->nurse->application()->create(['organisation_id' => $this->org->id, 'kind' => 'provider', 'status' => 'approved', 'discipline' => 'nurse', 'credential_reference' => 'Synthetic licence', 'credential_expires_at' => now()->addMonth()->toDateString()]);
        $this->patient = Patient::create(['organisation_id' => $this->org->id, 'user_id' => $this->patientUser->id, 'reference' => (string) Str::uuid(), 'name' => 'Synthetic Patient', 'date_of_birth' => '1970-01-01', 'locality' => 'Curepipe', 'authority_type' => 'patient_request', 'authority_evidence' => 'Synthetic intake', 'request_id' => (string) Str::uuid()]);
        $this->actingAs($this->admin);
    }

    private function rule(string $kind = 'grant', string $scope = 'summary.read', array $extra = []): PatientAccessRule
    {
        return PatientAccessRule::create([...['patient_id' => $this->patient->id, 'user_id' => $this->nurse->id, 'kind' => $kind, 'scope' => $scope, 'role' => $kind === 'assignment' ? 'nurse' : null, 'purpose' => 'Synthetic care', 'authority_type' => $kind === 'assignment' ? 'care_assignment' : 'patient_consent', 'evidence' => 'Synthetic actual authority', 'wording_version' => 'test-v1', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(), 'recorded_by' => $this->admin->id, 'request_id' => (string) Str::uuid()], ...$extra]);
    }

    private function demographicData(): array
    {
        return ['version' => 1, 'name' => 'Synthetic Patient', 'date_of_birth' => '1970-01-01', 'phone' => '+23051234567', 'email' => '', 'locality' => 'Curepipe', 'content' => ['given_names' => 'Synthetic', 'family_name' => 'Patient', 'dob_precision' => 'exact', 'preferred_language' => 'en', 'information_source' => 'patient', 'source_details' => 'Patient confirmed today', 'record_status' => 'provisional', 'safe_contact_method' => 'phone', 'country' => 'MU', 'emergency_contact_unavailable_reason' => 'Declined']];
    }

    public function test_demographics_are_validated_encrypted_versioned_and_not_in_directory(): void
    {
        $data = $this->demographicData();
        $this->patch('/patient-records/'.$this->patient->id, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Synthetic', $this->patient->fresh()->demographics['given_names']);
        $this->assertStringNotContainsString('Synthetic', DB::table('patients')->value('demographics'));
        $this->patch('/patient-records/'.$this->patient->id, $data)->assertConflict();
        $this->get('/workspace/patients')->assertInertia(fn (Assert $p) => $p->missing('rows.data.0.demographics'));
        $this->patch('/patient-records/'.$this->patient->id, [...$data, 'version' => 2, 'content' => [...$data['content'], 'private_notes' => 'Not allowed']])->assertSessionHasErrors('content');
    }

    public function test_unknown_birth_date_and_assisted_contact_are_supported_but_unproven_contacts_are_rejected(): void
    {
        $d = $this->demographicData();
        $d['date_of_birth'] = '';
        $d['phone'] = '';
        $d['content']['dob_precision'] = 'unknown';
        $d['content']['safe_contact_method'] = 'representative';
        $this->patch('/patient-records/'.$this->patient->id, $d)->assertSessionHasErrors('content.assisted_contact');
        $d['content']['assisted_contact'] = 'Assisted contact by named coordinator at agreed clinic appointment';
        $this->patch('/patient-records/'.$this->patient->id, $d)->assertSessionHasNoErrors();
        $this->assertNull($this->patient->fresh()->date_of_birth);
    }

    public function test_coordinates_and_single_name_and_emergency_contact_require_evidence(): void
    {
        $d = $this->demographicData();
        $d['content']['home_latitude'] = 0;
        $this->patch('/patient-records/'.$this->patient->id, $d)->assertSessionHasErrors('content.home_latitude');
        $d['content']['home_longitude'] = 0;
        $this->patch('/patient-records/'.$this->patient->id, $d)->assertSessionHasErrors('content.pin_verification_evidence');
        unset($d['content']['home_latitude'],$d['content']['home_longitude']);
        $d['content']['family_name'] = '';
        $this->patch('/patient-records/'.$this->patient->id, $d)->assertSessionHasErrors('content.family_name');
        $d['content']['single_name_reason'] = 'Legal single name';
        $d['content']['emergency_contact_name'] = 'Synthetic contact';
        $this->patch('/patient-records/'.$this->patient->id, $d)->assertSessionHasErrors('content.emergency_contact_phone');
    }

    public function test_admin_does_not_bypass_clinical_access_and_denial_is_audited(): void
    {
        $this->getJson('/patient-records/'.$this->patient->id.'/summary')->assertForbidden();
        $this->assertDatabaseHas('audit_events', ['event' => 'patient.summary.read.denied', 'resource_id' => $this->patient->id]);
        $this->actingAs($this->nurse)->getJson('/patient-records/'.$this->patient->id.'/summary')->assertForbidden();
        $this->rule('assignment', 'all');
        $this->rule();
        $this->getJson('/patient-records/'.$this->patient->id.'/summary')->assertOk()->assertJsonPath('content.allergy_status', 'not_assessed');
    }

    public function test_expired_revoked_future_blocked_and_suspended_access_fail_on_every_request(): void
    {
        $a = $this->rule('assignment', 'all');
        $g = $this->rule();
        $this->actingAs($this->nurse);
        $url = '/patient-records/'.$this->patient->id.'/summary';
        $this->getJson($url)->assertOk();
        $g->update(['ends_at' => now()]);
        $this->getJson($url)->assertForbidden();
        $g->update(['ends_at' => now()->addDay(), 'starts_at' => now()->addHour()]);
        $this->getJson($url)->assertForbidden();
        $g->update(['starts_at' => now()->subMinute(), 'revoked_at' => now()]);
        $this->getJson($url)->assertForbidden();
        $g->update(['revoked_at' => null]);
        $b = $this->rule('block', 'all');
        $this->getJson($url)->assertForbidden();
        $b->update(['revoked_at' => now()]);
        $this->getJson($url)->assertOk();
        $this->nurse->application()->update(['credential_expires_at' => now()->subDay()->toDateString()]);
        $this->getJson($url)->assertForbidden();
        $this->nurse->application()->update(['credential_expires_at' => now()->addDay()->toDateString()]);
        $this->nurse->memberships()->update(['status' => 'suspended']);
        $this->getJson($url)->assertNotFound();
    }

    public function test_scoped_summary_writes_validate_allergies_preserve_revisions_and_reject_stale_versions(): void
    {
        $this->rule('assignment', 'all');
        $this->rule();
        $this->actingAs($this->nurse);
        $url = '/patient-records/'.$this->patient->id.'/summary';
        $d = ['version' => 0, 'reason' => 'Patient history reconciled', 'content' => ['allergy_status' => 'known_allergies', 'allergies' => [], 'conditions' => [], 'medications' => [], 'surgeries' => [], 'immunisations' => []]];
        $this->patch($url, $d)->assertForbidden();
        $this->rule('grant', 'summary.write');
        $this->patch($url, $d)->assertSessionHasErrors('content.allergies');
        $d['content']['allergies'] = [['substance' => 'Synthetic substance', 'reaction' => 'Synthetic reaction', 'severity' => 'unknown', 'source' => 'Patient']];
        $this->patch($url, $d)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('patient_summary_revisions', 1);
        $this->assertStringNotContainsString('Synthetic substance', DB::table('patient_summaries')->value('content'));
        $this->patch($url, $d)->assertConflict();
        $d['version'] = 1;
        $d['content']['private_nursing_assessment'] = 'No';
        $this->patch($url, $d)->assertSessionHasErrors('content');
    }

    public function test_only_linked_patient_can_see_own_summary_and_revoke_or_block_with_immediate_effect(): void
    {
        $this->rule('assignment', 'all');
        $g = $this->rule();
        PatientSummary::create(['patient_id' => $this->patient->id, 'content' => ['allergy_status' => 'unknown'], 'updated_by' => $this->nurse->id]);
        $url = '/my-care/patients/'.$this->patient->id;
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($this->patientUser)->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->where('summary.content.allergy_status', 'unknown')->missing('rules.0.evidence'));
        $this->post($url.'/blocks', ['user_id' => $this->nurse->id, 'reason' => 'Synthetic restriction'])->assertSessionHasNoErrors();
        $b = PatientAccessRule::where('kind', 'block')->first();
        $this->actingAs($this->admin)->post('/patient-records/'.$this->patient->id.'/access/'.$b->id.'/revoke', ['reason' => 'Try lift'])->assertForbidden();
        $this->actingAs($this->nurse)->getJson('/patient-records/'.$this->patient->id.'/summary')->assertForbidden();
        $this->actingAs($this->patientUser)->post($url.'/access/'.$b->id.'/revoke', ['reason' => 'Care arranged'])->assertSessionHasNoErrors();
        $this->post($url.'/access/'.$g->id.'/revoke', ['reason' => 'Consent withdrawn'])->assertSessionHasNoErrors();
        $this->actingAs($this->nurse)->getJson('/patient-records/'.$this->patient->id.'/summary')->assertForbidden();
    }

    public function test_rules_cannot_be_backdated_are_idempotent_and_tenant_scoped(): void
    {
        $url = '/patient-records/'.$this->patient->id.'/access';
        $d = ['request_id' => (string) Str::uuid(), 'user_id' => $this->nurse->id, 'kind' => 'grant', 'scope' => 'summary.read', 'purpose' => 'Synthetic care', 'authority_type' => 'patient_consent', 'evidence' => 'Actual synthetic patient authority', 'wording_version' => 'v1', 'starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(), 'attested' => true];
        $this->post($url, $d)->assertSessionHasErrors('starts_at');
        $d['starts_at'] = now()->toIso8601String();
        $this->post($url, $d)->assertSessionHasNoErrors();
        $this->post($url, $d)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('patient_access_rules', 1);
        $other = Organisation::create(['name' => 'Other']);
        $this->withSession(['organisation_id' => $other->id])->get('/patient-records/'.$this->patient->id)->assertNotFound();
        $this->withSession(['organisation_id' => $this->org->id])->actingAs($this->nurse)->patch('/patient-records/'.$this->patient->id, $this->demographicData())->assertForbidden();
    }

    public function test_patient_can_authorise_only_assigned_scopes_and_cannot_extend_assignment_or_authorise_another_patient(): void
    {
        $a = $this->rule('assignment', 'all');
        $url = '/my-care/patients/'.$this->patient->id.'/grants';
        $d = ['user_id' => $this->nurse->id, 'request_id' => (string) Str::uuid(), 'scope' => 'summary.read', 'reason' => 'I authorise care summary access', 'ends_at' => $a->ends_at->toIso8601String()];
        $this->actingAs($this->patientUser)->post($url, [...$d, 'scope' => 'all'])->assertSessionHasErrors('scope');
        $this->post($url, [...$d, 'ends_at' => now()->addMonth()->toIso8601String()])->assertNotFound();
        $this->post($url, $d)->assertSessionHasNoErrors();
        $this->post($url, $d)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('patient_access_rules', 2);
        $this->actingAs($this->nurse)->getJson('/patient-records/'.$this->patient->id.'/summary')->assertOk();
        $this->actingAs(User::factory()->create())->post($url, $d)->assertNotFound();
    }

    public function test_role_changes_and_cross_tenant_rules_do_not_create_clinical_access(): void
    {
        $this->rule('assignment', 'all');
        $this->rule();
        $url = '/patient-records/'.$this->patient->id.'/summary';
        $this->actingAs($this->nurse)->getJson($url)->assertOk();
        $this->nurse->memberships()->update(['role' => 'carer']);
        $this->getJson($url)->assertForbidden();
        $this->nurse->memberships()->update(['role' => 'nurse', 'organisation_id' => Organisation::create(['name' => 'Another service'])->id]);
        $this->getJson($url)->assertNotFound();
    }

    public function test_staff_cannot_create_assignments_without_reviewed_credentials_and_family_claim_alone_is_not_a_grant(): void
    {
        $d = ['request_id' => (string) Str::uuid(), 'user_id' => $this->admin->id, 'kind' => 'assignment', 'scope' => 'all', 'role' => 'nurse', 'purpose' => 'Synthetic care', 'authority_type' => 'care_assignment', 'evidence' => 'Synthetic task assignment', 'wording_version' => 'v1', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String(), 'attested' => true];
        $url = '/patient-records/'.$this->patient->id.'/access';
        $this->post($url, $d)->assertSessionHasErrors('role');
        $d['user_id'] = $this->nurse->id;
        $this->post($url, $d)->assertSessionHasNoErrors();
        $this->post($url, [...$d, 'request_id' => (string) Str::uuid(), 'kind' => 'grant', 'scope' => 'summary.read', 'authority_type' => 'family'])->assertSessionHasErrors('authority_type');
    }
}
