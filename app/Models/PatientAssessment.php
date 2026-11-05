<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientAssessment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'amendment_reason' => 'encrypted', 'confirmation_evidence' => 'encrypted', 'submitted_at' => 'datetime', 'signed_at' => 'datetime', 'cosigned_at' => 'datetime', 'cosign_required' => 'boolean', 'review_due' => 'date:Y-m-d'];
    }

    public function digest(array $content): string
    {
        return hash('sha256', json_encode(['schema' => $this->schema_version, 'kind' => $this->kind, 'patient_id' => $this->patient_id, 'appointment_id' => $this->appointment_id, 'previous_id' => $this->previous_id, 'content' => $content], JSON_THROW_ON_ERROR));
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
