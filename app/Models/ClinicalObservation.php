<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class ClinicalObservation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'measurements' => 'encrypted:array',
            'context' => 'encrypted',
            'correction_reason' => 'encrypted',
            'observed_at' => 'datetime',
        ];
    }

    public function digest(): string
    {
        return self::digestFor([
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'author_id' => $this->author_id,
            'supersedes_id' => $this->supersedes_id,
            'observed_at' => $this->observed_at,
            'measurements' => $this->measurements,
            'context' => $this->context,
            'correction_reason' => $this->correction_reason,
        ]);
    }

    public static function digestFor(array $attributes): string
    {
        return hash('sha256', json_encode([
            'patient_id' => $attributes['patient_id'],
            'appointment_id' => $attributes['appointment_id'],
            'author_id' => $attributes['author_id'],
            'supersedes_id' => $attributes['supersedes_id'] ?? null,
            'observed_at' => CarbonImmutable::parse($attributes['observed_at'])->utc()->toISOString(),
            'measurements' => $attributes['measurements'],
            'context' => $attributes['context'] ?? null,
            'correction_reason' => $attributes['correction_reason'] ?? null,
        ], JSON_THROW_ON_ERROR));
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
