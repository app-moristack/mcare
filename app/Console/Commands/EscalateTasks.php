<?php

namespace App\Console\Commands;

use App\Models\CareTask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EscalateTasks extends Command
{
    protected $signature = 'mauricare:escalate-tasks';

    protected $description = 'Durably notify task owners and backups of overdue work';

    public function handle(): int
    {
        CareTask::whereNotIn('status', ['completed', 'cancelled'])->whereNull('escalated_at')->where('due_at', '<', now())->chunkById(100, function ($tasks) {
            foreach ($tasks as $task) {
                DB::transaction(function () use ($task) {
                    $locked = CareTask::whereKey($task->id)->lockForUpdate()->first();
                    if ($locked->escalated_at || in_array($locked->status, ['completed', 'cancelled'])) {
                        return;
                    }
                    foreach (array_unique([$task->owner_id, $task->backup_id]) as $userId) {
                        DB::table('operation_notifications')->insertOrIgnore(['organisation_id' => $task->organisation_id, 'user_id' => $userId, 'message' => 'An assigned task is overdue. Review the task queue.', 'url' => '/workspace/tasks', 'deduplication_key' => 'overdue:'.$task->id.':'.$task->version.':'.$userId, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $locked->update(['escalated_at' => now()]);
                });
            }
        });
        $this->info('Overdue tasks processed.');

        return self::SUCCESS;
    }
}
