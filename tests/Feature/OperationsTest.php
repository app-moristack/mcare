<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\CareTask;
use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\Referral;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $backup;

    private Organisation $org;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mauricare.workforce_mfa_required' => false]);
        $this->seed(AdminUserSeeder::class);
        $this->admin = User::where('email', 'admin@mauricare.mu')->firstOrFail();
        $this->org = Organisation::first();
        $this->backup = User::factory()->create();
        $this->backup->memberships()->create(['organisation_id' => $this->org->id, 'role' => 'coordinator', 'status' => 'active', 'approved_by' => $this->admin->id, 'starts_at' => now()]);
        $this->actingAs($this->admin);
    }

    private function patient(?int $org = null): Patient
    {
        return Patient::create(['organisation_id' => $org ?? $this->org->id, 'reference' => (string) Str::uuid(), 'name' => 'Synthetic Patient', 'date_of_birth' => '1970-01-02', 'locality' => 'Port Louis', 'authority_type' => 'patient_request', 'authority_evidence' => 'Synthetic coordination request', 'request_id' => (string) Str::uuid()]);
    }

    private function referral(Patient $patient): Referral
    {
        return Referral::create(['organisation_id' => $patient->organisation_id, 'patient_id' => $patient->id, 'source' => 'patient', 'reason' => 'Synthetic care request', 'discipline' => 'nurse', 'priority' => 'routine', 'status' => 'awaiting_match', 'triage_evidence' => 'Reviewed by test clinician', 'owner_id' => $this->admin->id, 'backup_id' => $this->backup->id, 'due_at' => now()->addDay(), 'request_id' => (string) Str::uuid()]);
    }

    public function test_mfa_is_optional_when_disabled_and_required_when_enabled(): void
    {
        $this->get('/workspace/overview')->assertOk();
        config(['mauricare.workforce_mfa_required' => true]);
        $this->get('/workspace/overview')->assertRedirect('/security/mfa');
    }

    public function test_optional_mfa_setup_requires_password_and_rejects_replayed_codes(): void
    {
        $this->post('/security/mfa/setup', ['password' => 'incorrect'])->assertSessionHasErrors('password');
        $this->post('/security/mfa/setup', ['password' => 'P@ssw0rd'])->assertRedirect()->assertSessionHasNoErrors();
        $secret = session('mfa_setup_secret');
        $this->assertNotEmpty($secret);
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->post('/security/mfa/verify', ['code' => $code])->assertRedirect()->assertSessionHasNoErrors()->assertRedirect('/workspace/overview');
        $this->assertNotNull($this->admin->fresh()->mfa_confirmed_at);
        $this->assertNotSame($secret, DB::table('users')->where('id', $this->admin->id)->value('mfa_secret'));
        $this->post('/security/mfa/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertArrayNotHasKey('mfa_secret', $this->admin->fresh()->toArray());
    }

    public function test_tenant_boundary_and_public_role_isolation(): void
    {
        $other = Organisation::create(['name' => 'Other care']);
        $patient = $this->patient($other->id);
        $this->get('/workspace/patients')->assertInertia(fn (Assert $p) => $p->component('operations/index', false)->where('rows.total', 0));
        $this->patch('/workspace/patients/'.$patient->id, ['version' => 1, 'status' => 'inactive'])->assertNotFound();
        $this->withSession(['organisation_id' => $other->id])->get('/workspace/patients')->assertForbidden();
        $this->actingAs(User::factory()->create())->withSession(['organisation_id' => $this->org->id])->get('/workspace/patients')->assertForbidden();
    }

    public function test_patient_creation_is_idempotent_and_details_are_versioned(): void
    {
        $data = ['request_id' => (string) Str::uuid(), 'name' => 'Synthetic New', 'date_of_birth' => '1960-05-06', 'locality' => 'Curepipe', 'authority_type' => 'referral_authority', 'authority_evidence' => 'Verified synthetic source'];
        $this->post('/workspace/patients', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/workspace/patients', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('patients', 1);
        $p = Patient::first();
        $this->patch('/workspace/patients/'.$p->id, [...$data, 'action' => 'details', 'version' => 1, 'name' => 'Updated Name'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated Name', $p->fresh()->name);
        $this->assertSame($p->reference, $p->fresh()->reference);
        $this->patch('/workspace/patients/'.$p->id, ['version' => 1, 'status' => 'inactive'])->assertConflict();
    }

    public function test_referral_has_owners_and_clinical_triage_gate(): void
    {
        $p = $this->patient();
        $data = ['request_id' => (string) Str::uuid(), 'patient_id' => $p->id, 'source' => 'patient', 'reason' => 'Synthetic assistance', 'discipline' => 'nurse', 'priority' => 'unknown', 'owner_id' => $this->admin->id, 'backup_id' => $this->admin->id, 'due_at' => '2030-01-01T10:00'];
        $this->post('/workspace/referrals', $data)->assertSessionHasErrors('backup_id');
        $data['backup_id'] = $this->backup->id;
        $this->post('/workspace/referrals', $data)->assertRedirect()->assertSessionHasNoErrors();
        $ref = Referral::first();
        $this->patch('/workspace/referrals/'.$ref->id, ['version' => 1, 'status' => 'awaiting_match', 'triage_evidence' => 'Reviewed', 'priority' => 'routine'])->assertSessionHasErrors('status');
        $this->patch('/workspace/referrals/'.$ref->id, ['version' => 1, 'status' => 'review'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->backup)->patch('/workspace/referrals/'.$ref->id, ['version' => 2, 'status' => 'awaiting_match', 'triage_evidence' => 'Reviewed', 'priority' => 'routine'])->assertForbidden();
        $this->actingAs($this->admin)->patch('/workspace/referrals/'.$ref->id, ['version' => 2, 'status' => 'awaiting_match', 'triage_evidence' => 'Reviewed by authorised triager', 'priority' => 'routine'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_appointments_reject_conflicts_and_record_arrival_departure_without_gps(): void
    {
        $p = $this->patient();
        $ref = $this->referral($p);
        $data = ['request_id' => (string) Str::uuid(), 'patient_id' => $p->id, 'referral_id' => $ref->id, 'provider_id' => $this->admin->id, 'service' => 'Synthetic assessment', 'starts_at' => '2030-01-01T10:00', 'ends_at' => '2030-01-01T11:00', 'location_type' => 'home', 'location' => 'Synthetic address'];
        $this->post('/workspace/appointments', $data)->assertRedirect()->assertSessionHasNoErrors();
        $visit = Appointment::first();
        $this->assertSame('06:00', $visit->starts_at->format('H:i'));
        $this->post('/workspace/appointments', [...$data, 'request_id' => (string) Str::uuid()])->assertSessionHasErrors('starts_at');
        $this->post('/workspace/appointments', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('appointments', 1);
        $event = ['request_id' => (string) Str::uuid(), 'kind' => 'departure', 'capture_status' => 'denied', 'exception_reason' => 'Location permission denied in test'];
        $this->post('/my-visits/'.$visit->id.'/attendance', $event)->assertSessionHasErrors('kind');
        $this->patch('/workspace/appointments/'.$visit->id, ['version' => 1, 'status' => 'confirmed', 'confirmation_evidence' => 'Synthetic patient and provider confirmed by coordinator'])->assertRedirect()->assertSessionHasNoErrors();
        $event['kind'] = 'arrival';
        $this->post('/my-visits/'.$visit->id.'/attendance', $event)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/my-visits/'.$visit->id.'/attendance', $event)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendance_events', 1);
        $this->patch('/workspace/appointments/'.$visit->id, ['version' => 3, 'status' => 'completed', 'completion_evidence' => 'Verified visit completion'])->assertSessionHasErrors('status');
        $this->post('/my-visits/'.$visit->id.'/attendance', [...$event, 'kind' => 'departure', 'request_id' => (string) Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        $this->patch('/workspace/appointments/'.$visit->id, ['version' => 3, 'status' => 'completed', 'completion_evidence' => 'Verified operational completion; clinical review separate'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', $visit->fresh()->status);
        $this->assertDatabaseCount('attendance_events', 2);
    }

    public function test_only_assigned_provider_can_clock_an_appointment(): void
    {
        $p = $this->patient();
        $ref = $this->referral($p);
        $visit = Appointment::create(['organisation_id' => $this->org->id, 'patient_id' => $p->id, 'referral_id' => $ref->id, 'provider_id' => $this->admin->id, 'service' => 'Test', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'location_type' => 'home', 'location' => 'Synthetic', 'request_id' => (string) Str::uuid(), 'status' => 'confirmed']);
        $this->actingAs($this->backup)->post('/my-visits/'.$visit->id.'/attendance', ['request_id' => (string) Str::uuid(), 'kind' => 'arrival', 'capture_status' => 'unavailable', 'exception_reason' => 'No GPS'])->assertForbidden();
        $this->assertDatabaseCount('attendance_events', 0);
    }

    public function test_task_waiting_completion_and_escalation_are_evidenced_and_idempotent(): void
    {
        $data = ['request_id' => (string) Str::uuid(), 'title' => 'Synthetic callback', 'description' => 'Call the synthetic contact', 'priority' => 'high', 'owner_id' => $this->admin->id, 'backup_id' => $this->backup->id, 'due_at' => '2020-01-01T10:00'];
        $this->post('/workspace/tasks', $data)->assertRedirect()->assertSessionHasNoErrors();
        $task = CareTask::first();
        $this->artisan('mauricare:escalate-tasks')->assertExitCode(0);
        $this->artisan('mauricare:escalate-tasks')->assertExitCode(0);
        $this->assertDatabaseCount('operation_notifications', 2);
        $this->patch('/workspace/tasks/'.$task->id, ['version' => 1, 'status' => 'acknowledged'])->assertRedirect()->assertSessionHasNoErrors();
        $this->patch('/workspace/tasks/'.$task->id, ['version' => 2, 'status' => 'waiting'])->assertSessionHasErrors(['waiting_reason', 'next_check_at']);
        $this->patch('/workspace/tasks/'.$task->id, ['version' => 2, 'status' => 'in_progress'])->assertRedirect()->assertSessionHasNoErrors();
        $this->patch('/workspace/tasks/'.$task->id, ['version' => 3, 'status' => 'completed'])->assertSessionHasErrors('completion_evidence');
        $this->patch('/workspace/tasks/'.$task->id, ['version' => 3, 'status' => 'completed', 'completion_evidence' => 'Synthetic callback completed and response recorded'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_invoices_compute_amounts_and_only_reconciled_payments_settle_them(): void
    {
        $p = $this->patient();
        $data = ['request_id' => (string) Str::uuid(), 'patient_id' => $p->id, 'due_date' => '2030-01-10', 'total_minor' => 1, 'status' => 'paid', 'items' => [['description' => 'Synthetic assessment', 'quantity' => 2, 'unit_price_minor' => 150000]]];
        $this->post('/workspace/invoices', $data)->assertRedirect()->assertSessionHasNoErrors();
        $invoice = Invoice::first();
        $this->assertSame(300000, $invoice->total_minor);
        $this->assertSame('draft', $invoice->status);
        $this->patch('/workspace/invoices/'.$invoice->id, ['version' => 1, 'status' => 'paid'])->assertSessionHasErrors('status');
        $this->patch('/workspace/invoices/'.$invoice->id, ['version' => 1, 'status' => 'issued'])->assertRedirect()->assertSessionHasNoErrors();
        $payment = ['request_id' => (string) Str::uuid(), 'amount_minor' => 100000, 'reference' => 'TEST-001', 'evidence' => 'Verified synthetic bank settlement'];
        $this->post('/workspace/invoices/'.$invoice->id.'/payments', $payment)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/workspace/invoices/'.$invoice->id.'/payments', $payment)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('part_paid', $invoice->fresh()->status);
        $this->post('/workspace/invoices/'.$invoice->id.'/payments', [...$payment, 'request_id' => (string) Str::uuid(), 'reference' => 'TEST-002', 'amount_minor' => 300000])->assertSessionHasErrors('amount_minor');
        $this->post('/workspace/invoices/'.$invoice->id.'/payments', [...$payment, 'request_id' => (string) Str::uuid(), 'reference' => 'TEST-003', 'amount_minor' => 200000])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_provider_approval_requires_current_credential_but_not_disabled_mfa(): void
    {
        $user = User::factory()->create(['account_kind' => 'provider_application']);
        $app = $user->application()->create(['kind' => 'provider_application', 'organisation_id' => $this->org->id, 'discipline' => 'nurse', 'credential_reference' => 'SYNTHETIC-REG', 'credential_expires_at' => '2020-01-01']);
        $this->patch('/workspace/team/applications/'.$app->id, ['status' => 'approved', 'review_evidence' => 'Reviewed synthetic credential'])->assertSessionHasErrors('status');
        $app->update(['credential_expires_at' => '2030-01-01']);
        $this->patch('/workspace/team/applications/'.$app->id, ['status' => 'approved', 'review_evidence' => 'Reviewed synthetic credential'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'role' => 'nurse', 'status' => 'active']);
        $this->assertFalse($user->hasPermission('patients.manage', $this->org->id));
    }

    public function test_patient_portal_does_not_link_on_email_or_expose_other_records(): void
    {
        $patient = $this->patient();
        $user = User::factory()->create();
        $user->patientProfile()->create(['public_id' => (string) Str::uuid(), 'legal_name' => 'My Synthetic Name', 'date_of_birth' => '1980-01-01', 'locality' => 'Test', 'onboarding_completed_at' => now()]);
        $this->actingAs($user)->get('/my-care')->assertInertia(fn (Assert $p) => $p->component('care/portal', false)->has('patients', 0));
        $this->post('/my-care/request', ['organisation_id' => $this->org->id, 'authority_acceptance' => false])->assertSessionHasErrors('authority_acceptance');
        $this->post('/my-care/request', ['organisation_id' => $this->org->id, 'authority_acceptance' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/my-care/request', ['organisation_id' => $this->org->id, 'authority_acceptance' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('patients', 2);
        $this->assertDatabaseHas('patients', ['user_id' => $user->id, 'name' => 'My Synthetic Name']);
    }

    public function test_operational_staff_membership_is_scoped_and_does_not_grant_clinical_roles(): void
    {
        $user = User::factory()->create();
        $this->post('/workspace/team/memberships', ['email' => $user->email, 'role' => 'doctor', 'approval_evidence' => 'Test approval'])->assertSessionHasErrors('role');
        $this->post('/workspace/team/memberships', ['email' => $user->email, 'role' => 'coordinator', 'approval_evidence' => 'Test approved coordination duties'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($user->hasPermission('patients.manage', $this->org->id));
        $this->assertFalse($user->hasPermission('finance.manage', $this->org->id));
        $this->assertDatabaseHas('memberships', ['user_id' => $user->id, 'role' => 'coordinator', 'approval_evidence' => 'Test approved coordination duties']);
    }
}
