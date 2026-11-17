<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class MedicationAdministration extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['administered_at' => 'datetime', 'instruction_snapshot' => 'encrypted:array', 'reason' => 'encrypted', 'notes' => 'encrypted', 'correction_reason' => 'encrypted'];
    }

    public static function digestFor(array $a): string
    {
        return hash('sha256', json_encode(['patient_id' => $a['patient_id'], 'medication_instruction_id' => $a['medication_instruction_id'], 'appointment_id' => $a['appointment_id'], 'administrator_id' => $a['administrator_id'], 'supersedes_id' => $a['supersedes_id'] ?? null, 'administered_at' => CarbonImmutable::parse($a['administered_at'])->utc()->toISOString(), 'outcome' => $a['outcome'], 'instruction_snapshot' => $a['instruction_snapshot'], 'reason' => $a['reason'] ?? null, 'notes' => $a['notes'] ?? null, 'correction_reason' => $a['correction_reason'] ?? null], JSON_THROW_ON_ERROR));
    }

    public function digest(): string
    {
        return self::digestFor(['patient_id' => $this->patient_id, 'medication_instruction_id' => $this->medication_instruction_id, 'appointment_id' => $this->appointment_id, 'administrator_id' => $this->administrator_id, 'supersedes_id' => $this->supersedes_id, 'administered_at' => $this->administered_at, 'outcome' => $this->outcome, 'instruction_snapshot' => $this->instruction_snapshot, 'reason' => $this->reason, 'notes' => $this->notes, 'correction_reason' => $this->correction_reason]);
    }

    public function administrator()
    {
        return $this->belongsTo(User::class, 'administrator_id');
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function instruction()
    {
        return $this->belongsTo(MedicationInstruction::class, 'medication_instruction_id');
    }
}
