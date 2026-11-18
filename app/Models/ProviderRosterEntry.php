<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderRosterEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'reviewed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'notes' => 'encrypted', 'review_evidence' => 'encrypted', 'cancellation_reason' => 'encrypted',
        ];
    }

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
