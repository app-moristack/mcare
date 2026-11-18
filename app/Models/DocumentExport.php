<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentExport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'document_ids' => 'encrypted:array',
            'purpose' => 'encrypted',
            'review_evidence' => 'encrypted',
            'archive_path' => 'encrypted',
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
