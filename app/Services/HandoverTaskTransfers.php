<?php

namespace App\Services;

use App\Models\CareTask;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HandoverTaskTransfers
{
    public static function available(Patient $patient, int $sender): Builder
    {
        return CareTask::where('organisation_id', $patient->organisation_id)
            ->where('patient_id', $patient->id)->where('owner_id', $sender)
            ->where('handover_transferable', true)->where('priority', 'routine')
            ->whereIn('status', ['new', 'acknowledged', 'in_progress', 'waiting', 'reopened']);
    }

    public static function canAccept(User $recipient, Patient $patient): bool
    {
        return $recipient->hasPermission('tasks.manage', $patient->organisation_id);
    }

    /**
     * Lock the exact outstanding tasks named in a handover and verify the current backup coverage.
     */
    public static function lock(Patient $patient, int $sender, User $recipient, array $selection): Collection
    {
        $versions = array_column($selection, 'version', 'id');
        $tasks = self::available($patient, $sender)->whereKey(array_keys($versions))->orderBy('id')->lockForUpdate()->get();
        if ($tasks->count() !== count($versions) || $tasks->contains(fn (CareTask $task) => $task->version !== $versions[$task->id])) {
            throw ValidationException::withMessages(['transfer_tasks' => 'One or more tasks changed or are no longer eligible. Review the task list before sending a new proposal.']);
        }

        $backupIds = $tasks->pluck('backup_id')->unique();
        if ($backupIds->contains($recipient->id) || Workspace::staff($patient->organisation_id)->whereKey($backupIds->all())->count() !== $backupIds->count()) {
            throw ValidationException::withMessages(['transfer_tasks' => 'Each task needs an active backup distinct from its proposed new owner.']);
        }

        return $tasks;
    }

    public static function accept(Request $request, Patient $patient, Collection $tasks): void
    {
        foreach ($tasks as $task) {
            $task->update([
                'owner_id' => $request->user()->id,
                'version' => $task->version + 1,
                'escalated_at' => null,
            ]);
            DB::table('operation_notifications')
                ->where('organisation_id', $patient->organisation_id)->whereNull('read_at')
                ->where('deduplication_key', 'like', 'overdue:'.$task->id.':%')->update(['read_at' => now()]);
            Workspace::audit($request, 'clinical.handover.task_transferred', $patient->organisation_id, 'care_tasks', $task->id);
        }
    }
}
