<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'payments';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
