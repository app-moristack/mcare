<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestigationPublication extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'published_at' => 'datetime'];
    }

    public function result()
    {
        return $this->belongsTo(InvestigationResult::class, 'investigation_result_id');
    }
}
