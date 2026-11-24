<?php

namespace App\Http\Controllers;

use App\Models\CareEpisode;
use App\Models\EpisodeHandover;
use App\Models\Patient;
use App\Services\EpisodeHandovers;
use App\Services\PatientAccess;
use App\Services\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EpisodeHandoverController extends Controller
{
    private function patient(Request $request, int $id): Patient
    {
        $organisation = Workspace::selectedOrganisation($request);
        abort_unless(Workspace::memberships($request->user())->contains('organisation_id', $organisation), 404);

        return Patient::where('organisation_id', $organisation)->findOrFail($id);
    }

    private function authorise(Request $request, Patient $patient, string $scope): void
    {
        PatientAccess::enforce($request, $patient, 'plans.read');
        PatientAccess::enforce($request, $patient, 'handover.read');
        PatientAccess::enforce($request, $patient, $scope);
    }

    public function store(Request $request, int $id, int $episode)
    {
        $patient = $this->patient($request, $id);
        $this->authorise($request, $patient, 'handover.write');
        $data = $request->validate([
            'request_id' => 'required|uuid',
            'recipient_id' => 'required|integer',
            'situation' => 'required|string|max:4000',
            'background' => 'required|string|max:4000',
            'assessment' => 'required|string|max:4000',
            'recommendation' => 'required|string|max:4000',
            'priority' => 'required|in:routine,urgent',
            'due_at' => 'required|date_format:Y-m-d\TH:i',
            'password' => 'required|current_password',
            'declaration' => 'accepted',
        ]);

        DB::transaction(function () use ($request, $patient, $episode, $data) {
            $patient = Patient::lockForUpdate()->findOrFail($patient->id);
            $this->authorise($request, $patient, 'handover.write');
            $record = CareEpisode::where('patient_id', $patient->id)->lockForUpdate()->findOrFail($episode);
            $sender = $request->user()->id;
            $recipientId = (int) $data['recipient_id'];
            $content = Arr::only($data, ['situation', 'background', 'assessment', 'recommendation']);
            $dueAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['due_at'], config('mauricare.time_zone'))->utc();
            $existing = EpisodeHandover::where('care_episode_id', $record->id)->where('sender_id', $sender)
                ->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless(
                    $existing->recipient_id === $recipientId && $existing->content === $content
                        && $existing->priority === $data['priority'] && $existing->due_at->equalTo($dueAt),
                    409,
                    'This request already recorded a different handover.'
                );

                return;
            }

            abort_if($record->discharge()->exists(), 409, 'A discharged episode cannot start a new handover.');
            if (! $dueAt->isFuture()) {
                throw ValidationException::withMessages(['due_at' => 'Choose a future acknowledgement deadline.']);
            }

            $recipient = Workspace::staff($patient->organisation_id)->whereKeyNot($sender)->find($recipientId);
            if (! $recipient || ! EpisodeHandovers::canAcknowledge($recipient, $patient)) {
                throw ValidationException::withMessages(['recipient_id' => 'Choose a different assigned clinician who can read and acknowledge this handover.']);
            }

            $handover = EpisodeHandover::create([
                'care_episode_id' => $record->id,
                'sender_id' => $sender,
                'recipient_id' => $recipient->id,
                'request_id' => $data['request_id'],
                'content' => $content,
                'priority' => $data['priority'],
                'due_at' => $dueAt,
            ]);
            DB::table('operation_notifications')->insert([
                'organisation_id' => $patient->organisation_id,
                'user_id' => $recipient->id,
                'message' => 'A clinical handover is awaiting your acknowledgement.',
                'url' => '/patient-records/'.$patient->id.'/clinical?tab=handover',
                'deduplication_key' => 'handover:'.$handover->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Workspace::audit($request, 'clinical.handover.sent', $patient->organisation_id, 'episode_handovers', $handover->id);
        });

        return back()->with('success', 'Handover recorded for the named recipient.');
    }

    public function acknowledge(Request $request, int $id, int $episode, int $handover)
    {
        $patient = $this->patient($request, $id);
        $this->authorise($request, $patient, 'handover.acknowledge');
        $data = $request->validate([
            'acknowledgement' => 'required|string|max:4000',
            'password' => 'required|current_password',
            'declaration' => 'accepted',
        ]);

        DB::transaction(function () use ($request, $patient, $episode, $handover, $data) {
            $patient = Patient::lockForUpdate()->findOrFail($patient->id);
            $this->authorise($request, $patient, 'handover.acknowledge');
            $record = CareEpisode::where('patient_id', $patient->id)->findOrFail($episode);
            $entry = EpisodeHandover::where('care_episode_id', $record->id)
                ->where('recipient_id', $request->user()->id)->lockForUpdate()->findOrFail($handover);

            if ($entry->acknowledged_at !== null) {
                abort_unless($entry->acknowledgement === $data['acknowledgement'], 409, 'The recorded acknowledgement cannot be changed.');

                return;
            }

            $entry->update(['acknowledgement' => $data['acknowledgement'], 'acknowledged_at' => now()]);
            DB::table('operation_notifications')->where('deduplication_key', 'handover:'.$entry->id)->update(['read_at' => now()]);
            Workspace::audit($request, 'clinical.handover.acknowledged', $patient->organisation_id, 'episode_handovers', $entry->id);
        });

        return back()->with('success', 'Handover acknowledgement recorded.');
    }
}
