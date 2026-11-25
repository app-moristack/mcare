<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisitChecklist extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'responses' => 'encrypted:array', 'attached_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    public function sourceVersion()
    {
        return $this->belongsTo(ChecklistVersion::class, 'checklist_version_id');
    }

    public function forPage(bool $canSubmit = false): array
    {
        return [
            ...$this->only(['id', 'checklist_version_id', 'title', 'version', 'steps', 'attached_at', 'submitted_at', 'responses']),
            'can_submit' => $canSubmit && $this->submitted_at === null,
        ];
    }

    public function digest(array $responses, int $signerId): string
    {
        return hash('sha256', json_encode([
            'appointment_id' => $this->appointment_id,
            'checklist_version_id' => $this->checklist_version_id,
            'source_hash' => $this->source_hash,
            'title' => $this->title,
            'version' => $this->version,
            'steps' => $this->steps,
            'signer_id' => $signerId,
            'responses' => $responses,
        ], JSON_THROW_ON_ERROR));
    }
}
