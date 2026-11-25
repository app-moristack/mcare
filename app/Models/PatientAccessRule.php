<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientAccessRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime', 'evidence' => 'encrypted'];
    }

    public function episode()
    {
        return $this->belongsTo(CareEpisode::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
