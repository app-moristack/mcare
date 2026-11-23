<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EpisodeDischarge extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array', 'signed_at' => 'datetime'];
    }

    public static function digest(int $episode, int $plan, int $signer, array $content): string
    {
        return hash('sha256', json_encode([$episode, $plan, $signer, $content], JSON_THROW_ON_ERROR));
    }
}
