<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClinicalObservation;
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

class ClinicalObservationTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    private Patient $patient;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->patient = Patient::whereNotNull('user_id')->firstOrFail();
        $this->organisation = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->organisation->id]);
    }

    private function url(): string
    {
        return '/patient-records/'.$this->patient->id.'/observations';
    }

    private function payload(array $extra = []): array
    {
        return [...[
            'request_id' => (string) Str::uuid(),
            'appointment_id' => Appointment::where('patient_id', $this->patient->id)->where('provider_id', $this->nurse->id)->firstOrFail()->id,
            'observed_at' => now('Indian/Mauritius')->subMinute()->format('Y-m-d\TH:i'),
            'measurements' => [['type' => 'temperature', 'value' => '36.8', 'method' => 'Synthetic test device context']],
            'context' => 'Synthetic test only',
            'password' => DemoDataSeeder::PASSWORD,
            'declaration' => true,
        ], ...$extra];
    }

    public function test_authorised_clinician_can_view_and_sign_immutable_structured_observations(): void
    {
        $this->as($this->nurse)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('observations/index', false)->where('canWrite', true)->has('rows', 3));
        $before = ClinicalObservation::count();
        $payload = $this->payload();
        $this->post($this->url(), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before + 1, ClinicalObservation::count());
        $record = ClinicalObservation::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame($record->digest(), $record->content_hash);
        $this->assertSame('°C', $record->measurements[0]['unit']);
        $this->assertStringNotContainsString('Synthetic test', DB::table('clinical_observations')->where('id', $record->id)->value('measurements'));
        $this->assertStringNotContainsString('Synthetic test', DB::table('clinical_observations')->where('id', $record->id)->value('context'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->nurse->id, 'event' => 'patient.observations.recorded', 'resource_id' => $this->patient->id]);
    }

    public function test_recording_is_idempotent_and_rejects_stale_authority_wrong_visit_and_future_time(): void
    {
        $payload = $this->payload();
        $this->as($this->nurse)->post($this->url(), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $count = ClinicalObservation::count();
        $this->post($this->url(), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($count, ClinicalObservation::count());
        $this->post($this->url(), [...$payload, 'context' => 'Conflicting retry content'])->assertStatus(409);
        $this->post($this->url(), $this->payload(['observed_at' => now('Indian/Mauritius')->addHour()->format('Y-m-d\TH:i')]))->assertSessionHasErrors('observed_at');
        $this->post($this->url(), $this->payload(['appointment_id' => 999999]))->assertNotFound();
        PatientAccessRule::where('patient_id', $this->patient->id)->where('user_id', $this->nurse->id)->where('scope', 'observations.write')->update(['revoked_at' => now()]);
        $this->post($this->url(), $this->payload())->assertForbidden();
    }

    public function test_blood_pressure_pair_password_and_append_only_correction_are_enforced(): void
    {
        $this->as($this->nurse)->post($this->url(), $this->payload(['password' => 'wrong']))->assertSessionHasErrors('password');
        $this->post($this->url(), $this->payload(['measurements' => [['type' => 'blood_pressure_systolic', 'value' => '120', 'method' => 'Synthetic']]]))->assertSessionHasErrors('measurements');
        $originalPayload = $this->payload(['context' => 'Original retained context']);
        $this->post($this->url(), $originalPayload)->assertRedirect()->assertSessionHasNoErrors();
        $original = ClinicalObservation::where('request_id', $originalPayload['request_id'])->firstOrFail();
        $originalHash = $original->content_hash;
        $this->post($this->url(), $this->payload(['supersedes_id' => $original->id]))->assertSessionHasErrors('correction_reason');
        $correctionPayload = $this->payload([
            'supersedes_id' => $original->id,
            'correction_reason' => 'Synthetic transcription correction',
            'measurements' => [['type' => 'temperature', 'value' => '36.9', 'method' => 'Synthetic corrected value']],
        ]);
        $this->post($this->url(), $correctionPayload)->assertRedirect()->assertSessionHasNoErrors();
        $correction = ClinicalObservation::where('request_id', $correctionPayload['request_id'])->firstOrFail();
        $this->assertSame($original->id, $correction->supersedes_id);
        $this->assertSame('36.9', $correction->measurements[0]['value']);
        $this->assertSame($originalHash, $original->fresh()->content_hash);
        $this->assertSame('Original retained context', $original->fresh()->context);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->nurse->id, 'event' => 'patient.observations.corrected', 'resource_id' => $this->patient->id]);
        $this->post($this->url(), $this->payload(['supersedes_id' => $original->id, 'correction_reason' => 'Synthetic second correction']))->assertStatus(409);
    }

    public function test_patient_operational_staff_other_tenant_and_wrong_discipline_cannot_read_private_observations(): void
    {
        $patientUser = User::findOrFail($this->patient->user_id);
        $coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $physio = User::where('email', 'demo.physio@example.test')->firstOrFail();
        $doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $this->as($doctor)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->where('canWrite', false)->has('rows', 3));
        $this->post($this->url(), $this->payload())->assertForbidden();
        $this->as($patientUser)->get($this->url())->assertNotFound();
        $this->as($coordinator)->get($this->url())->assertForbidden();
        $this->as($physio)->get($this->url())->assertForbidden();
        $other = Organisation::create(['name' => 'Other']);
        $this->as($this->nurse)->withSession(['organisation_id' => $other->id])->get($this->url())->assertNotFound();
    }
}
