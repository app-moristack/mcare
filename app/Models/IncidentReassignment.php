<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentReassignment extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'changed_at' => 'datetime', 'previous_assigned_at' => 'datetime'];
    }

    public function previousReviewer()
    {
        return $this->belongsTo(User::class, 'previous_reviewer_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
