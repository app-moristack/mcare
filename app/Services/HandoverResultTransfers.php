<?php

namespace App\Services;

use App\Models\CareTask;
use App\Models\InvestigationResult;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HandoverResultTransfers
{
    private const OPEN_TASK_STATUSES = ['new', 'acknowledged', 'in_progress', 'waiting', 'reopened'];

    public static function canAccept(User $user, Patient $patient): bool
    {
        return PatientAccess::allowed($user, $patient, 'investigations.read')
            && PatientAccess::allowed($user, $patient, 'results.read')
            && PatientAccess::allowed($user, $patient, 'results.acknowledge');
    }

    public static function available(Patient $patient, int $sender): Builder
    {
        return InvestigationResult::where('patient_id', $patient->id)
            ->where(fn ($query) => $query->where('follow_up_owner_id', $sender)
                ->orWhere(fn ($original) => $original->whereNull('follow_up_owner_id')->where('owner_id', $sender)))
            ->where('source_flag', 'routine')->whereIn('status', ['pending', 'acknowledged'])
            ->whereNotIn('id', InvestigationResult::where('patient_id', $patient->id)->whereNotNull('supersedes_id')->select('supersedes_id'))
            ->whereHas('trackingTask', fn ($query) => $query
                ->where('organisation_id', $patient->organisation_id)->where('patient_id', $patient->id)
                ->where('owner_id', $sender)->whereColumn('care_tasks.backup_id', 'investigation_results.backup_id')
                ->whereIn('status', self::OPEN_TASK_STATUSES));
    }

    public static function isOutstanding(InvestigationResult $result): bool
    {
        return $result->status === 'pending' || ($result->acknowledgement['follow_up_required'] ?? null) === 'yes';
    }

    public static function title(InvestigationResult $result): string
    {
        return 'Investigation result #'.$result->id;
    }

    public static function lock(Patient $patient, int $sender, User $recipient, array $selection, bool $verifySignedTask = false): array
    {
        $selected = collect($selection)->keyBy('id');
        $results = self::available($patient, $sender)->whereKey($selected->keys()->all())->orderBy('id')->lockForUpdate()->get();
        $tasks = CareTask::whereKey($results->pluck('task_id')->all())
            ->where('organisation_id', $patient->organisation_id)->where('patient_id', $patient->id)
            ->where('owner_id', $sender)->whereIn('status', self::OPEN_TASK_STATUSES)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $stale = $results->contains(function (InvestigationResult $result) use ($selected, $tasks, $verifySignedTask) {
            $signed = $selected[$result->id];
            $task = $tasks[$result->task_id] ?? null;

            return ! $task || $task->backup_id !== $result->backup_id || $result->version !== $signed['version']
                || ! self::isOutstanding($result) || ! hash_equals($result->content_hash, $result->digest())
                || ($verifySignedTask && ($task->id !== $signed['task_id'] || $task->version !== $signed['task_version']));
        });
        if ($results->count() !== $selected->count() || $tasks->count() !== $results->count() || $stale) {
            throw ValidationException::withMessages(['transfer_results' => 'One or more results or tracking tasks changed or are no longer eligible. Review the result queue before sending a new proposal.']);
        }

        $backupIds = $results->pluck('backup_id')->unique();
        $activeBackups = Workspace::staff($patient->organisation_id)->whereKey($backupIds->all())->get()
            ->filter(fn (User $backup) => $backup->hasPermission('tasks.manage', $patient->organisation_id));
        if ($backupIds->contains($recipient->id) || $activeBackups->count() !== $backupIds->count()) {
            throw ValidationException::withMessages(['transfer_results' => 'Each result needs an active operational backup distinct from its proposed owner.']);
        }

        return [$results, $tasks];
    }

    public static function accept(Request $request, Patient $patient, array $locked): void
    {
        [$results, $tasks] = $locked;
        HandoverTaskTransfers::accept($request, $patient, $tasks);
        foreach ($results as $result) {
            $oldOwner = $result->effectiveOwnerId();
            $result->update(['follow_up_owner_id' => $request->user()->id, 'version' => $result->version + 1]);
            DB::table('operation_notifications')->where('organisation_id', $patient->organisation_id)
                ->where('user_id', $oldOwner)->whereNull('read_at')
                ->where(fn ($query) => $query->where('deduplication_key', 'result-received:'.$result->id.':'.$oldOwner)
                    ->orWhere('deduplication_key', 'like', 'result-transferred:'.$result->id.':%'))
                ->update(['read_at' => now()]);
            DB::table('operation_notifications')->insert([
                'organisation_id' => $patient->organisation_id,
                'user_id' => $request->user()->id,
                'message' => 'An investigation result was transferred to you for follow-up.',
                'url' => '/patient-records/'.$patient->id.'/investigations',
                'deduplication_key' => 'result-transferred:'.$result->id.':'.$result->version,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Workspace::audit($request, 'clinical.handover.result_transferred', $patient->organisation_id, 'investigation_results', $result->id);
        }
    }
}
