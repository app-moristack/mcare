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
}
