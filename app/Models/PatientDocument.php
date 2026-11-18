<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'original_name' => 'encrypted',
            'storage_path' => 'encrypted',
            'description' => 'encrypted',
            'review_evidence' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
