<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationalIncident extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'description' => 'encrypted',
            'resolution' => 'encrypted',
            'reported_at' => 'datetime',
            'assigned_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function reassignments()
    {
        return $this->hasMany(IncidentReassignment::class)->orderBy('previous_version');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
