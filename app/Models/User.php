<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $attributes = ['account_status' => 'active', 'account_kind' => 'self_patient', 'identity_status' => 'unverified', 'preferred_language' => 'en', 'time_zone' => 'Indian/Mauritius'];

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    public function patientProfile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    public function application(): HasOne
    {
        return $this->hasOne(AccountApplication::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function hasPermission(string $ability, ?int $organisationId = null): bool
    {
        if ($this->account_status !== 'active' || ! $this->hasVerifiedEmail()
            || ! in_array($ability, config('permissions.abilities'), true)) {
            return false;
        }

        return $this->memberships()
            ->whereIn('role', collect(config('permissions.grants'))->filter(fn ($abilities) => in_array('*', $abilities, true) || in_array($ability, $abilities, true))->keys())
            ->where('status', 'active')
            ->whereNotNull('approved_by')
            ->where('starts_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->when($organisationId !== null, fn ($query) => $query->where('organisation_id', $organisationId))
            ->exists();
    }

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_last_counter',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_acknowledged_at' => 'datetime',
        ];
    }
}
