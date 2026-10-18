<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Support\DeveloperMode;
use App\Support\StationMode;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

use function Illuminate\Support\enum_value;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    use HasRoles {
        hasPermissionTo as protected roleBasedHasPermissionTo;
    }

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
        'is_ghost',
        'account_locked_at',
        'approval_requested_at',
        'failed_login_attempts',
        'last_login_at',
        'last_login_ip',
        'password_changed_at',
        'requires_password_change',
        'two_factor_secret',
        'preferred_timezone',
        'notification_preferences',
        'is_youth',
        'is_cpr_aed_trained',
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
            'approval_requested_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'requires_password_change' => 'boolean',
            'is_ghost' => 'boolean',
            'notification_preferences' => 'array',
            'is_youth' => 'boolean',
            'is_cpr_aed_trained' => 'boolean',
        ];
    }

    /**
     * Override the roles relationship accessor to support dev mode role switching.
     */
    public function getRolesAttribute()
    {
        // In dev mode with role override, return the overridden role for the logged-in user only
        if (DeveloperMode::allowsRoleOverride() && session()->has('dev_role_override') && auth()->check() && $this->id === auth()->id()) {
            try {
                $role = Role::findByName(session('dev_role_override'), 'web');

                return collect([$role]);
            } catch (RoleDoesNotExist $e) {
                // Invalid role, fall through to normal relationship
            }
        }

        // Load the actual roles relationship
        if (! $this->relationLoaded('roles')) {
            $this->load('roles');
        }

        return $this->getRelation('roles');
    }

    public const SYSTEM_CALL_SIGN = 'SYSTEM';

    /**
     * Check if this is the built-in system user account.
     */
    public function isSystemUser(): bool
    {
        return $this->call_sign === self::SYSTEM_CALL_SIGN;
    }

    /**
     * Exclude the built-in system user from query results.
     */
    public function scopeExcludeSystem($query)
    {
        return $query->where('call_sign', '!=', self::SYSTEM_CALL_SIGN);
    }

    /**
     * Check if the user is a system administrator.
     */
    public function isSystemAdmin(): bool
    {
        if ($this->isInStationMode()) {
            return false;
        }

        return $this->hasRole('System Administrator');
    }

    /**
     * Check if this user is the operator signed in on a station-mode browser.
     */
    public function isInStationMode(): bool
    {
        return StationMode::isActive() && Auth::id() === $this->id;
    }

    /**
     * Cap permissions while signed in through station mode, so walk-up sessions
     * never carry an account's captain or admin powers and ghosts can still log.
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->isInStationMode()) {
            $name = $permission instanceof Permission ? $permission->name : enum_value($permission);

            return in_array($name, StationMode::ALLOWED_PERMISSIONS, true);
        }

        return $this->roleBasedHasPermissionTo($permission, $guardName);
    }

    /**
     * Check if the user account is currently locked.
     */
    public function isLocked(): bool
    {
        return $this->account_locked_at !== null;
    }

    /**
     * Check if the account is a registration still waiting for an administrator to approve it.
     */
    public function isPendingApproval(): bool
    {
        return $this->isLocked() && $this->approval_requested_at !== null;
    }

    /**
     * Get the user's invitations.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }

    /**
     * Get the user's operating sessions.
     */
    public function operatingSessions(): HasMany
    {
        return $this->hasMany(OperatingSession::class, 'operator_user_id');
    }

    /**
     * Get the user's active operating session.
     */
    public function activeSession(): HasOne
    {
        return $this->hasOne(OperatingSession::class, 'operator_user_id')->whereNull('end_time')->latest();
    }

    /**
     * Get the user's dashboards.
     */
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class);
    }

    /**
     * Get the user's default dashboard.
     */
    public function defaultDashboard(): HasOne
    {
        return $this->hasOne(Dashboard::class)->where('is_default', true);
    }

    /**
     * Check if user is subscribed to a notification category.
     * Defaults to true if the category has not been explicitly configured.
     */
    public function isSubscribedTo(NotificationCategory $category): bool
    {
        $categories = $this->notification_preferences['categories'] ?? [];

        return $categories[$category->value] ?? true;
    }

    /**
     * Get the user's configured bulletin reminder intervals in minutes.
     *
     * @return array<int>
     */
    public function getBulletinReminderMinutes(): array
    {
        $minutes = $this->notification_preferences['bulletin_reminder_minutes'] ?? [15];

        return array_values(array_map('intval', $minutes));
    }

    /**
     * Set the user's bulletin reminder intervals in minutes.
     *
     * @param  array<int>  $minutes
     */
    public function setBulletinReminderMinutes(array $minutes): void
    {
        $preferences = $this->notification_preferences ?? [];
        $preferences['bulletin_reminder_minutes'] = array_values(array_unique(array_map('intval', $minutes)));
        $this->notification_preferences = $preferences;
        $this->save();
    }

    /**
     * Get the user's configured shift check-in reminder intervals in minutes.
     *
     * @return array<int>
     */
    public function getShiftReminderMinutes(): array
    {
        $minutes = $this->notification_preferences['shift_reminder_minutes'] ?? [15];

        return array_values(array_map('intval', $minutes));
    }

    /**
     * Set the user's shift check-in reminder intervals in minutes.
     *
     * @param  array<int>  $minutes
     */
    public function setShiftReminderMinutes(array $minutes): void
    {
        $preferences = $this->notification_preferences ?? [];
        $deduplicated = array_unique(array_map('intval', $minutes));
        sort($deduplicated);
        $preferences['shift_reminder_minutes'] = array_values($deduplicated);
        $this->notification_preferences = $preferences;
        $this->save();
    }

    /**
     * Check if the user has shift reminder email notifications enabled.
     */
    public function hasShiftReminderEmailEnabled(): bool
    {
        return $this->notification_preferences['shift_reminder_email'] ?? false;
    }

    /**
     * Get the effective call sign, respecting dev mode session overrides.
     */
    public function effectiveCallSign(): string
    {
        if (DeveloperMode::allowsRoleOverride() && session()->has('dev_callsign_override')) {
            return session('dev_callsign_override');
        }

        return $this->call_sign;
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

    /**
     * Get the user's equipment.
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'owner_user_id');
    }

    /**
     * Get the user's shift assignments.
     */
    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /**
     * Consider email verified unless the email_verification_required mode is active.
     * This ensures the `verified` middleware is a no-op in open and approval_required modes.
     */
    public function hasVerifiedEmail(): bool
    {
        if (config('auth-security.registration_mode') !== 'email_verification_required' || $this->isInStationMode()) {
            return true;
        }

        return parent::hasVerifiedEmail();
    }

    /**
     * Only send the email verification notification when the email_verification_required
     * registration mode is active. In other modes (open, approval_required), skip it.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (config('auth-security.registration_mode') === 'email_verification_required') {
            parent::sendEmailVerificationNotification();
        }
    }

    /**
     * Normalize call sign to uppercase.
     */
    protected function setCallSignAttribute(?string $value): void
    {
        $this->attributes['call_sign'] = $value ? mb_strtoupper($value) : null;
    }
}
