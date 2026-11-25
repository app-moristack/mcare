<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ChecklistTemplate;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VisitChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private User $nurse;

    private Organisation $organisation;

    private ChecklistTemplate $catalogue;

    private Appointment $attachedVisit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->organisation = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
        $this->catalogue = ChecklistTemplate::where('organisation_id', $this->organisation->id)->firstOrFail();
        $this->attachedVisit = Appointment::where('service', 'Demo visit with operational checklist')->firstOrFail();
    }

    private function workspace(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->organisation->id]);
    }

    private function attachment(Appointment $visit, ?int $versionId = null): array
    {
        return [
            'request_id' => Str::uuid()->toString(), 'visit_version' => $visit->version,
            'checklist_version_id' => $versionId ?? $this->catalogue->currentPublication()->firstOrFail()->id,
        ];
    }

    private function attendance(Appointment $visit, string $kind): void
    {
        $this->workspace($this->nurse)->post('/my-visits/'.$visit->id.'/attendance', [
            'kind' => $kind, 'request_id' => Str::uuid()->toString(), 'capture_status' => 'obtained',
            'latitude' => 1, 'longitude' => 2, 'accuracy' => 5,
        ])->assertSessionHasNoErrors();
    }

    public function test_only_current_same_tenant_publication_can_be_attached_once_and_later_versions_do_not_change_the_snapshot(): void
    {
        $visit = Appointment::where('organisation_id', $this->organisation->id)->where('status', 'booked')->whereDoesntHave('checklist')->firstOrFail();
        $url = '/workspace/appointments/'.$visit->id.'/checklist';
        $current = $this->catalogue->currentPublication()->firstOrFail();
        $previous = $this->catalogue->versions()->where('version', 1)->firstOrFail();
        $draft = $this->catalogue->versions()->where('status', 'draft')->firstOrFail();
        $payload = $this->attachment($visit);
        $this->workspace($this->nurse)->postJson($url, $payload)->assertForbidden();
        $this->workspace($this->coordinator)->get('/workspace/appointments?record='.$visit->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.data.0.checklist', null)
                ->where('lookups.checklists', [['id' => $current->id, 'title' => $this->catalogue->title, 'version' => $current->version]]));
        $this->postJson($url, [...$payload, 'visit_version' => $visit->version + 1])->assertConflict();
        $this->postJson($url, [...$payload, 'checklist_version_id' => $previous->id])->assertUnprocessable()->assertJsonValidationErrors('checklist_version_id');
        $this->postJson($url, [...$payload, 'checklist_version_id' => $draft->id])->assertUnprocessable()->assertJsonValidationErrors('checklist_version_id');

        $foreignOrg = Organisation::create(['name' => 'Synthetic foreign visit space', 'type' => 'care_service', 'currency' => 'MUR', 'time_zone' => 'Indian/Mauritius']);
        $foreignTemplate = ChecklistTemplate::create(['organisation_id' => $foreignOrg->id, 'title' => 'Foreign private synthetic checklist', 'created_by' => $this->coordinator->id]);
        $foreignVersion = $foreignTemplate->versions()->create(['version' => 1, 'status' => 'published', 'steps' => ['Synthetic foreign step.'], 'created_by' => $this->coordinator->id]);
        $this->postJson($url, [...$payload, 'checklist_version_id' => $foreignVersion->id])->assertNotFound();

        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $snapshot = $visit->checklist()->firstOrFail();
        $this->assertSame($this->coordinator->id, $snapshot->attached_by);
        $this->assertSame($current->content_hash, $snapshot->source_hash);
        $this->assertSame($visit->version + 1, $visit->fresh()->version);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'visit.checklist.attached')->where('resource_id', $visit->id)->count());

        $this->post('/workspace/checklists/'.$this->catalogue->id.'/versions/'.$draft->id.'/publish', ['password' => DemoDataSeeder::PASSWORD, 'declaration' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame($draft->id, $this->catalogue->currentPublication()->firstOrFail()->id);
        $this->postJson($url, $this->attachment($visit->fresh(), $draft->id))->assertConflict();
        $this->assertSame($current->id, $snapshot->fresh()->checklist_version_id);
        $this->assertSame($current->steps, $snapshot->fresh()->steps);
    }

    public function test_provider_signs_all_snapshot_steps_with_exceptions_and_only_then_can_staff_complete_the_visit(): void
    {
        $visit = $this->attachedVisit;
        $snapshot = $visit->checklist()->firstOrFail();
        $submit = '/my-visits/'.$visit->id.'/checklist';
        $transition = '/workspace/appointments/'.$visit->id;
        $responses = array_fill(0, count($snapshot->steps), ['status' => 'completed', 'reason' => null]);
        $responses[0] = ['status' => 'exception', 'reason' => 'Synthetic operational exception.'];
        $signature = [
            'request_id' => Str::uuid()->toString(), 'visit_version' => $visit->version,
            'responses' => $responses, 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true,
        ];
        $doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $this->workspace($doctor)->postJson($submit, $signature)->assertForbidden();
        $this->workspace($this->nurse)->postJson($submit, $signature)->assertConflict();
        $this->attendance($visit, 'arrival');
        $this->attendance($visit, 'departure');
        $arrived = $visit->fresh();
        $this->get('/my-visits')->assertInertia(fn (Assert $page) => $page->where('visits.data', fn ($items) => collect($items)->firstWhere('id', $visit->id)['checklist']['can_submit'] === true));
        $this->postJson($submit, $signature)->assertConflict();
        $signature['visit_version'] = $arrived->version;
        $this->postJson($submit, [...$signature, 'responses' => [['status' => 'exception']]])
            ->assertUnprocessable()->assertJsonValidationErrors('responses.0.reason');
        $this->postJson($submit, [...$signature, 'responses' => array_slice($responses, 0, 1)])
            ->assertUnprocessable()->assertJsonValidationErrors('responses');

        $complete = ['status' => 'completed', 'version' => $arrived->version, 'completion_evidence' => 'Synthetic visit completed.'];
        $this->workspace($this->coordinator)->patchJson($transition, $complete)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->workspace($this->nurse)->post($submit, $signature)->assertSessionHasNoErrors();
        $signed = $snapshot->fresh();
        $this->post($submit, $signature)->assertSessionHasNoErrors();
        $this->postJson($submit, [...$signature, 'request_id' => Str::uuid()->toString()])->assertConflict();
        $this->assertSame($this->nurse->id, $signed->submitted_by);
        $this->assertSame($responses, $signed->responses);
        $this->assertSame($signed->digest($responses, $this->nurse->id), $signed->submission_hash);
        $this->assertStringNotContainsString($responses[0]['reason'], $signed->getRawOriginal('responses'));
        $this->assertSame($arrived->version + 1, $visit->fresh()->version);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'visit.checklist.signed')->where('resource_id', $visit->id)->count());
        $this->workspace($this->coordinator)->patch($transition, [...$complete, 'version' => $visit->fresh()->version])->assertSessionHasNoErrors();
        $this->assertSame('completed', $visit->fresh()->status);
    }

    public function test_unbound_visits_can_still_complete_and_attached_visits_can_cancel_or_abort(): void
    {
        $attached = $this->attachedVisit;
        $this->workspace($this->coordinator)->patch('/workspace/appointments/'.$attached->id, [
            'status' => 'cancelled', 'version' => $attached->version, 'cancellation_reason' => 'Synthetic cancellation.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $attached->fresh()->status);

        [$abortable, $legacy] = Appointment::where('organisation_id', $this->organisation->id)
            ->where('provider_id', $this->nurse->id)->where('status', 'confirmed')->whereDoesntHave('checklist')->limit(2)->get()->all();
        $this->post('/workspace/appointments/'.$abortable->id.'/checklist', $this->attachment($abortable))->assertSessionHasNoErrors();
        $this->attendance($abortable, 'arrival');
        $this->workspace($this->coordinator)->patch('/workspace/appointments/'.$abortable->id, [
            'status' => 'aborted', 'version' => $abortable->fresh()->version, 'cancellation_reason' => 'Synthetic visit aborted.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('aborted', $abortable->fresh()->status);

        $this->attendance($legacy, 'arrival');
        $this->attendance($legacy, 'departure');
        $this->workspace($this->coordinator)->patch('/workspace/appointments/'.$legacy->id, [
            'status' => 'completed', 'version' => $legacy->fresh()->version, 'completion_evidence' => 'Synthetic legacy visit completed.',
        ])->assertSessionHasNoErrors();
        $this->assertSame('completed', $legacy->fresh()->status);
    }
}
