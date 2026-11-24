<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EpisodeHandover extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'transfer_tasks' => 'encrypted:array', 'acknowledgement' => 'encrypted', 'acknowledged_at' => 'datetime', 'due_at' => 'datetime'];
    }

    public function episode()
    {
        return $this->belongsTo(CareEpisode::class, 'care_episode_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
