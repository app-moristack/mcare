<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarePlan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'revision_reason' => 'encrypted', 'signed_at' => 'datetime', 'approved_at' => 'datetime', 'review_due_at' => 'datetime'];
    }

    public function digest(array $content): string
    {
        return hash('sha256', json_encode(['patient_id' => $this->patient_id, 'episode_id' => $this->episode_id, 'author_id' => $this->author_id, 'previous_id' => $this->previous_id, 'revision_number' => $this->revision_number, 'revision_reason' => $this->revision_reason, 'content' => $content], JSON_THROW_ON_ERROR));
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function episode()
    {
        return $this->belongsTo(CareEpisode::class);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
