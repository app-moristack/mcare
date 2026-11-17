<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class Investigation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'details' => 'encrypted:array'];
    }

    public static function digestFor(array $a): string
    {
        return hash('sha256', json_encode(['patient_id' => $a['patient_id'], 'requester_id' => $a['requester_id'], 'requested_at' => CarbonImmutable::parse($a['requested_at'])->utc()->toISOString(), 'category' => $a['category'], 'details' => $a['details']], JSON_THROW_ON_ERROR));
    }

    public function digest(): string
    {
        return self::digestFor(['patient_id' => $this->patient_id, 'requester_id' => $this->requester_id, 'requested_at' => $this->requested_at, 'category' => $this->category, 'details' => $this->details]);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
