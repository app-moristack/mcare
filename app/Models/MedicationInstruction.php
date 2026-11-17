<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class MedicationInstruction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'details' => 'encrypted:array', 'correction_reason' => 'encrypted'];
    }

    public static function digestFor(array $a): string
    {
        return hash('sha256', json_encode(['patient_id' => $a['patient_id'], 'verifier_id' => $a['verifier_id'], 'supersedes_id' => $a['supersedes_id'] ?? null, 'verified_at' => CarbonImmutable::parse($a['verified_at'])->utc()->toISOString(), 'starts_at' => CarbonImmutable::parse($a['starts_at'])->utc()->toISOString(), 'ends_at' => empty($a['ends_at']) ? null : CarbonImmutable::parse($a['ends_at'])->utc()->toISOString(), 'status' => $a['status'], 'details' => $a['details'], 'correction_reason' => $a['correction_reason'] ?? null], JSON_THROW_ON_ERROR));
    }

    public function digest(): string
    {
        return self::digestFor(['patient_id' => $this->patient_id, 'verifier_id' => $this->verifier_id, 'supersedes_id' => $this->supersedes_id, 'verified_at' => $this->verified_at, 'starts_at' => $this->starts_at, 'ends_at' => $this->ends_at, 'status' => $this->status, 'details' => $this->details, 'correction_reason' => $this->correction_reason]);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }
}
