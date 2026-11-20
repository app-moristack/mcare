<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceEvent extends Model
{
    protected $table = 'attendance_events';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function review()
    {
        return $this->hasOne(AttendanceReview::class);
    }

    public function corrections()
    {
        return $this->hasMany(AttendanceCorrection::class);
    }
}
