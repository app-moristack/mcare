<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organisation extends Model
{
    protected $fillable = ['name', 'type', 'currency', 'time_zone'];

    protected function casts(): array
    {
        return [];
    }
}
