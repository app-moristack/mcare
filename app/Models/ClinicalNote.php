<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalNote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'addendum_reason' => 'encrypted', 'encounter_at' => 'datetime', 'signed_at' => 'datetime'];
    }

    public function digest(array $content): string
    {
        return hash('sha256', json_encode(['patient_id' => $this->patient_id, 'appointment_id' => $this->appointment_id, 'author_id' => $this->author_id, 'discipline' => $this->discipline, 'note_type' => $this->note_type, 'visibility' => $this->visibility, 'original_id' => $this->original_id, 'addendum_reason' => $this->addendum_reason, 'content' => $content], JSON_THROW_ON_ERROR));
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
