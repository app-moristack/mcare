<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountApplication extends Model
{
    protected $fillable = ['user_id', 'kind', 'status'];

    protected function casts(): array
    {
        return [];
    }
}
