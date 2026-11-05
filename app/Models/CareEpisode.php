<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareEpisode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
