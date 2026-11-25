<?php

namespace App\Http\Controllers;

use App\Models\CareEpisode;
use App\Models\EpisodeHandover;
use App\Models\Patient;
use App\Services\EpisodeHandovers;
use App\Services\HandoverResultTransfers;
use App\Services\HandoverTaskTransfers;
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

    private function transferSelection(array $selection): array
    {
        return collect($selection)->map(fn (array $item) => ['id' => (int) $item['id'], 'version' => (int) $item['version']])
            ->sortBy('id')->values()->all();
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
            'transfer_tasks' => 'sometimes|array|list|max:20',
            'transfer_tasks.*' => 'required|array:id,version',
            'transfer_tasks.*.id' => 'required|integer|min:1|distinct',
            'transfer_tasks.*.version' => 'required|integer|min:1',
            'transfer_results' => 'sometimes|array|list|max:20',
            'transfer_results.*' => 'required|array:id,version',
            'transfer_results.*.id' => 'required|integer|min:1|distinct',
            'transfer_results.*.version' => 'required|integer|min:1',
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
            $selection = $this->transferSelection($data['transfer_tasks'] ?? []);
            $resultSelection = $this->transferSelection($data['transfer_results'] ?? []);
            $existing = EpisodeHandover::where('care_episode_id', $record->id)->where('sender_id', $sender)
                ->where('request_id', $data['request_id'])->first();
            if ($existing) {
                $existingSelection = $this->transferSelection($existing->transfer_tasks ?? []);
                $existingResults = $this->transferSelection($existing->transfer_results ?? []);
                abort_unless(
                    $existing->recipient_id === $recipientId && $existing->content === $content
                        && $existing->priority === $data['priority'] && $existing->due_at->equalTo($dueAt)
                        && $existingSelection === $selection && $existingResults === $resultSelection,
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

            $transferTasks = [];
            if ($selection !== []) {
                if (! HandoverTaskTransfers::canAccept($recipient, $patient)) {
                    throw ValidationException::withMessages(['recipient_id' => 'Choose a named clinician with task management permission to accept transferred tasks.']);
                }
                $transferTasks = HandoverTaskTransfers::lock($patient, $sender, $recipient, $selection)
                    ->map(fn ($task) => $task->only(['id', 'version', 'title']))->all();
            }

            $transferResults = [];
            if ($resultSelection !== []) {
                if (! HandoverResultTransfers::canAccept($request->user(), $patient)) {
                    throw ValidationException::withMessages(['transfer_results' => 'Current investigation result authority is required to propose a transfer.']);
                }
                if (! HandoverResultTransfers::canAccept($recipient, $patient)) {
                    throw ValidationException::withMessages(['recipient_id' => 'Choose a current assigned doctor with result follow-up authority.']);
                }
                [$results, $tasks] = HandoverResultTransfers::lock($patient, $sender, $recipient, $resultSelection);
                $transferResults = $results->map(fn ($result) => [
                    'id' => $result->id, 'version' => $result->version, 'title' => HandoverResultTransfers::title($result),
                    'task_id' => $result->task_id, 'task_version' => $tasks[$result->task_id]->version,
                ])->all();
            }

            $handover = EpisodeHandover::create([
                'care_episode_id' => $record->id,
                'sender_id' => $sender,
                'recipient_id' => $recipient->id,
                'request_id' => $data['request_id'],
                'content' => $content,
                'priority' => $data['priority'],
                'due_at' => $dueAt,
                'transfer_tasks' => $transferTasks,
                'transfer_results' => $transferResults,
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

            $transferTasks = $entry->transfer_tasks ?? [];
            $transferResults = $entry->transfer_results ?? [];
            if ($transferTasks !== [] || $transferResults !== []) {
                $request->validate(['accept_transfer' => 'accepted']);
            }

            if ($entry->acknowledged_at !== null) {
                abort_unless($entry->acknowledgement === $data['acknowledgement'], 409, 'The recorded acknowledgement cannot be changed.');

                return;
            }

            if (($transferTasks !== [] || $transferResults !== [])
                && (! EpisodeHandovers::canRead($entry->sender, $patient) || ! PatientAccess::allowed($entry->sender, $patient, 'handover.write'))) {
                $field = $transferTasks !== [] ? 'transfer_tasks' : 'transfer_results';

                throw ValidationException::withMessages([$field => 'The sender no longer has authority for this transfer. Review the outstanding work.']);
            }

            $tasks = null;
            if ($transferTasks !== []) {
                abort_unless(HandoverTaskTransfers::canAccept($request->user(), $patient), 403, 'Current task management permission is required to accept this transfer.');
                $tasks = HandoverTaskTransfers::lock($patient, $entry->sender_id, $request->user(), $transferTasks);
            }
            $results = null;
            if ($transferResults !== []) {
                abort_unless(HandoverResultTransfers::canAccept($request->user(), $patient), 403, 'Current result follow-up authority is required to accept this transfer.');
                if (! HandoverResultTransfers::canAccept($entry->sender, $patient)) {
                    throw ValidationException::withMessages(['transfer_results' => 'The sender no longer has result follow-up authority. Review the outstanding results.']);
                }
                $results = HandoverResultTransfers::lock($patient, $entry->sender_id, $request->user(), $transferResults, verifySignedTask: true);
            }
            if ($tasks) {
                HandoverTaskTransfers::accept($request, $patient, $tasks);
            }
            if ($results) {
                HandoverResultTransfers::accept($request, $patient, $results);
            }

            $entry->update(['acknowledgement' => $data['acknowledgement'], 'acknowledged_at' => now()]);
            DB::table('operation_notifications')->where('deduplication_key', 'handover:'.$entry->id)->update(['read_at' => now()]);
            Workspace::audit($request, 'clinical.handover.acknowledged', $patient->organisation_id, 'episode_handovers', $entry->id);
        });

        return back()->with('success', 'Handover acknowledgement recorded.');
    }
}
