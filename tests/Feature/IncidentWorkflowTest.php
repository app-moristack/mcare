<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\OperationalIncident;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IncidentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private Appointment $visit;

    private User $nurse;

    private User $coordinator;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->organisation = Organisation::where('name', 'MauriCare Demo')->firstOrFail();
        $this->visit = Appointment::where('service', 'Demo visit with operational checklist')->firstOrFail();
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->coordinator = User::where('email', 'demo.coordinator@example.test')->firstOrFail();
        $this->lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
    }

    private function workspace(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->organisation->id]);
    }

    private function report(): array
    {
        return [
            'request_id' => Str::uuid()->toString(), 'category' => 'service_delivery', 'severity' => 'routine',
            'description' => 'Synthetic incident details retained privately for review.',
        ];
    }

    public function test_staff_incidents_are_encrypted_replay_safe_and_scoped_to_the_selected_organisation(): void
    {
        $route = '/workspace/incidents';
        $input = [...$this->report(), 'reviewer_id' => $this->lead->id, 'visit_id' => $this->visit->id];
        $this->workspace($this->nurse)->get($route)->assertForbidden();
        $this->postJson($route, $input)->assertForbidden();

        $foreignOrg = Organisation::create(['name' => 'Other synthetic incident workspace', 'type' => 'care_service', 'currency' => 'MUR', 'time_zone' => 'Indian/Mauritius']);
        $foreignVisit = $this->visit->replicate();
        $foreignVisit->fill(['organisation_id' => $foreignOrg->id, 'request_id' => Str::uuid()->toString()])->save();
        $foreign = OperationalIncident::firstOrFail()->replicate();
        $foreign->fill(['organisation_id' => $foreignOrg->id, 'visit_id' => $foreignVisit->id, 'request_id' => Str::uuid()->toString(), 'description' => 'Foreign private synthetic report.'])->save();
        $this->workspace($this->coordinator)->postJson($route, [...$input, 'visit_id' => $foreignVisit->id])
            ->assertUnprocessable()->assertJsonValidationErrors('visit_id');
        $this->postJson($route, [...$input, 'reviewer_id' => $this->coordinator->id])->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');
        $this->postJson($route, [...$input, 'reviewer_id' => $this->nurse->id])->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');
        $this->post($route, $input)->assertSessionHasNoErrors();
        $this->post($route, $input)->assertSessionHasNoErrors();
        $this->postJson($route, [...$input, 'description' => 'Different synthetic report.'])->assertConflict();
        $report = OperationalIncident::where('request_id', $input['request_id'])->firstOrFail();
        $this->assertSame('assigned', $report->status);
        $this->assertSame($this->lead->id, $report->reviewer_id);
        $this->assertSame($this->coordinator->id, $report->assigned_by);
        $this->assertStringNotContainsString($input['description'], $report->getRawOriginal('description'));
        $this->assertSame(1, DB::table('audit_events')->where('event', 'incident.reported')->where('resource_id', $report->id)->count());

        $this->get($route)->assertInertia(fn (Assert $page) => $page->component('incidents/index', false)
            ->has('incidents.data', 4)->where('incidents.data.0.description', $input['description'])->where('currentUserId', $this->coordinator->id))
            ->assertDontSee('Foreign private synthetic report.');
        $this->postJson($route.'/'.$foreign->id.'/assign', ['version' => 1, 'reviewer_id' => $this->lead->id])->assertNotFound();
        $this->postJson($route.'/'.$foreign->id.'/reassign', [
            'version' => 1, 'reviewer_id' => $this->lead->id, 'reason' => 'Synthetic reason.',
            'password' => DemoDataSeeder::PASSWORD, 'declaration' => true,
        ])->assertNotFound();
        $this->withSession(['organisation_id' => $foreignOrg->id])->get($route)->assertForbidden();
    }

    public function test_only_assigned_provider_can_report_and_only_active_named_reviewer_can_resolve(): void
    {
        $route = '/my-visits/'.$this->visit->id.'/incidents';
        $staff = '/workspace/incidents';
        $input = $this->report();
        $doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $initialAlerts = DB::table('operation_notifications')->count();
        $this->workspace($doctor)->postJson($route, $input)->assertForbidden();
        $this->visit->update(['offer_status' => 'pending']);
        $this->workspace($this->nurse)->postJson($route, $input)->assertForbidden();
        $this->visit->update(['offer_status' => 'accepted']);
        $this->post($route, [...$input, 'reviewer_id' => $this->lead->id])->assertSessionHasNoErrors();
        $incident = OperationalIncident::where('request_id', $input['request_id'])->firstOrFail();
        $this->assertSame('reported', $incident->status);
        $this->assertNull($incident->reviewer_id);
        $assign = $staff.'/'.$incident->id.'/assign';
        $resolve = $staff.'/'.$incident->id.'/resolve';
        $review = ['version' => $incident->version, 'reviewer_id' => $this->lead->id];
        $this->workspace($this->coordinator)->postJson($assign, [...$review, 'version' => $incident->version + 1])->assertConflict();
        $this->postJson($assign, [...$review, 'reviewer_id' => $this->nurse->id])->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');
        $this->post($assign, $review)->assertSessionHasNoErrors();
        $this->post($assign, $review)->assertSessionHasNoErrors();
        $assigned = $incident->fresh();
        $signature = ['password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
        $resolution = ['version' => $assigned->version, 'resolution' => 'Synthetic review evidence stored internally.', ...$signature];
        $this->postJson($resolve, $resolution)->assertForbidden();
        $this->workspace($this->lead)->postJson($resolve, [...$resolution, 'resolution' => '', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors(['resolution', 'password']);
        $this->postJson($resolve, [...$resolution, 'version' => $incident->version])->assertConflict();
        $membership = $this->lead->memberships()->where('organisation_id', $this->organisation->id)->where('role', 'clinical_admin')->firstOrFail();
        $membership->update(['status' => 'suspended']);
        $this->postJson($resolve, $resolution)->assertForbidden();
        $membership->update(['status' => 'active']);

        $this->post($resolve, $resolution)->assertSessionHasNoErrors();
        $this->post($resolve, $resolution)->assertSessionHasNoErrors();
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertStringNotContainsString($resolution['resolution'], $incident->fresh()->getRawOriginal('resolution'));
        $this->assertSame(1, DB::table('audit_events')->where('event', 'incident.assigned')->where('resource_id', $incident->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('event', 'incident.resolved')->where('resource_id', $incident->id)->where('actor_id', $this->lead->id)->count());

        $this->workspace($this->coordinator)->post($staff, [...$this->report(), 'reviewer_id' => $this->lead->id, 'visit_id' => $this->visit->id])
            ->assertSessionHasNoErrors();
        $this->workspace($this->nurse)->post($route, $input)->assertSessionHasNoErrors();
        $this->get('/my-visits')->assertInertia(fn (Assert $page) => $page->where('visits.data', function ($visits) use ($incident) {
            $visit = collect($visits)->firstWhere('id', $this->visit->id);
            $report = collect($visit['incidents'])->firstWhere('id', $incident->id);

            return $visit['can_report_incident']
                && count($visit['incidents']) === 2
                && array_keys($report) === ['id', 'status', 'reported_at', 'resolved_at']
                && $report['status'] === 'resolved';
        }))->assertDontSee($resolution['resolution'])->assertDontSee($input['description']);
        $this->assertSame($initialAlerts, DB::table('operation_notifications')->count());
    }

    public function test_reviewed_reassignment_retains_previous_attribution_and_transfers_resolution_authority(): void
    {
        $incident = OperationalIncident::where('visit_id', $this->visit->id)->where('status', 'reported')->firstOrFail();
        $base = '/workspace/incidents/'.$incident->id;
        $this->workspace($this->coordinator)->post($base.'/assign', ['reviewer_id' => $this->coordinator->id, 'version' => $incident->version])
            ->assertSessionHasNoErrors();
        $assigned = $incident->fresh();
        $signature = ['password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
        $move = [
            'reviewer_id' => $this->lead->id, 'version' => $assigned->version,
            'reason' => 'Synthetic continuity evidence explaining why a different reviewer was selected.',
            ...$signature,
        ];
        $this->workspace($this->nurse)->postJson($base.'/reassign', $move)->assertForbidden();
        $this->workspace($this->coordinator)->postJson($base.'/reassign', [...$move, 'password' => 'wrong', 'reason' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['password', 'reason']);
        $this->postJson($base.'/reassign', [...$move, 'version' => $assigned->version + 1])->assertConflict();
        $this->postJson($base.'/reassign', [...$move, 'reviewer_id' => $this->coordinator->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');
        $this->postJson($base.'/reassign', [...$move, 'reviewer_id' => $this->nurse->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');

        $membership = $this->lead->memberships()->where('organisation_id', $this->organisation->id)->where('role', 'clinical_admin')->firstOrFail();
        $membership->update(['status' => 'suspended']);
        $this->postJson($base.'/reassign', $move)->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');
        $membership->update(['status' => 'active']);

        $this->post($base.'/reassign', $move)->assertSessionHasNoErrors();
        $this->post($base.'/reassign', $move)->assertSessionHasNoErrors();
        $this->postJson($base.'/reassign', [...$move, 'reason' => 'Changed retry payload.'])->assertConflict();
        $this->postJson($base.'/assign', ['reviewer_id' => $this->lead->id, 'version' => $assigned->version])->assertConflict();
        $current = $incident->fresh();
        $history = $incident->reassignments()->sole();
        $this->assertSame($this->lead->id, $current->reviewer_id);
        $this->assertSame($assigned->version + 1, $current->version);
        $this->assertSame($assigned->content_hash, $current->content_hash);
        $this->assertSame($assigned->reviewer_id, $history->previous_reviewer_id);
        $this->assertSame($assigned->assigned_by, $history->previous_assigned_by);
        $this->assertEquals($assigned->assigned_at, $history->previous_assigned_at);
        $this->assertSame($move['reason'], $history->reason);
        $this->assertStringNotContainsString($move['reason'], $history->getRawOriginal('reason'));
        $this->assertSame(1, DB::table('audit_events')->where('event', 'incident.reassigned')->where('resource_id', $incident->id)->count());
        $this->get('/workspace/incidents')->assertInertia(fn (Assert $page) => $page->where('incidents.data', function ($items) use ($incident, $history, $move) {
            $row = collect($items)->firstWhere('id', $incident->id);

            return count($row['reassignments']) === 1
                && $row['reassignments'][0]['id'] === $history->id
                && $row['reassignments'][0]['previous_reviewer']['id'] === $this->coordinator->id
                && $row['reassignments'][0]['reviewer']['id'] === $this->lead->id
                && $row['reassignments'][0]['changed_by']['id'] === $this->coordinator->id
                && $row['reassignments'][0]['reason'] === $move['reason'];
        }));

        $resolution = ['version' => $current->version, 'resolution' => 'Synthetic closure following reassignment.', ...$signature];
        $this->postJson($base.'/resolve', $resolution)->assertForbidden();
        $this->workspace($this->lead)->post($base.'/resolve', $resolution)->assertSessionHasNoErrors();
        $this->postJson($base.'/reassign', [...$move, 'reviewer_id' => $this->coordinator->id, 'version' => $incident->fresh()->version])->assertConflict();
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertSame(1, $incident->reassignments()->count());
        $this->workspace($this->nurse)->get('/my-visits')->assertDontSee($move['reason'])->assertDontSee($resolution['resolution']);
    }
}
