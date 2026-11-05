<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    protected $fillable = ['organisation_id', 'user_id', 'role', 'status', 'approved_by', 'starts_at', 'ends_at', 'approval_evidence'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
