<?php

namespace Tests\Feature;

use App\Models\CareEpisode;
use App\Models\EpisodeHandover;
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
            ->assertInertia(fn (Assert $page) => $page->where('handover.recipients', [$this->nurse->only(['id', 'name'])]));
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
        $doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
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
        $doctor = User::where('email', 'demo.doctor@example.test')->firstOrFail();
        $grant = PatientAccessRule::where('user_id', $this->nurse->id)->where('patient_id', $this->patient->id)
            ->where('scope', 'handover.read')->firstOrFail()->replicate();
        $grant->fill(['user_id' => $doctor->id, 'request_id' => Str::uuid()->toString()])->save();
        $this->inWorkspace($doctor)->get($this->clinicalPage)
            ->assertInertia(fn (Assert $page) => $page->where('handover.canCreate', false)->has('handover.records', 0));

        $this->actingAs(User::findOrFail($this->patient->user_id))->get('/my-care/patients/'.$this->patient->id.'/clinical')
            ->assertInertia(fn (Assert $page) => $page->missing('handover'))
            ->assertDontSee('SYNTHETIC handover for a demo episode.');
    }
}
