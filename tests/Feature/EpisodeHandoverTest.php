<?php

namespace Tests\Feature;

use App\Models\CareEpisode;
use App\Models\CareTask;
use App\Models\EpisodeHandover;
use App\Models\InvestigationResult;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EpisodeHandoverTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;

    private CareEpisode $episode;

    private User $lead;

    private User $nurse;

    private string $clinicalPage;

    private string $handovers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(DemoDataSeeder::class);
        $this->lead = User::where('email', 'demo.lead@example.test')->firstOrFail();
        $this->nurse = User::where('email', 'demo.nurse@example.test')->firstOrFail();
        $this->episode = EpisodeHandover::firstOrFail()->episode;
        $this->patient = $this->episode->patient;
        $this->clinicalPage = '/patient-records/'.$this->patient->id.'/clinical';
        $this->handovers = $this->clinicalPage.'/episodes/'.$this->episode->id.'/handovers';
    }

    private function inWorkspace(User $user): static
    {
        return $this->actingAs($user)->withSession(['organisation_id' => $this->patient->organisation_id]);
    }

    private function payload(array $attributes = []): array
    {
        return [
            'request_id' => Str::uuid()->toString(),
            'recipient_id' => $this->nurse->id,
            'situation' => 'Synthetic confidential situation.',
            'background' => 'Synthetic background.',
            'assessment' => 'Synthetic assessment.',
            'recommendation' => 'Confirm receipt and review the synthetic plan.',
            'priority' => 'urgent',
            'due_at' => now(config('mauricare.time_zone'))->addHours(2)->format('Y-m-d\TH:i'),
            'password' => DemoDataSeeder::PASSWORD,
            'declaration' => true,
            ...$attributes,
        ];
    }

    public function test_named_handover_and_acknowledgement_are_replay_safe_and_preserve_the_signed_content(): void
    {
        $data = $this->payload();
        $this->inWorkspace($this->lead)->get($this->clinicalPage)
            ->assertInertia(fn (Assert $page) => $page->where('handover.recipients', [
                [...User::where('email', 'demo.doctor@example.test')->firstOrFail()->only(['id', 'name']), 'canAcceptTransfers' => false, 'canAcceptResultTransfers' => true],
                [...$this->nurse->only(['id', 'name']), 'canAcceptTransfers' => false, 'canAcceptResultTransfers' => false],
            ]));
        $this->post($this->handovers, $data)->assertSessionHasNoErrors();
        $this->post($this->handovers, $data)->assertSessionHasNoErrors();
        $this->postJson($this->handovers, [...$data, 'situation' => 'A different situation'])->assertConflict();
        $this->assertDatabaseCount('episode_handovers', 2);
        $record = EpisodeHandover::where('request_id', $data['request_id'])->firstOrFail();
        $this->assertSame($this->lead->id, $record->sender_id);
        $this->assertSame('urgent', $record->priority);
        $this->assertSame($data['due_at'], $record->due_at->copy()->setTimezone(config('mauricare.time_zone'))->format('Y-m-d\TH:i'));
        $this->assertSame($data['situation'], $record->content['situation']);
        $this->assertStringNotContainsString($data['situation'], $record->getRawOriginal('content'));
        $this->assertDatabaseHas('operation_notifications', [
            'user_id' => $this->nurse->id,
            'message' => 'A clinical handover is awaiting your acknowledgement.',
            'deduplication_key' => 'handover:'.$record->id,
            'read_at' => null,
        ]);

        $this->inWorkspace($this->nurse)->get($this->clinicalPage)->assertInertia(fn (Assert $page) => $page
            ->where('handover.records.0.canAcknowledge', true)
            ->where('handover.records.0.content.situation', $data['situation']));
        $path = $this->handovers.'/'.$record->id.'/acknowledge';
        $ack = ['acknowledgement' => 'Synthetic confidential confirmation.', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
        $this->postJson($path, [...$ack, 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->post($path, $ack)->assertSessionHasNoErrors();
        $record->refresh();
        $acknowledgedAt = $record->acknowledged_at->toJSON();
        $this->post($path, $ack)->assertSessionHasNoErrors();
        $this->postJson($path, [...$ack, 'acknowledgement' => 'Attempt to replace acknowledgement'])->assertConflict();
        $this->assertSame($acknowledgedAt, $record->fresh()->acknowledged_at->toJSON());
        $this->assertStringNotContainsString($ack['acknowledgement'], $record->getRawOriginal('acknowledgement'));
        $this->assertNotNull(DB::table('operation_notifications')->where('deduplication_key', 'handover:'.$record->id)->value('read_at'));
        $this->assertSame(1, DB::table('audit_events')->where('event', 'clinical.handover.acknowledged')->where('resource_id', $record->id)->count());
        $this->inWorkspace($this->lead)->get($this->clinicalPage)->assertInertia(fn (Assert $page) => $page
            ->where('handover.records.0.acknowledgement', $ack['acknowledgement'])
            ->where('handover.records.0.canAcknowledge', false));
    }

    public function test_sender_and_recipient_must_hold_current_patient_specific_authority(): void
    {
        $doctor = User::where('email', 'demo.physio@example.test')->firstOrFail();
        $this->inWorkspace($this->lead)->postJson($this->handovers, $this->payload(['recipient_id' => $doctor->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('recipient_id');
        $this->postJson($this->handovers, $this->payload(['recipient_id' => $this->lead->id]))->assertUnprocessable()->assertJsonValidationErrors('recipient_id');
        $this->postJson($this->handovers, $this->payload(['due_at' => now(config('mauricare.time_zone'))->subMinute()->format('Y-m-d\TH:i')]))
            ->assertUnprocessable()->assertJsonValidationErrors('due_at');
        $this->postJson($this->handovers, $this->payload(['priority' => 'emergency']))->assertUnprocessable()->assertJsonValidationErrors('priority');
        $this->postJson($this->handovers, $this->payload(['password' => 'wrong']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->inWorkspace($doctor)->postJson($this->handovers, $this->payload())->assertForbidden();

        $pending = EpisodeHandover::firstOrFail();
        $ack = ['acknowledgement' => 'Synthetic review only.', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
        $this->inWorkspace($this->lead)->postJson($this->handovers.'/'.$pending->id.'/acknowledge', $ack)->assertNotFound();
        $this->withSession(['organisation_id' => 0])->postJson($this->handovers, $this->payload())->assertNotFound();
        PatientAccessRule::where('user_id', $this->nurse->id)->where('patient_id', $this->patient->id)
            ->where('scope', 'handover.acknowledge')->update(['revoked_at' => now()]);
        $this->inWorkspace($this->nurse)->postJson($this->handovers.'/'.$pending->id.'/acknowledge', $ack)->assertForbidden();
        $this->inWorkspace($this->lead)->postJson($this->handovers, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('recipient_id');
        $this->assertNull($pending->fresh()->acknowledged_at);
        $this->assertDatabaseCount('episode_handovers', 1);
    }

    public function test_read_scopes_do_not_disclose_an_unaddressed_handover_or_publish_it_to_the_patient(): void
    {
        $doctor = User::where('email', 'demo.physio@example.test')->firstOrFail();
        $grant = PatientAccessRule::where('user_id', $this->nurse->id)->where('patient_id', $this->patient->id)
            ->where('scope', 'handover.read')->firstOrFail()->replicate();
        $grant->fill(['user_id' => $doctor->id, 'request_id' => Str::uuid()->toString()])->save();
        $this->inWorkspace($doctor)->get($this->clinicalPage)
            ->assertInertia(fn (Assert $page) => $page->where('handover.canCreate', false)->has('handover.records', 0));

        $this->actingAs(User::findOrFail($this->patient->user_id))->get('/my-care/patients/'.$this->patient->id.'/clinical')
            ->assertInertia(fn (Assert $page) => $page->missing('handover'))
            ->assertDontSee('SYNTHETIC handover for a demo episode.');
    }

    public function test_transfer_is_signed_by_both_sides_and_moves_ownership_only_after_explicit_acceptance(): void
    {
        $task = CareTask::where('title', 'Demo handover coordination task')->firstOrFail();
        $task->update(['due_at' => now()->subHour(), 'escalated_at' => now()->subMinutes(30)]);
        $initialVersion = $task->version;
        $oldAlert = 'overdue:'.$task->id.':'.$initialVersion.':'.$this->nurse->id;
        DB::table('operation_notifications')->insert([
            'organisation_id' => $task->organisation_id, 'user_id' => $this->nurse->id,
            'message' => 'Synthetic overdue task alert.', 'url' => '/workspace/tasks',
            'deduplication_key' => $oldAlert, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $data = $this->payload([
            'recipient_id' => $this->lead->id,
            'transfer_tasks' => [$task->only(['id', 'version'])],
        ]);

        $this->inWorkspace($this->nurse)->get($this->clinicalPage)
            ->assertInertia(fn (Assert $page) => $page
                ->where('handover.transferTasks', [$task->only(['id', 'title', 'version'])])
                ->where('handover.recipients', [
                    [...User::where('email', 'demo.doctor@example.test')->firstOrFail()->only(['id', 'name']), 'canAcceptTransfers' => false, 'canAcceptResultTransfers' => true],
                    [...$this->lead->only(['id', 'name']), 'canAcceptTransfers' => true, 'canAcceptResultTransfers' => true],
                ]));
        $this->post($this->handovers, $data)->assertSessionHasNoErrors();
        $this->post($this->handovers, $data)->assertSessionHasNoErrors();
        $this->postJson($this->handovers, [...$data, 'transfer_tasks' => []])->assertConflict();
        $entry = EpisodeHandover::where('request_id', $data['request_id'])->firstOrFail();
        $this->assertSame($this->nurse->id, $task->fresh()->owner_id);
        $this->assertSame($initialVersion, $task->fresh()->version);
        $this->assertStringNotContainsString($task->title, $entry->getRawOriginal('transfer_tasks'));

        $this->inWorkspace($this->lead)->get($this->clinicalPage)
            ->assertInertia(fn (Assert $page) => $page
                ->where('handover.records.0.transfer_tasks', [$task->only(['id', 'title'])])
                ->where('handover.records.0.canAcknowledge', true));
        $url = $this->handovers.'/'.$entry->id.'/acknowledge';
        $ack = ['acknowledgement' => 'I acknowledge the synthetic handover and take ownership.', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
        $this->postJson($url, $ack)->assertUnprocessable()->assertJsonValidationErrors('accept_transfer');
        $this->assertSame($this->nurse->id, $task->fresh()->owner_id);
        $this->post($url, [...$ack, 'accept_transfer' => true])->assertSessionHasNoErrors();
        $this->post($url, [...$ack, 'accept_transfer' => true])->assertSessionHasNoErrors();
        $changed = $task->fresh();
        $this->assertSame($this->lead->id, $changed->owner_id);
        $this->assertSame($initialVersion + 1, $changed->version);
        $this->assertEquals($task->only(['backup_id', 'due_at', 'status', 'description']), $changed->only(['backup_id', 'due_at', 'status', 'description']));
        $this->assertNull($changed->escalated_at);
        $this->assertNotNull($entry->fresh()->acknowledged_at);
        $this->assertNotNull(DB::table('operation_notifications')->where('deduplication_key', $oldAlert)->value('read_at'));
        $this->assertSame(1, DB::table('audit_events')->where('event', 'clinical.handover.task_transferred')->where('resource_id', $task->id)->where('actor_id', $this->lead->id)->count());

        $this->artisan('mauricare:escalate-tasks')->assertSuccessful();
        $this->assertDatabaseHas('operation_notifications', [
            'deduplication_key' => 'overdue:'.$task->id.':'.$changed->version.':'.$this->lead->id, 'user_id' => $this->lead->id, 'read_at' => null,
        ]);
        $this->assertDatabaseMissing('operation_notifications', [
            'deduplication_key' => 'overdue:'.$task->id.':'.$changed->version.':'.$this->nurse->id,
        ]);
    }

    public function test_ineligible_or_changed_tasks_cannot_be_transferred_and_apply_atomically(): void
    {
        $task = CareTask::where('title', 'Demo handover coordination task')->firstOrFail();
        $derived = CareTask::whereKey(InvestigationResult::firstOrFail()->task_id)->firstOrFail();
        $derived->update(['owner_id' => $this->nurse->id]);
        $data = $this->payload(['recipient_id' => $this->lead->id, 'transfer_tasks' => [$derived->only(['id', 'version'])]]);
        $this->inWorkspace($this->nurse)->postJson($this->handovers, $data)->assertUnprocessable()->assertJsonValidationErrors('transfer_tasks');

        $alternative = $task->replicate();
        $alternative->fill(['request_id' => Str::uuid()->toString(), 'title' => 'Synthetic additional task', 'backup_id' => $this->lead->id])->save();
        $data['transfer_tasks'] = [$alternative->only(['id', 'version'])];
        $this->postJson($this->handovers, $data)->assertUnprocessable()->assertJsonValidationErrors('transfer_tasks');
        $alternative->update(['backup_id' => $task->backup_id]);

        $data['transfer_tasks'] = [$task->only(['id', 'version']), $alternative->only(['id', 'version'])];
        $this->post($this->handovers, $data)->assertSessionHasNoErrors();
        $entry = EpisodeHandover::where('request_id', $data['request_id'])->firstOrFail();
        $alternative->increment('version');
        $ack = ['acknowledgement' => 'Synthetic transfer acceptance.', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true, 'accept_transfer' => true];
        $this->inWorkspace($this->lead)->postJson($this->handovers.'/'.$entry->id.'/acknowledge', $ack)
            ->assertUnprocessable()->assertJsonValidationErrors('transfer_tasks');
        $this->assertNull($entry->fresh()->acknowledged_at);
        $this->assertSame($this->nurse->id, $task->fresh()->owner_id);
        $this->assertSame($task->version, $task->fresh()->version);
        $this->assertSame($this->nurse->id, $alternative->fresh()->owner_id);
    }

    public function test_task_management_authority_is_rechecked_at_send_and_acceptance(): void
    {
        $task = CareTask::where('title', 'Demo handover coordination task')->firstOrFail();
        $task->update(['owner_id' => $this->lead->id]);
        $data = $this->payload(['transfer_tasks' => [$task->only(['id', 'version'])]]);
        $this->inWorkspace($this->lead)->postJson($this->handovers, $data)->assertUnprocessable()->assertJsonValidationErrors('recipient_id');

        $task->update(['owner_id' => $this->nurse->id]);
        $data['recipient_id'] = $this->lead->id;
        $this->inWorkspace($this->nurse)->post($this->handovers, $data)->assertSessionHasNoErrors();
        $entry = EpisodeHandover::where('request_id', $data['request_id'])->firstOrFail();
        config(['permissions.grants.clinical_admin' => array_values(array_diff(config('permissions.grants.clinical_admin'), ['tasks.manage']))]);
        $ack = ['acknowledgement' => 'Attempt to accept without task authority.', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true, 'accept_transfer' => true];
        $this->inWorkspace($this->lead)->postJson($this->handovers.'/'.$entry->id.'/acknowledge', $ack)->assertForbidden();
        $this->assertNull($entry->fresh()->acknowledged_at);
        $this->assertSame($this->nurse->id, $task->fresh()->owner_id);
    }

    public function test_transfer_provenance_is_assigned_by_ordinary_task_creation(): void
    {
        $task = CareTask::where('title', 'Demo handover coordination task')->firstOrFail();
        $data = [
            'request_id' => Str::uuid()->toString(), 'patient_id' => $task->patient_id, 'title' => 'Synthetic ordinary task',
            'description' => 'Synthetic coordination work.', 'priority' => 'routine', 'owner_id' => $task->owner_id,
            'backup_id' => $task->backup_id, 'due_at' => now()->addDay()->toDateTimeString(),
        ];
        $this->inWorkspace($this->lead)->post('/workspace/tasks', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('care_tasks', ['request_id' => $data['request_id'], 'handover_transferable' => true]);

        $data['request_id'] = Str::uuid()->toString();
        $this->post('/workspace/tasks', [...$data, 'priority' => 'critical', 'handover_transferable' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('care_tasks', ['request_id' => $data['request_id'], 'handover_transferable' => false]);
    }

    public function test_migration_backfills_only_patient_linked_routine_tasks_with_matching_creation_audit(): void
    {
        $ordinary = CareTask::where('title', 'Demo handover coordination task')->firstOrFail();
        $unknown = $ordinary->replicate();
        $unknown->fill(['request_id' => Str::uuid()->toString(), 'title' => 'Synthetic unknown provenance'])->save();
        $critical = $ordinary->replicate();
        $critical->fill(['request_id' => Str::uuid()->toString(), 'priority' => 'critical'])->save();
        foreach ([$ordinary, $unknown, $critical] as $task) {
            DB::table('audit_events')->insert([
                'actor_id' => $this->lead->id,
                'organisation_id' => $task->id === $unknown->id ? null : $task->organisation_id,
                'event' => 'tasks.created',
                'resource_type' => 'tasks',
                'resource_id' => $task->id,
                'created_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_11_24_000001_add_handover_task_transfers.php');
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('care_tasks', ['id' => $ordinary->id, 'handover_transferable' => true]);
        $this->assertDatabaseHas('care_tasks', ['id' => $unknown->id, 'handover_transferable' => false]);
        $this->assertDatabaseHas('care_tasks', ['id' => $critical->id, 'handover_transferable' => false]);
    }

    public function test_result_transfer_preserves_signed_receipt_and_hands_off_existing_follow_up(): void
    {
        $doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $result = InvestigationResult::firstOrFail();
        $tracking = CareTask::findOrFail($result->task_id);
        $version = $result->version;
        $taskVersion = $tracking->version;
        $hash = $result->content_hash;
        $oldAlert = 'result-received:'.$result->id.':'.$doctor->id;
        DB::table('operation_notifications')->insert([
            'organisation_id' => $this->patient->organisation_id, 'user_id' => $doctor->id,
            'message' => 'Synthetic result follow-up notice.', 'url' => '/patient-records/'.$this->patient->id.'/investigations',
            'deduplication_key' => $oldAlert, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $selection = [$result->only(['id', 'version'])];
        $offer = $this->payload(['recipient_id' => $this->lead->id, 'transfer_results' => $selection]);

        $this->inWorkspace($doctor)->get($this->clinicalPage)
            ->assertInertia(fn (Assert $page) => $page
                ->where('handover.transferResults', [['id' => $result->id, 'title' => 'Investigation result #'.$result->id, 'version' => $result->version]])
                ->where('handover.recipients.0.canAcceptResultTransfers', true));
        $this->post($this->handovers, $offer)->assertSessionHasNoErrors();
        $this->post($this->handovers, $offer)->assertSessionHasNoErrors();
        $this->postJson($this->handovers, [...$offer, 'transfer_results' => []])->assertConflict();
        $this->assertSame($doctor->id, $result->fresh()->effectiveOwnerId());
        $this->assertSame($doctor->id, $tracking->fresh()->owner_id);
        $handover = EpisodeHandover::where('request_id', $offer['request_id'])->firstOrFail();
        $this->assertStringNotContainsString('Investigation result #'.$result->id, $handover->getRawOriginal('transfer_results'));

        $this->inWorkspace($this->lead)->get($this->clinicalPage)
            ->assertInertia(fn (Assert $page) => $page
                ->where('handover.records.0.transfer_results', [['id' => $result->id, 'title' => 'Investigation result #'.$result->id]])
                ->where('handover.records.0.canAcknowledge', true));
        $accept = $this->handovers.'/'.$handover->id.'/acknowledge';
        $signature = ['password' => DemoDataSeeder::PASSWORD, 'declaration' => true];
        $ack = ['acknowledgement' => 'Synthetic result follow-up accepted.', ...$signature];
        $this->postJson($accept, $ack)->assertUnprocessable()->assertJsonValidationErrors('accept_transfer');
        $this->post($accept, [...$ack, 'accept_transfer' => true])->assertSessionHasNoErrors();
        $this->post($accept, [...$ack, 'accept_transfer' => true])->assertSessionHasNoErrors();

        $changed = $result->fresh();
        $this->assertSame($doctor->id, $changed->owner_id);
        $this->assertSame($this->lead->id, $changed->effectiveOwnerId());
        $this->assertSame($hash, $changed->content_hash);
        $this->assertSame($hash, $changed->digest());
        $this->assertSame($result->acknowledged_by, $changed->acknowledged_by);
        $this->assertSame($result->getRawOriginal('details'), $changed->getRawOriginal('details'));
        $this->assertSame($version + 1, $changed->version);
        $this->assertSame($taskVersion + 1, $tracking->fresh()->version);
        $this->assertSame($this->lead->id, $tracking->fresh()->owner_id);
        $this->assertSame($tracking->backup_id, $tracking->fresh()->backup_id);
        $this->assertNotNull(DB::table('operation_notifications')->where('deduplication_key', $oldAlert)->value('read_at'));
        $this->assertDatabaseHas('operation_notifications', ['user_id' => $this->lead->id, 'deduplication_key' => 'result-transferred:'.$result->id.':'.$changed->version]);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'clinical.handover.result_transferred')->where('resource_id', $result->id)->count());

        $work = '/patient-records/'.$this->patient->id.'/investigations';
        $this->get($work)->assertInertia(fn (Assert $page) => $page
            ->where('results.0.owner_id', $doctor->id)
            ->where('results.0.effective_owner_id', $this->lead->id));
        $complete = $work.'/results/'.$result->id.'/complete';
        $completion = ['version' => $changed->version, 'outcome' => 'Synthetic follow-up completed.', 'evidence' => 'Synthetic record only.', ...$signature];
        $this->inWorkspace($doctor)->postJson($complete, $completion)->assertConflict();
        $this->inWorkspace($this->lead)->post($complete, $completion)->assertSessionHasNoErrors();
        $this->assertSame($this->lead->id, $result->fresh()->completed_by);
        $this->assertSame('completed', $tracking->fresh()->status);
    }

    public function test_changed_result_tracking_task_rejects_combined_transfer_without_moving_other_work(): void
    {
        $doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $result = InvestigationResult::firstOrFail();
        $tracking = CareTask::findOrFail($result->task_id);
        $ordinary = CareTask::where('title', 'Demo handover coordination task')->firstOrFail();
        $ordinary->update(['owner_id' => $doctor->id]);
        $offer = $this->payload([
            'recipient_id' => $this->lead->id,
            'transfer_tasks' => [$ordinary->only(['id', 'version'])],
            'transfer_results' => [$result->only(['id', 'version'])],
        ]);

        $this->inWorkspace($doctor)->post($this->handovers, $offer)->assertSessionHasNoErrors();
        $handover = EpisodeHandover::where('request_id', $offer['request_id'])->firstOrFail();
        $tracking->increment('version');
        $this->inWorkspace($this->lead)->postJson($this->handovers.'/'.$handover->id.'/acknowledge', [
            'acknowledgement' => 'Synthetic combined acceptance.', 'password' => DemoDataSeeder::PASSWORD,
            'declaration' => true, 'accept_transfer' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('transfer_results');
        $this->assertNull($handover->fresh()->acknowledged_at);
        $this->assertSame($doctor->id, $result->fresh()->effectiveOwnerId());
        $this->assertSame($doctor->id, $ordinary->fresh()->owner_id);
        $this->assertSame($ordinary->version, $ordinary->fresh()->version);
    }

    public function test_nonroutine_or_corrected_results_and_revoked_result_authority_block_transfer(): void
    {
        $doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $result = InvestigationResult::firstOrFail();
        $offer = $this->payload([
            'recipient_id' => $this->lead->id,
            'transfer_results' => [$result->only(['id', 'version'])],
        ]);
        $this->inWorkspace($doctor)->postJson($this->handovers, [...$offer, 'recipient_id' => $this->nurse->id])
            ->assertUnprocessable()->assertJsonValidationErrors('recipient_id');

        $result->update(['source_flag' => 'critical']);
        $this->postJson($this->handovers, $offer)->assertUnprocessable()->assertJsonValidationErrors('transfer_results');
        $result->update(['source_flag' => 'routine']);
        $this->post($this->handovers, $offer)->assertSessionHasNoErrors();
        $handover = EpisodeHandover::where('request_id', $offer['request_id'])->firstOrFail();
        $accept = $this->handovers.'/'.$handover->id.'/acknowledge';
        $ack = ['acknowledgement' => 'Synthetic result transfer acceptance.', 'password' => DemoDataSeeder::PASSWORD, 'declaration' => true, 'accept_transfer' => true];
        $grant = PatientAccessRule::where('user_id', $this->lead->id)->where('patient_id', $this->patient->id)
            ->where('scope', 'results.acknowledge')->firstOrFail();
        $grant->update(['revoked_at' => now()]);
        $this->inWorkspace($this->lead)->postJson($accept, $ack)->assertForbidden();

        $grant->update(['revoked_at' => null]);
        $correction = $result->replicate();
        $correction->fill(['request_id' => Str::uuid()->toString(), 'supersedes_id' => $result->id, 'task_id' => null])->save();
        $this->postJson($accept, $ack)->assertUnprocessable()->assertJsonValidationErrors('transfer_results');
        $this->assertNull($handover->fresh()->acknowledged_at);
        $this->assertSame($doctor->id, $result->fresh()->effectiveOwnerId());
    }
}
