<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'encrypted', 'reviewed_at' => 'datetime'];
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
