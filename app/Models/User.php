<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'call_sign',
        'first_name',
        'last_name',
        'email',
        'email_verified_at',
        'password',
        'license_class',
        'user_role',
        'account_locked_at',
        'failed_login_attempts',
        'last_login_at',
        'last_login_ip',
        'password_changed_at',
        'requires_password_change',
        'two_factor_secret',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
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
            'account_locked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'requires_password_change' => 'boolean',
            'two_factor_bypass_enabled' => 'boolean',
            'two_factor_bypass_expires_at' => 'datetime',
        ];
    }

    /**
     * Check if the user is a system administrator.
     */
    public function isSystemAdmin(): bool
    {
        return $this->hasRole('system-admin');
    }

    /**
     * Check if the user account is currently locked.
     */
    public function isLocked(): bool
    {
        return $this->account_locked_at !== null;
    }

    /**
     * Check if the user has two-factor authentication enabled.
     */
    public function has2FAEnabled(): bool
    {
        return $this->two_factor_secret !== null;
    }

    /**
     * Get the user's invitations.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }
}
