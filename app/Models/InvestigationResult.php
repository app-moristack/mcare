<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class InvestigationResult extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'source_reported_at' => 'datetime', 'details' => 'encrypted:array', 'correction_reason' => 'encrypted', 'acknowledgement' => 'encrypted:array', 'acknowledged_at' => 'datetime', 'completion' => 'encrypted:array', 'completed_at' => 'datetime'];
    }

    public static function digestFor(array $a): string
    {
        return hash('sha256', json_encode(['patient_id' => $a['patient_id'], 'investigation_id' => $a['investigation_id'], 'received_by' => $a['received_by'], 'owner_id' => $a['owner_id'], 'backup_id' => $a['backup_id'], 'supersedes_id' => $a['supersedes_id'] ?? null, 'received_at' => CarbonImmutable::parse($a['received_at'])->utc()->toISOString(), 'source_reported_at' => empty($a['source_reported_at']) ? null : CarbonImmutable::parse($a['source_reported_at'])->utc()->toISOString(), 'source_flag' => $a['source_flag'], 'details' => $a['details'], 'correction_reason' => $a['correction_reason'] ?? null], JSON_THROW_ON_ERROR));
    }

    public function digest(): string
    {
        return self::digestFor(['patient_id' => $this->patient_id, 'investigation_id' => $this->investigation_id, 'received_by' => $this->received_by, 'owner_id' => $this->owner_id, 'backup_id' => $this->backup_id, 'supersedes_id' => $this->supersedes_id, 'received_at' => $this->received_at, 'source_reported_at' => $this->source_reported_at, 'source_flag' => $this->source_flag, 'details' => $this->details, 'correction_reason' => $this->correction_reason]);
    }

    public function investigation()
    {
        return $this->belongsTo(Investigation::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function effectiveOwnerId(): int
    {
        return $this->follow_up_owner_id ?? $this->owner_id;
    }

    public function followUpOwner()
    {
        return $this->belongsTo(User::class, 'follow_up_owner_id');
    }

    public function trackingTask()
    {
        return $this->belongsTo(CareTask::class, 'task_id');
    }

    public function backup()
    {
        return $this->belongsTo(User::class, 'backup_id');
    }

    public function publication()
    {
        return $this->hasOne(InvestigationPublication::class);
    }
}
