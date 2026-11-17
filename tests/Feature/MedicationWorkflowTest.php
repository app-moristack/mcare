<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\MedicationAdministration;
use App\Models\MedicationInstruction;
use App\Models\MedicationReconciliation;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MedicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    private User $doctor;

    private Patient $patient;

    private Organisation $organisation;

    private Appointment $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $this->patient = Patient::whereNotNull('user_id')->firstOrFail();
        $this->organisation = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
        $this->visit = Appointment::where('patient_id', $this->patient->id)->where('provider_id', $this->nurse->id)->whereIn('status', ['confirmed', 'arrived', 'completed'])->firstOrFail();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->organisation->id]);
    }

    private function url(string $suffix = ''): string
    {
        return '/patient-records/'.$this->patient->id.'/medications'.$suffix;
    }

    private function signature(): array
    {
        return ['password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
    }

    private function reconciliationPayload(array $extra = []): array
    {
        return [...[
            'request_id' => (string) Str::uuid(), 'appointment_id' => $this->visit->id,
            'reconciled_at' => now('Indian/Mauritius')->subMinute()->format('Y-m-d\TH:i'), 'status' => 'complete',
            'sources' => ['Synthetic patient interview'],
            'medicines' => [['name' => 'Synthetic test medicine', 'dose' => 'test dose', 'route' => 'oral', 'frequency' => 'test frequency', 'status' => 'taking']],
            'discrepancies' => 'Synthetic discrepancy', 'follow_up' => '',
            ...$this->signature(),
        ], ...$extra];
    }

    private function instructionPayload(array $extra = []): array
    {
        return [...[
            'request_id' => (string) Str::uuid(), 'verified_at' => now('Indian/Mauritius')->subMinute()->format('Y-m-d\TH:i'),
            'starts_at' => now('Indian/Mauritius')->subDay()->format('Y-m-d\TH:i'), 'ends_at' => now('Indian/Mauritius')->addDay()->format('Y-m-d\TH:i'), 'status' => 'active',
            'medicine' => 'Synthetic verified medicine', 'dose' => 'test dose', 'route' => 'oral', 'frequency' => 'once for test', 'instructions' => 'Synthetic only',
            'prescriber_name' => 'Synthetic External Doctor', 'prescriber_registration' => 'TEST-NOT-REAL', 'source_reference' => 'TEST-NOT-A-PRESCRIPTION', 'verification_evidence' => 'Synthetic source personally checked for this test.',
            ...$this->signature(),
        ], ...$extra];
    }

    private function administrationPayload(MedicationInstruction $instruction, array $extra = []): array
    {
        return [...[
            'request_id' => (string) Str::uuid(), 'medication_instruction_id' => $instruction->id, 'appointment_id' => $this->visit->id,
            'administered_at' => now('Indian/Mauritius')->subMinute()->format('Y-m-d\TH:i'), 'outcome' => 'administered', 'reason' => '', 'notes' => 'Synthetic administration test',
            ...$this->signature(),
        ], ...$extra];
    }

    public function test_role_specific_workspace_and_signed_encrypted_reconciliation(): void
    {
        $this->as($this->nurse)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('medications/index', false)->where('canReconcile', true)->where('canVerify', false)->where('canAdminister', true)->has('reconciliations', 1)->has('instructions', 1)->has('administrations', 1));
        $this->as($this->doctor)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canReconcile', true)->where('canVerify', true)->where('canAdminister', false));
        $payload = $this->reconciliationPayload();
        $this->as($this->nurse)->post($this->url('/reconciliations'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $record = MedicationReconciliation::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame($record->digest(), $record->content_hash);
        $raw = DB::table('medication_reconciliations')->where('id', $record->id)->first();
        $this->assertStringNotContainsString('Synthetic test medicine', $raw->medicines);
        $this->assertStringNotContainsString('Synthetic patient interview', $raw->sources);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->nurse->id, 'event' => 'patient.medications.reconciled', 'resource_id' => $this->patient->id]);
    }

    public function test_doctor_verifies_external_instruction_and_nurse_records_snapshot(): void
    {
        $payload = $this->instructionPayload();
        $this->as($this->doctor)->post($this->url('/instructions'), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $instruction = MedicationInstruction::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame($instruction->digest(), $instruction->content_hash);
        $this->assertStringNotContainsString('Synthetic verified medicine', DB::table('medication_instructions')->where('id', $instruction->id)->value('details'));
        $administration = $this->administrationPayload($instruction);
        $this->as($this->nurse)->post($this->url('/administrations'), $administration)->assertRedirect()->assertSessionHasNoErrors();
        $record = MedicationAdministration::where('request_id', $administration['request_id'])->firstOrFail();
        $this->assertSame($record->digest(), $record->content_hash);
        $this->assertSame($instruction->content_hash, $record->instruction_snapshot['instruction_hash']);
        $this->assertStringNotContainsString('Synthetic verified medicine', DB::table('medication_administrations')->where('id', $record->id)->value('instruction_snapshot'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->doctor->id, 'event' => 'patient.medications.instruction_verified']);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->nurse->id, 'event' => 'patient.medications.administration_recorded']);
    }

    public function test_role_boundaries_stale_authority_and_non_administration_reason_are_enforced(): void
    {
        $seeded = MedicationInstruction::firstOrFail();
        $this->as($this->nurse)->post($this->url('/instructions'), $this->instructionPayload())->assertForbidden();
        $this->as($this->doctor)->post($this->url('/administrations'), $this->administrationPayload($seeded))->assertForbidden();
        $this->as($this->nurse)->post($this->url('/administrations'), $this->administrationPayload($seeded, ['outcome' => 'refused']))->assertSessionHasErrors('reason');
        $this->post($this->url('/administrations'), $this->administrationPayload($seeded, ['administered_at' => now('Indian/Mauritius')->addHour()->format('Y-m-d\TH:i')]))->assertSessionHasErrors('administered_at');
        PatientAccessRule::where('patient_id', $this->patient->id)->where('user_id', $this->nurse->id)->where('scope', 'medications.administer')->update(['revoked_at' => now()]);
        $this->post($this->url('/administrations'), $this->administrationPayload($seeded))->assertForbidden();
    }

    public function test_idempotency_append_only_corrections_and_instruction_currency(): void
    {
        $originalPayload = $this->reconciliationPayload(['discrepancies' => 'Original retained discrepancy']);
        $this->as($this->nurse)->post($this->url('/reconciliations'), $originalPayload)->assertSessionHasNoErrors();
        $count = MedicationReconciliation::count();
        $this->post($this->url('/reconciliations'), $originalPayload)->assertSessionHasNoErrors();
        $this->assertSame($count, MedicationReconciliation::count());
        $this->post($this->url('/reconciliations'), [...$originalPayload, 'discrepancies' => 'Conflicting retry'])->assertConflict();
        $original = MedicationReconciliation::where('request_id', $originalPayload['request_id'])->firstOrFail();
        $correction = $this->reconciliationPayload(['supersedes_id' => $original->id, 'correction_reason' => 'Synthetic correction reason', 'discrepancies' => 'Corrected discrepancy']);
        $this->post($this->url('/reconciliations'), $correction)->assertSessionHasNoErrors();
        $this->assertSame('Original retained discrepancy', $original->fresh()->discrepancies);
        $this->post($this->url('/reconciliations'), $this->reconciliationPayload(['supersedes_id' => $original->id, 'correction_reason' => 'Second correction']))->assertConflict();

        $expiredPayload = $this->instructionPayload(['starts_at' => now('Indian/Mauritius')->subDays(2)->format('Y-m-d\TH:i'), 'ends_at' => now('Indian/Mauritius')->subDay()->format('Y-m-d\TH:i')]);
        $this->as($this->doctor)->post($this->url('/instructions'), $expiredPayload)->assertSessionHasNoErrors();
        $expired = MedicationInstruction::where('request_id', $expiredPayload['request_id'])->firstOrFail();
        $this->as($this->nurse)->post($this->url('/administrations'), $this->administrationPayload($expired))->assertSessionHasErrors('medication_instruction_id');
    }

    public function test_patient_operational_staff_wrong_discipline_and_other_tenant_cannot_read(): void
    {
        $patientUser = User::findOrFail($this->patient->user_id);
        $coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $physio = User::where('email', 'demo.physio@example.test')->firstOrFail();
        $this->as($patientUser)->get($this->url())->assertNotFound();
        $this->as($coordinator)->get($this->url())->assertForbidden();
        $this->as($physio)->get($this->url())->assertForbidden();
        $other = Organisation::create(['name' => 'Other medication tenant']);
        $this->as($this->nurse)->withSession(['organisation_id' => $other->id])->get($this->url())->assertNotFound();
    }
}
