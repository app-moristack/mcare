<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceEvent;
use App\Models\AttendanceReview;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceReviewTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private User $nurse;

    private User $lead;

    private User $coordinator;

    private Appointment $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->organisation = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
        $this->coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $this->visit = Appointment::where('service', 'Demo completed visit')->firstOrFail();
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->organisation->id]);
    }

    public function test_exception_review_is_independent_encrypted_idempotent_and_does_not_mutate_source(): void
    {
        $event = AttendanceEvent::where('appointment_id', $this->visit->id)->where('kind', 'arrival')->firstOrFail();
        AttendanceReview::where('attendance_event_id', $event->id)->delete();
        $payload = ['request_id' => (string) Str::uuid(), 'decision' => 'confirmed_exception', 'evidence' => 'Synthetic coordinator checked the documented browser permission denial.'];
        $this->as($this->nurse)->post('/workspace/attendance-events/'.$event->id.'/review', $payload)->assertForbidden();
        $sourceTime = $event->occurred_at->toISOString();
        $this->as($this->coordinator)->post('/workspace/attendance-events/'.$event->id.'/review', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/workspace/attendance-events/'.$event->id.'/review', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $review = AttendanceReview::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame('confirmed_exception', $review->decision);
        $this->assertSame($sourceTime, $event->fresh()->occurred_at->toISOString());
        $this->assertStringNotContainsString('browser permission', DB::table('attendance_reviews')->where('id', $review->id)->value('evidence'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->coordinator->id, 'event' => 'attendance.exception_confirmed_exception', 'resource_id' => $event->id]);
    }

    public function test_provider_requests_append_only_correction_and_distinct_reviewer_approves_effective_time(): void
    {
        $event = AttendanceEvent::where('appointment_id', $this->visit->id)->where('kind', 'arrival')->firstOrFail();
        AttendanceReview::where('attendance_event_id', $event->id)->delete();
        $this->as($this->coordinator)->post('/workspace/attendance-events/'.$event->id.'/review', [
            'request_id' => (string) Str::uuid(),
            'decision' => 'correction_required',
            'evidence' => 'Synthetic independent review requires a time correction.',
        ])->assertSessionHasNoErrors();
        $proposed = $event->occurred_at->copy()->addMinute();
        $payload = [
            'request_id' => (string) Str::uuid(), 'proposed_occurred_at' => $proposed->setTimezone('Indian/Mauritius')->format('Y-m-d\TH:i'),
            'reason' => 'Synthetic device clock transcription correction.', 'evidence' => 'Synthetic visit log evidence.', 'declaration' => true,
        ];
        $original = $event->occurred_at->toISOString();
        $this->as($this->nurse)->post('/my-visits/'.$this->visit->id.'/attendance/'.$event->id.'/corrections', $payload)->assertSessionHasNoErrors();
        $this->post('/my-visits/'.$this->visit->id.'/attendance/'.$event->id.'/corrections', $payload)->assertSessionHasNoErrors();
        $correction = AttendanceCorrection::where('request_id', $payload['request_id'])->firstOrFail();
        $this->assertSame('pending', $correction->status);
        $this->assertStringNotContainsString('device clock', DB::table('attendance_corrections')->where('id', $correction->id)->value('reason'));
        $this->as($this->lead)->post('/workspace/attendance-corrections/'.$correction->id.'/review', ['version' => 1, 'decision' => 'approve', 'review_evidence' => 'Synthetic independent evidence review.'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $correction->fresh()->status);
        $this->assertSame($original, $event->fresh()->occurred_at->toISOString());
        $this->assertDatabaseHas('audit_events', ['event' => 'attendance.correction_approved', 'resource_id' => $correction->id]);
    }

    public function test_correction_order_plausibility_and_current_request_rules_are_enforced(): void
    {
        $event = AttendanceEvent::where('appointment_id', $this->visit->id)->where('kind', 'departure')->firstOrFail();
        AttendanceCorrection::where('attendance_event_id', $event->id)->delete();
        $payload = ['request_id' => (string) Str::uuid(), 'proposed_occurred_at' => now('Indian/Mauritius')->addHour()->format('Y-m-d\TH:i'), 'reason' => 'Future is invalid.', 'evidence' => 'Synthetic evidence.', 'declaration' => true];
        $this->as($this->nurse)->post('/my-visits/'.$this->visit->id.'/attendance/'.$event->id.'/corrections', $payload)->assertSessionHasErrors('proposed_occurred_at');

        $confirmed = AttendanceEvent::where('appointment_id', $this->visit->id)->where('kind', 'arrival')->firstOrFail();
        $this->post('/my-visits/'.$this->visit->id.'/attendance/'.$confirmed->id.'/corrections', [
            ...$payload,
            'request_id' => (string) Str::uuid(),
            'proposed_occurred_at' => $confirmed->occurred_at->copy()->setTimezone('Indian/Mauritius')->format('Y-m-d\TH:i'),
        ])->assertUnprocessable();

        $arrival = AttendanceEvent::where('appointment_id', $this->visit->id)->where('kind', 'arrival')->firstOrFail();
        $bad = [...$payload, 'request_id' => (string) Str::uuid(), 'proposed_occurred_at' => $arrival->occurred_at->copy()->subMinute()->setTimezone('Indian/Mauritius')->format('Y-m-d\TH:i'), 'reason' => 'Synthetic invalid ordering request.'];
        $this->post('/my-visits/'.$this->visit->id.'/attendance/'.$event->id.'/corrections', $bad)->assertSessionHasNoErrors();
        $correction = AttendanceCorrection::where('request_id', $bad['request_id'])->firstOrFail();
        $this->as($this->lead)->post('/workspace/attendance-corrections/'.$correction->id.'/review', ['version' => 1, 'decision' => 'approve', 'review_evidence' => 'Should fail ordering.'])->assertSessionHasErrors('decision');
        $this->post('/workspace/attendance-corrections/'.$correction->id.'/review', ['version' => 1, 'decision' => 'reject', 'review_evidence' => 'Synthetic rejection for invalid ordering.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $correction->fresh()->status);
    }

    public function test_completion_requires_every_exception_review_or_approved_correction(): void
    {
        $arrival = AttendanceEvent::where('appointment_id', $this->visit->id)->where('kind', 'arrival')->firstOrFail();
        AttendanceReview::where('attendance_event_id', $arrival->id)->delete();
        $this->visit->update(['status' => 'arrived', 'version' => 10]);
        $this->as($this->coordinator)->patch('/workspace/appointments/'.$this->visit->id, ['version' => 10, 'status' => 'completed', 'completion_evidence' => 'Synthetic operational completion.'])->assertSessionHasErrors('status');
        $this->post('/workspace/attendance-events/'.$arrival->id.'/review', ['request_id' => (string) Str::uuid(), 'decision' => 'confirmed_exception', 'evidence' => 'Synthetic exception confirmed.'])->assertSessionHasNoErrors();
        $this->patch('/workspace/appointments/'.$this->visit->id, ['version' => 10, 'status' => 'completed', 'completion_evidence' => 'Synthetic operational completion after exception review.'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $this->visit->fresh()->status);
    }

    public function test_review_queue_is_tenant_scoped_and_demo_seed_is_idempotent(): void
    {
        $this->as($this->coordinator)->get('/workspace/attendance-review')->assertOk()->assertInertia(fn (Assert $page) => $page->component('attendance/index', false)->has('events', 2));
        $patient = User::where('email', 'demo.patient@example.test')->firstOrFail();
        $this->as($patient)->get('/workspace/attendance-review')->assertForbidden();
        $other = Organisation::create(['name' => 'Other attendance tenant']);
        $this->as($this->coordinator)->withSession(['organisation_id' => $other->id])->get('/workspace/attendance-review')->assertForbidden();
        $counts = [AttendanceReview::count(), AttendanceCorrection::count()];
        $this->seed(DemoDataSeeder::class);
        $this->assertSame($counts, [AttendanceReview::count(), AttendanceCorrection::count()]);
    }
}
