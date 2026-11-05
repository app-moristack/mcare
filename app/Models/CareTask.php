<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CareTask extends Model
{
    protected $table = 'care_tasks';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'next_check_at' => 'datetime', 'escalated_at' => 'datetime'];
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function backup()
    {
        return $this->belongsTo(User::class, 'backup_id');
    }
}
