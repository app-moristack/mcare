<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\CareTask;
use App\Models\MissedVisitCase;
use App\Models\Organisation;
use App\Models\Patient;
use App\Models\ProviderRosterEntry;
use App\Models\Referral;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RosterAndMissedVisitTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private User $nurse;

    private User $doctor;

    private User $lead;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->organisation = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $this->lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
        $this->coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->organisation->id]);
    }

    private function rosterPayload(array $extra = []): array
    {
        return [...[
            'request_id' => (string) Str::uuid(), 'kind' => 'availability',
            'starts_at' => now('Indian/Mauritius')->addMonths(14)->startOfDay()->format('Y-m-d\TH:i'),
            'ends_at' => now('Indian/Mauritius')->addMonths(14)->endOfDay()->format('Y-m-d\TH:i'),
            'locality' => 'Synthetic roster test', 'notes' => 'Synthetic availability only', 'declaration' => true,
        ], ...$extra];
    }

    public function test_provider_submits_encrypted_roster_and_independent_operations_review(): void
    {
        $payload = $this->rosterPayload();
        $this->as($this->nurse)->post('/my-roster', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $entry = ProviderRosterEntry::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame('pending', $entry->status);
        $this->assertStringNotContainsString('Synthetic availability only', DB::table('provider_roster_entries')->where('id', $entry->id)->value('notes'));
        $this->post('/workspace/roster/'.$entry->id.'/review', ['version' => 1, 'decision' => 'approve', 'review_evidence' => 'Self approval is forbidden.'])->assertForbidden();

        $this->as($this->coordinator)->post('/workspace/roster/'.$entry->id.'/review', ['version' => 1, 'decision' => 'approve', 'review_evidence' => 'Synthetic independent schedule review.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->nurse->id, 'event' => 'roster.entry.submitted']);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->coordinator->id, 'event' => 'roster.entry.approved']);
    }

    public function test_approved_absence_requires_accountable_reassignment_for_affected_visits(): void
    {
        $visit = Appointment::where('provider_id', $this->nurse->id)->whereIn('status', ['booked', 'confirmed'])->firstOrFail();
        $payload = $this->rosterPayload([
            'kind' => 'leave', 'starts_at' => $visit->starts_at->copy()->subMinutes(5)->setTimezone('Indian/Mauritius')->format('Y-m-d\TH:i'),
            'ends_at' => $visit->ends_at->copy()->addMinutes(5)->setTimezone('Indian/Mauritius')->format('Y-m-d\TH:i'), 'notes' => 'Synthetic leave around a demo visit.',
        ]);
        $this->as($this->nurse)->post('/my-roster', $payload)->assertSessionHasNoErrors();
        $entry = ProviderRosterEntry::where('request_id', $payload['request_id'])->firstOrFail();
        $this->as($this->coordinator)->post('/workspace/roster/'.$entry->id.'/review', ['version' => 1, 'decision' => 'approve', 'review_evidence' => 'Synthetic approval.'])->assertSessionHasErrors('backup_id');
        $this->post('/workspace/roster/'.$entry->id.'/review', [
            'version' => 1, 'decision' => 'approve', 'review_evidence' => 'Synthetic approval with accountable reassignment.',
            'backup_id' => $this->lead->id, 'due_at' => now('Indian/Mauritius')->addHour()->format('Y-m-d\TH:i'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('care_tasks', ['owner_id' => $this->coordinator->id, 'backup_id' => $this->lead->id, 'status' => 'new']);
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_scheduling_and_acceptance_enforce_approved_roster_and_explicit_provider_response(): void
    {
        $patient = Patient::whereNotNull('user_id')->firstOrFail();
        $referral = Referral::where('patient_id', $patient->id)->where('status', 'awaiting_match')->firstOrFail();
        $outside = now('Indian/Mauritius')->addYears(2)->startOfDay()->addHours(10);
        $payload = [
            'request_id' => (string) Str::uuid(), 'patient_id' => $patient->id, 'referral_id' => $referral->id, 'provider_id' => $this->nurse->id,
            'service' => 'Synthetic roster-gated visit', 'starts_at' => $outside->format('Y-m-d\TH:i'), 'ends_at' => $outside->addHour()->format('Y-m-d\TH:i'),
            'location_type' => 'clinic', 'location' => 'Synthetic clinic',
        ];
        $this->as($this->coordinator)->post('/workspace/appointments', $payload)->assertSessionHasErrors('starts_at');
        ProviderRosterEntry::create(['organisation_id' => $this->organisation->id, 'provider_id' => $this->nurse->id, 'kind' => 'availability', 'starts_at' => $outside->copy()->subHour()->utc(), 'ends_at' => $outside->copy()->addHours(2)->utc(), 'status' => 'approved', 'recorded_by' => $this->nurse->id, 'reviewed_by' => $this->coordinator->id, 'reviewed_at' => now(), 'request_id' => (string) Str::uuid()]);
        $this->post('/workspace/appointments', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $visit = Appointment::where('request_id', $payload['request_id'])->firstOrFail();
        $this->patch('/workspace/appointments/'.$visit->id, ['version' => 1, 'status' => 'confirmed', 'confirmation_evidence' => 'Synthetic patient confirmation.'])->assertSessionHasErrors('status');
        $this->as($this->doctor)->post('/my-visits/'.$visit->id.'/respond', ['version' => 1, 'decision' => 'accept', 'evidence' => 'Wrong provider.', 'declaration' => true])->assertNotFound();
        $this->as($this->nurse)->post('/my-visits/'.$visit->id.'/respond', ['version' => 1, 'decision' => 'accept', 'evidence' => 'Synthetic schedule and location checked.', 'declaration' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->as($this->coordinator)->patch('/workspace/appointments/'.$visit->id, ['version' => 2, 'status' => 'confirmed', 'confirmation_evidence' => 'Synthetic patient confirmation after provider acceptance.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $visit->fresh()->status);
    }

    public function test_missed_visit_opens_assigned_welfare_and_replacement_case_then_resolves_with_evidence(): void
    {
        $patient = Patient::whereNotNull('user_id')->firstOrFail();
        $referral = Referral::where('patient_id', $patient->id)->firstOrFail();
        $visit = Appointment::create([
            'organisation_id' => $this->organisation->id, 'patient_id' => $patient->id, 'referral_id' => $referral->id, 'provider_id' => $this->nurse->id,
            'service' => 'Synthetic past confirmed visit', 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(), 'location_type' => 'home',
            'location' => 'Synthetic address', 'status' => 'confirmed', 'offer_status' => 'accepted', 'request_id' => (string) Str::uuid(),
        ]);
        $requestId = (string) Str::uuid();
        $mark = ['request_id' => $requestId, 'version' => 1, 'missed_reason' => 'Synthetic coordinator verified no attendance and no visit occurred.', 'owner_id' => $this->coordinator->id, 'backup_id' => $this->lead->id, 'due_at' => now('Indian/Mauritius')->addHour()->format('Y-m-d\TH:i')];
        $this->as($this->coordinator)->post('/workspace/appointments/'.$visit->id.'/missed', $mark)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/workspace/appointments/'.$visit->id.'/missed', $mark)->assertRedirect()->assertSessionHasNoErrors();
        $case = MissedVisitCase::where('request_id', $requestId)->firstOrFail();
        $this->assertSame('missed', $visit->fresh()->status);
        $this->assertDatabaseHas('care_tasks', ['id' => $case->task_id, 'owner_id' => $this->coordinator->id]);
        $this->post('/workspace/missed-visits/'.$case->id.'/welfare', ['version' => 1, 'welfare_status' => 'contacted', 'welfare_evidence' => 'Synthetic telephone contact outcome; no real call.', 'checked_at' => now('Indian/Mauritius')->subMinute()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->post('/workspace/missed-visits/'.$case->id.'/resolve', ['version' => 2, 'resolution_evidence' => 'Cannot resolve without replacement.'])->assertStatus(422);
        $this->post('/workspace/missed-visits/'.$case->id.'/waive-replacement', ['version' => 2, 'replacement_evidence' => 'Synthetic documented patient decision not to replace.'])->assertSessionHasNoErrors();
        $this->post('/workspace/missed-visits/'.$case->id.'/resolve', ['version' => 3, 'resolution_evidence' => 'Synthetic welfare and waiver independently reviewed.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('resolved', $case->fresh()->status);
        $this->assertSame('completed', CareTask::findOrFail($case->task_id)->status);
    }

    public function test_declined_replacement_reopens_requirement_and_confirmed_replacement_allows_resolution(): void
    {
        $case = MissedVisitCase::where('status', 'open')->firstOrFail();
        $referral = Referral::where('patient_id', $case->patient_id)->where('status', 'awaiting_match')->firstOrFail();
        $this->as($this->coordinator)->post('/workspace/missed-visits/'.$case->id.'/welfare', [
            'version' => 1, 'welfare_status' => 'contacted', 'welfare_evidence' => 'Synthetic contact for replacement test.',
            'checked_at' => now('Indian/Mauritius')->subMinute()->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();
        $start = now('Indian/Mauritius')->addDays(2)->setTime(18, 0);
        $base = [
            'patient_id' => $case->patient_id, 'referral_id' => $referral->id, 'provider_id' => $this->nurse->id, 'missed_visit_case_id' => $case->id,
            'service' => 'Synthetic replacement visit', 'location_type' => 'home', 'location' => 'Synthetic address',
        ];
        $first = [...$base, 'request_id' => (string) Str::uuid(), 'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->copy()->addHour()->format('Y-m-d\TH:i')];
        $this->post('/workspace/appointments', $first)->assertSessionHasNoErrors();
        $visit = Appointment::where('request_id', $first['request_id'])->firstOrFail();
        $this->assertSame('booked', $case->fresh()->replacement_status);
        $this->as($this->nurse)->post('/my-visits/'.$visit->id.'/respond', ['version' => 1, 'decision' => 'decline', 'evidence' => 'Synthetic provider cannot attend.', 'declaration' => true])->assertSessionHasNoErrors();
        $this->assertSame('required', $case->fresh()->replacement_status);
        $this->assertNull($case->fresh()->replacement_appointment_id);

        $secondStart = $start->copy()->addHours(2);
        $second = [...$base, 'request_id' => (string) Str::uuid(), 'starts_at' => $secondStart->format('Y-m-d\TH:i'), 'ends_at' => $secondStart->copy()->addHour()->format('Y-m-d\TH:i')];
        $this->as($this->coordinator)->post('/workspace/appointments', $second)->assertSessionHasNoErrors();
        $replacement = Appointment::where('request_id', $second['request_id'])->firstOrFail();
        $this->as($this->nurse)->post('/my-visits/'.$replacement->id.'/respond', ['version' => 1, 'decision' => 'accept', 'evidence' => 'Synthetic replacement roster checked.', 'declaration' => true])->assertSessionHasNoErrors();
        $this->as($this->coordinator)->patch('/workspace/appointments/'.$replacement->id, ['version' => 2, 'status' => 'confirmed', 'confirmation_evidence' => 'Synthetic patient confirmed replacement.'])->assertSessionHasNoErrors();
        $case = $case->fresh();
        $this->post('/workspace/missed-visits/'.$case->id.'/resolve', ['version' => $case->version, 'resolution_evidence' => 'Synthetic welfare completed and replacement confirmed.'])->assertSessionHasNoErrors();
        $this->assertSame('resolved', $case->fresh()->status);
    }

    public function test_roster_views_are_role_and_tenant_scoped_and_demo_data_is_idempotent(): void
    {
        $this->as($this->nurse)->get('/my-roster')->assertOk()->assertInertia(fn (Assert $page) => $page->component('roster/index', false)->where('mode', 'provider')->has('entries', 1));
        $this->as($this->coordinator)->get('/workspace/roster')->assertOk()->assertInertia(fn (Assert $page) => $page->where('mode', 'workspace')->has('cases', 1));
        $this->get('/my-roster')->assertForbidden();
        $other = Organisation::create(['name' => 'Other roster tenant']);
        $this->as($this->nurse)->withSession(['organisation_id' => $other->id])->get('/my-roster')->assertForbidden();
        $counts = [ProviderRosterEntry::count(), MissedVisitCase::count(), CareTask::count()];
        $this->seed(DemoDataSeeder::class);
        $this->assertSame($counts, [ProviderRosterEntry::count(), MissedVisitCase::count(), CareTask::count()]);
    }
}
