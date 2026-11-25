<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $table = 'appointments';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'offered_at' => 'datetime', 'offer_responded_at' => 'datetime', 'offer_evidence' => 'encrypted'];
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function incidents()
    {
        return $this->hasMany(OperationalIncident::class, 'visit_id');
    }

    public function checklist()
    {
        return $this->hasOne(VisitChecklist::class);
    }

    public function canSubmitChecklist(): bool
    {
        return $this->status === 'arrived' && $this->offer_status === 'accepted'
            && ($this->relationLoaded('attendance')
                ? $this->attendance->contains('kind', 'arrival')
                : $this->attendance()->where('kind', 'arrival')->exists());
    }

    public function attendance()
    {
        return $this->hasMany(AttendanceEvent::class);
    }
}
