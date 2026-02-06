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
        'preferred_timezone',
        'notification_preferences',
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
            'notification_preferences' => 'array',
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

    /**
     * Check if user has event notifications enabled.
     */
    public function hasEventNotificationsEnabled(): bool
    {
        return $this->notification_preferences['event_notifications'] ?? true;
    }

    /**
     * Check if user has system announcements enabled.
     */
    public function hasSystemAnnouncementsEnabled(): bool
    {
        return $this->notification_preferences['system_announcements'] ?? true;
    }

    /**
     * Get user's preferred timezone or system default.
     */
    public function getTimezone(): string
    {
        return $this->preferred_timezone ?? config('app.timezone');
    }

    /**
     * Get user's initials from first and last name.
     * Falls back to first 2 characters of callsign if names are not available.
     */
    public function getInitials(): string
    {
        $firstInitial = $this->first_name ? mb_substr($this->first_name, 0, 1) : '';
        $lastInitial = $this->last_name ? mb_substr($this->last_name, 0, 1) : '';

        $initials = $firstInitial.$lastInitial;

        // Fallback to callsign if no name is available
        if (empty($initials)) {
            return mb_substr($this->call_sign, 0, 2);
        }

        return mb_strtoupper($initials);
    }
}
