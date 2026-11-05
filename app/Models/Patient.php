<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    protected $table = 'patients';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d'];
    }
}
