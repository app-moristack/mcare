<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MissedVisitCase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'missed_reason' => 'encrypted', 'welfare_evidence' => 'encrypted', 'replacement_evidence' => 'encrypted', 'resolution_evidence' => 'encrypted',
            'reported_at' => 'datetime', 'welfare_checked_at' => 'datetime', 'due_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function replacement()
    {
        return $this->belongsTo(Appointment::class, 'replacement_appointment_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function backup()
    {
        return $this->belongsTo(User::class, 'backup_id');
    }
}
