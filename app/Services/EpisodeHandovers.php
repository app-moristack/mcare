<?php

namespace App\Services;

use App\Models\EpisodeHandover;
use App\Models\Patient;
use App\Models\PatientAccessRule;
use App\Models\User;
use Illuminate\Http\Request;

class EpisodeHandovers
{
    public static function canRead(User $user, Patient $patient): bool
    {
        return PatientAccess::allowed($user, $patient, 'plans.read')
            && PatientAccess::allowed($user, $patient, 'handover.read');
    }

    public static function canAcknowledge(User $user, Patient $patient): bool
    {
        return self::canRead($user, $patient) && PatientAccess::allowed($user, $patient, 'handover.acknowledge');
    }

    public static function forClinicalPage(Request $request, Patient $patient): ?array
    {
        $user = $request->user();
        if (! self::canRead($user, $patient)) {
            return null;
        }

        $canCreate = PatientAccess::allowed($user, $patient, 'handover.write');
        $canAcknowledge = PatientAccess::allowed($user, $patient, 'handover.acknowledge');
        $recipients = $canCreate
            ? Workspace::staff($patient->organisation_id)
                ->whereKeyNot($user->id)
                ->whereIn('id', PatientAccessRule::where('patient_id', $patient->id)
                    ->where('kind', 'grant')->where('scope', 'handover.acknowledge')
                    ->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now())
                    ->select('user_id'))
                ->orderBy('name')->get(['id', 'name', 'account_status', 'email_verified_at'])
                ->filter(fn (User $candidate) => self::canAcknowledge($candidate, $patient))
                ->map(fn (User $candidate) => [
                    ...$candidate->only(['id', 'name']),
                    'canAcceptTransfers' => HandoverTaskTransfers::canAccept($candidate, $patient),
                ])
                ->values()
            : [];

        $transferTasks = $canCreate ? HandoverTaskTransfers::available($patient, $user->id)->orderBy('id')->get(['id', 'title', 'version']) : [];
        $canAcceptTransfers = HandoverTaskTransfers::canAccept($user, $patient);
        $records = EpisodeHandover::whereHas('episode', fn ($query) => $query->where('patient_id', $patient->id))
            ->where(fn ($query) => $query->where('sender_id', $user->id)->orWhere('recipient_id', $user->id))
            ->with(['sender:id,name', 'recipient:id,name'])
            ->latest('id')->get()->map(fn (EpisodeHandover $record) => [
                'id' => $record->id,
                'episode_id' => $record->care_episode_id,
                'sender' => $record->sender->only(['id', 'name']),
                'recipient' => $record->recipient->only(['id', 'name']),
                'content' => $record->content,
                'priority' => $record->priority,
                'due_at' => $record->due_at,
                'created_at' => $record->created_at,
                'acknowledged_at' => $record->acknowledged_at,
                'acknowledgement' => $record->acknowledgement,
                'transfer_tasks' => collect($record->transfer_tasks)->map(fn (array $task) => ['id' => $task['id'], 'title' => $task['title']])->all(),
                'canAcknowledge' => $canAcknowledge && $record->recipient_id === $user->id && $record->acknowledged_at === null
                    && (empty($record->transfer_tasks) || $canAcceptTransfers),
            ]);

        if ($records->isNotEmpty()) {
            Workspace::audit($request, 'clinical.handover.viewed', $patient->organisation_id, 'patients', $patient->id);
        }

        return ['canCreate' => $canCreate, 'recipients' => $recipients, 'records' => $records, 'transferTasks' => $transferTasks];
    }
}
