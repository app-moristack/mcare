<?php

namespace Tests\Feature;

use App\Models\CareTask;
use App\Models\Investigation;
use App\Models\InvestigationPublication;
use App\Models\InvestigationResult;
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

class InvestigationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $nurse;

    private User $doctor;

    private User $lead;

    private User $coordinator;

    private User $patientUser;

    private Patient $patient;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $this->lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
        $this->coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $this->patientUser = User::where('email', 'demo.patient@example.test')->firstOrFail();
        $this->patient = Patient::where('user_id', $this->patientUser->id)->firstOrFail();
        $this->organisation = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->organisation->id]);
    }

    private function url(string $suffix = ''): string
    {
        return '/patient-records/'.$this->patient->id.'/investigations'.$suffix;
    }

    private function signature(): array
    {
        return ['password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
    }

    private function requestPayload(array $extra = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'requested_at' => now('Indian/Mauritius')->subMinutes(10)->format('Y-m-d\TH:i'), 'category' => 'laboratory', 'name' => 'Synthetic requested investigation', 'clinical_question' => 'Synthetic clinical question', 'destination' => 'Synthetic external provider', 'source_reference' => 'TEST-NOT-AN-ORDER', ...$this->signature()], ...$extra];
    }

    private function resultPayload(array $extra = []): array
    {
        return [...['request_id' => (string) Str::uuid(), 'investigation_id' => Investigation::firstOrFail()->id, 'received_at' => now('Indian/Mauritius')->subMinute()->format('Y-m-d\TH:i'), 'source_reported_at' => now('Indian/Mauritius')->subMinutes(5)->format('Y-m-d\TH:i'), 'source_flag' => 'routine', 'result_type' => 'final', 'source_reference' => 'TEST-NOT-A-RESULT', 'summary' => 'Synthetic source result payload', 'source_comment' => 'Synthetic source comment', 'values' => [['label' => 'Synthetic label', 'value' => 'Synthetic value', 'unit' => 'demo']], 'owner_id' => $this->doctor->id, 'backup_id' => $this->coordinator->id, 'due_at' => now('Indian/Mauritius')->addDay()->format('Y-m-d\TH:i'), ...$this->signature()], ...$extra];
    }

    private function pendingResult(array $extra = []): InvestigationResult
    {
        $payload = $this->resultPayload($extra);
        $this->as($this->nurse)->post($this->url('/results'), $payload)->assertRedirect()->assertSessionHasNoErrors();

        return InvestigationResult::where('request_id', $payload['request_id'])->firstOrFail();
    }

    public function test_role_specific_workspace_and_signed_encrypted_request(): void
    {
        $this->as($this->doctor)->get($this->url())->assertOk()->assertInertia(fn (Assert $page) => $page->component('investigations/index', false)->where('canRequest', true)->where('canReceive', false)->where('canAcknowledge', true)->where('canPublish', false)->has('investigations', 1)->has('results', 1));
        $this->as($this->nurse)->get($this->url())->assertInertia(fn (Assert $page) => $page->where('canRequest', false)->where('canReceive', true)->where('canAcknowledge', false));
        $payload = $this->requestPayload();
        $this->as($this->doctor)->post($this->url(), $payload)->assertSessionHasNoErrors();
        $record = Investigation::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame($record->digest(), $record->content_hash);
        $this->assertStringNotContainsString('Synthetic clinical question', DB::table('investigations')->where('id', $record->id)->value('details'));
        $count = Investigation::count();
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();
        $this->assertSame($count, Investigation::count());
        $this->post($this->url(), [...$payload, 'name' => 'Conflicting retry'])->assertConflict();
    }

    public function test_receipt_assigns_owner_backup_task_notifications_and_encrypted_source_content(): void
    {
        $payload = $this->resultPayload();
        $this->as($this->nurse)->post($this->url('/results'), $payload)->assertSessionHasNoErrors();
        $result = InvestigationResult::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame($result->digest(), $result->content_hash);
        $this->assertSame($this->doctor->id, $result->owner_id);
        $task = CareTask::findOrFail($result->task_id);
        $this->assertSame($this->doctor->id, $task->owner_id);
        $this->assertSame($this->coordinator->id, $task->backup_id);
        $this->assertStringNotContainsString('Synthetic source result payload', $task->description);
        $this->assertStringNotContainsString('Synthetic source result payload', DB::table('investigation_results')->where('id', $result->id)->value('details'));
        $this->assertDatabaseHas('operation_notifications', ['user_id' => $this->doctor->id, 'deduplication_key' => 'result-received:'.$result->id.':'.$this->doctor->id]);
        $this->assertDatabaseHas('operation_notifications', ['user_id' => $this->coordinator->id, 'deduplication_key' => 'result-received:'.$result->id.':'.$this->coordinator->id]);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->nurse->id, 'event' => 'patient.result.received']);
        $counts = [InvestigationResult::count(), CareTask::count(), DB::table('operation_notifications')->count()];
        $this->post($this->url('/results'), $payload)->assertSessionHasNoErrors();
        $this->assertSame($counts, [InvestigationResult::count(), CareTask::count(), DB::table('operation_notifications')->count()]);
        $this->post($this->url('/results'), [...$payload, 'summary' => 'Conflicting source retry'])->assertConflict();
    }

    public function test_source_flagged_critical_requires_completed_external_escalation_evidence(): void
    {
        $this->as($this->nurse)->post($this->url('/results'), $this->resultPayload(['source_flag' => 'critical']))->assertSessionHasErrors('escalation_evidence');
        $result = $this->pendingResult(['source_flag' => 'critical', 'escalated_at' => now('Indian/Mauritius')->subMinutes(2)->format('Y-m-d\TH:i'), 'escalation_channel' => 'Synthetic direct telephone escalation', 'escalation_evidence' => 'Synthetic recipient and read-back evidence.']);
        $this->assertSame('critical', $result->source_flag);
        $this->assertSame('high', CareTask::findOrFail($result->task_id)->priority);
        $this->assertStringContainsString('external escalation recorded', DB::table('operation_notifications')->where('deduplication_key', 'result-received:'.$result->id.':'.$this->doctor->id)->value('message'));
    }

    public function test_only_named_doctor_owner_acknowledges_and_completes_accountable_follow_up(): void
    {
        $result = $this->pendingResult();
        $ack = ['version' => 1, 'review_summary' => 'Synthetic doctor review', 'patient_communication' => 'completed', 'communication_evidence' => 'Synthetic test communication evidence', 'follow_up_required' => 'yes', 'follow_up_plan' => 'Synthetic follow-up plan', 'follow_up_due' => now('Indian/Mauritius')->addDay()->format('Y-m-d\TH:i'), ...$this->signature()];
        $this->as($this->lead)->post($this->url('/results/'.$result->id.'/acknowledge'), $ack)->assertForbidden();
        $this->as($this->doctor)->post($this->url('/results/'.$result->id.'/acknowledge'), [...$ack, 'patient_communication' => 'pending', 'follow_up_required' => 'no', 'follow_up_plan' => '', 'follow_up_due' => ''])->assertSessionHasErrors('follow_up_required');
        $this->as($this->doctor)->post($this->url('/results/'.$result->id.'/acknowledge'), $ack)->assertSessionHasNoErrors();
        $this->assertSame('acknowledged', $result->fresh()->status);
        $this->assertSame('waiting', CareTask::findOrFail($result->task_id)->status);
        $complete = ['version' => 2, 'outcome' => 'Synthetic follow-up outcome', 'evidence' => 'Synthetic completion evidence', ...$this->signature()];
        $this->post($this->url('/results/'.$result->id.'/complete'), $complete)->assertSessionHasNoErrors();
        $this->assertSame('completed', $result->fresh()->status);
        $this->assertSame('completed', CareTask::findOrFail($result->task_id)->status);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->doctor->id, 'event' => 'patient.result.follow_up_completed']);
    }

    public function test_correction_retains_original_cancels_open_task_and_rejects_second_correction(): void
    {
        $original = $this->pendingResult(['summary' => 'Original synthetic source summary']);
        $originalHash = $original->content_hash;
        $corrected = $this->pendingResult(['investigation_id' => $original->investigation_id, 'supersedes_id' => $original->id, 'correction_reason' => 'Synthetic corrected source report', 'result_type' => 'corrected', 'summary' => 'Corrected synthetic source summary']);
        $this->assertSame($original->id, $corrected->supersedes_id);
        $this->assertSame($originalHash, $original->fresh()->content_hash);
        $this->assertSame('Original synthetic source summary', $original->fresh()->details['summary']);
        $this->assertSame('cancelled', CareTask::findOrFail($original->task_id)->status);
        $this->as($this->nurse)->post($this->url('/results'), $this->resultPayload(['investigation_id' => $original->investigation_id, 'supersedes_id' => $original->id, 'correction_reason' => 'Second correction']))->assertConflict();
    }

    public function test_lead_publishes_only_reviewed_plain_language_snapshot_to_linked_patient(): void
    {
        $result = $this->pendingResult();
        $acknowledgement = ['version' => 1, 'review_summary' => 'Synthetic review for publication', 'patient_communication' => 'completed', 'communication_evidence' => 'Synthetic communication evidence', 'follow_up_required' => 'no', 'follow_up_plan' => '', 'follow_up_due' => '', ...$this->signature()];
        $this->as($this->doctor)->post($this->url('/results/'.$result->id.'/acknowledge'), $acknowledgement)->assertSessionHasNoErrors();
        $result->refresh();
        $payload = ['request_id' => (string) Str::uuid(), 'summary' => 'Reviewed synthetic patient result summary.', 'next_steps' => 'Synthetic patient next step.', ...$this->signature()];
        $this->as($this->doctor)->post($this->url('/results/'.$result->id.'/publish'), $payload)->assertForbidden();
        $this->as($this->lead)->post($this->url('/results/'.$result->id.'/publish'), $payload)->assertSessionHasNoErrors();
        $publication = InvestigationPublication::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame($result->content_hash, $publication->source_hash);
        $this->assertStringNotContainsString($payload['summary'], DB::table('investigation_publications')->where('id', $publication->id)->value('content'));
        $response = $this->actingAs($this->patientUser)->get('/my-care/patients/'.$this->patient->id.'/clinical')->assertInertia(fn (Assert $page) => $page->where('items', fn ($items) => collect($items)->contains(fn ($item) => $item['kind'] === 'results' && $item['content']['summary'] === $payload['summary'])));
        $this->assertStringNotContainsString($result->details['summary'], $response->getContent());
        $this->assertStringNotContainsString('content_hash', $response->getContent());
    }

    public function test_patient_operational_staff_wrong_discipline_other_tenant_and_revoked_scope_are_denied(): void
    {
        $physio = User::where('email', 'demo.physio@example.test')->firstOrFail();
        $this->as($this->patientUser)->get($this->url())->assertNotFound();
        $this->as($this->coordinator)->get($this->url())->assertForbidden();
        $this->as($physio)->get($this->url())->assertForbidden();
        $other = Organisation::create(['name' => 'Foreign investigation tenant']);
        $this->as($this->nurse)->withSession(['organisation_id' => $other->id])->get($this->url())->assertNotFound();
        PatientAccessRule::where('patient_id', $this->patient->id)->where('user_id', $this->nurse->id)->where('scope', 'results.receive')->update(['revoked_at' => now()]);
        $this->as($this->nurse)->post($this->url('/results'), $this->resultPayload())->assertForbidden();
    }
}
