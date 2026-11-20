<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceCorrection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'proposed_occurred_at' => 'datetime', 'reason' => 'encrypted', 'evidence' => 'encrypted',
            'review_evidence' => 'encrypted', 'reviewed_at' => 'datetime',
        ];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function event()
    {
        return $this->belongsTo(AttendanceEvent::class, 'attendance_event_id');
    }
}
