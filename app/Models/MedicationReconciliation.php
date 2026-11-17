<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class MedicationReconciliation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reconciled_at' => 'datetime', 'sources' => 'encrypted:array', 'medicines' => 'encrypted:array', 'discrepancies' => 'encrypted', 'follow_up' => 'encrypted', 'correction_reason' => 'encrypted'];
    }

    public static function digestFor(array $a): string
    {
        return hash('sha256', json_encode(['patient_id' => $a['patient_id'], 'appointment_id' => $a['appointment_id'], 'author_id' => $a['author_id'], 'supersedes_id' => $a['supersedes_id'] ?? null, 'reconciled_at' => CarbonImmutable::parse($a['reconciled_at'])->utc()->toISOString(), 'status' => $a['status'], 'sources' => $a['sources'], 'medicines' => $a['medicines'], 'discrepancies' => $a['discrepancies'] ?? null, 'follow_up' => $a['follow_up'] ?? null, 'correction_reason' => $a['correction_reason'] ?? null], JSON_THROW_ON_ERROR));
    }

    public function digest(): string
    {
        return self::digestFor($this->getAttributesForDigest());
    }

    private function getAttributesForDigest(): array
    {
        return ['patient_id' => $this->patient_id, 'appointment_id' => $this->appointment_id, 'author_id' => $this->author_id, 'supersedes_id' => $this->supersedes_id, 'reconciled_at' => $this->reconciled_at, 'status' => $this->status, 'sources' => $this->sources, 'medicines' => $this->medicines, 'discrepancies' => $this->discrepancies, 'follow_up' => $this->follow_up, 'correction_reason' => $this->correction_reason];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }
}
