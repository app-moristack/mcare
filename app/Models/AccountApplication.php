<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountApplication extends Model
{
    protected $fillable = ['user_id', 'kind', 'status', 'organisation_id', 'discipline', 'credential_reference', 'credential_expires_at'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [];
    }
}
