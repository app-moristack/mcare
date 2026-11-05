<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientProfile extends Model
{
    protected $fillable = ['user_id', 'public_id', 'legal_name', 'date_of_birth', 'mobile_phone', 'locality', 'onboarding_completed_at'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date:Y-m-d', 'onboarding_completed_at' => 'datetime'];
    }
}
