<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChecklistTemplate extends Model
{
    protected $guarded = ['id'];

    public function currentPublication(): HasOne
    {
        return $this->hasOne(ChecklistVersion::class)->ofMany(['version' => 'max'], fn ($query) => $query->where('status', 'published'));
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ChecklistVersion::class);
    }
}
