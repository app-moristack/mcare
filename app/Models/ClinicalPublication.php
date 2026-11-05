<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClinicalPublication extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'published_at' => 'datetime'];
    }

    public function note()
    {
        return $this->belongsTo(ClinicalNote::class, 'clinical_note_id');
    }

    public function plan()
    {
        return $this->belongsTo(CarePlan::class, 'care_plan_id');
    }
}
