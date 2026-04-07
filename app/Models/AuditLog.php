<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null; // Only created_at, no updated_at

    public const ACTION_LABELS = [
        // Authentication
        'user.login.success' => 'Logged in',
        'user.login.failed' => 'Failed login attempt',
        'user.login.2fa_failed' => 'Failed 2FA verification',
        'user.2fa.challenge' => '2FA challenge',
        'user.logout' => 'Logged out',
        'user.register' => 'User registered',

        // User Management
        'user.created' => 'User created',
        'user.updated' => 'User updated',
        'user.deleted' => 'User deleted',
        'user.locked' => 'Account locked',
        'user.unlocked' => 'Account unlocked',
        'user.password.changed' => 'Password changed',
        'user.password.reset' => 'Password reset',
        'user.password.reset_by_admin' => 'Password reset by admin',
        'user.password.force_reset' => 'Force password reset required',
        'user.invitation.sent' => 'Invitation sent',
        'user.profile.updated' => 'Profile updated',
        'user.2fa.enabled' => '2FA enabled',
        'user.2fa.disabled' => '2FA disabled',
        'user.2fa.recovery_code_used' => 'Recovery code used',
        'user.2fa.reset_by_admin' => '2FA reset by admin',

        // Roles & Permissions
        'role.created' => 'Role created',
        'role.updated' => 'Role updated',
        'role.deleted' => 'Role deleted',
        'role.assigned' => 'Role assigned',
        'role.removed' => 'Role removed',
        'permission.granted' => 'Permission granted',
        'permission.revoked' => 'Permission revoked',

        // Settings
        'settings.updated' => 'Settings updated',
        'settings.branding.updated' => 'Branding updated',

        // System
        'system.setup.completed' => 'System setup completed',
        'config.security.changed' => 'Security config changed',

        // Events
        'event.created' => 'Event created',
        'event.updated' => 'Event updated',
        'event.deleted' => 'Event deleted',
        'event.activated' => 'Event activated',
        'event.deactivated' => 'Event deactivated',

        // Developer Tools
        'developer.time_travel.set' => 'Time travel set',
        'developer.time_travel.clear' => 'Time travel cleared',
        'developer.database.full_reset' => 'Database full reset',
        'developer.database.selective_reset' => 'Database selective reset',
        'developer.snapshot.create' => 'Snapshot created',
        'developer.snapshot.restore' => 'Snapshot restored',
        'developer.snapshot.delete' => 'Snapshot deleted',
        'developer.test_users.initialize' => 'Test users initialized',
        'developer.test_users.clear' => 'Test users cleared',
        'developer.quick_action.seed_contacts' => 'Test contacts seeded',
        'developer.quick_action.fast_forward_event' => 'Fast-forwarded to event',
        'developer.quick_action.clear_caches' => 'Caches cleared',

        // Bulletins
        'bulletin.created' => 'Bulletin created',
        'bulletin.updated' => 'Bulletin updated',
        'bulletin.deleted' => 'Bulletin deleted',

        // Safety Checklist
        'safety.item.created' => 'Safety item created',
        'safety.item.updated' => 'Safety item updated',
        'safety.item.deleted' => 'Safety item deleted',
        'safety.item.toggled' => 'Safety item toggled',

        // Shifts
        'shift.signup' => 'Shift signup',
        'shift.signup.cancelled' => 'Shift signup cancelled',
        'shift.checkin' => 'Shift check-in',
        'shift.checkout' => 'Shift check-out',
        'shift.assigned' => 'Shift assigned',
        'shift.removed' => 'Shift assignment removed',
        'shift.confirmed' => 'Shift confirmed',
        'shift.revoked' => 'Shift confirmation revoked',
        'shift.no_show' => 'Shift no-show',
        'shift.manager_checkin' => 'Manager check-in',
        'shift.manager_checkout' => 'Manager check-out',
        'shift.role.created' => 'Shift role created',
        'shift.role.updated' => 'Shift role updated',
        'shift.role.deleted' => 'Shift role deleted',
        'shift.created' => 'Shift created',
        'shift.updated' => 'Shift updated',
        'shift.deleted' => 'Shift deleted',
        'shift.bulk_created' => 'Shifts bulk created',

        // Bonuses
        'bonus.claimed' => 'Bonus claimed',
        'bonus.unclaimed' => 'Bonus unclaimed',

        // Equipment
        'equipment.created' => 'Equipment created',
        'equipment.updated' => 'Equipment updated',
        'equipment.deleted' => 'Equipment deleted',
        'equipment.assigned' => 'Equipment assigned',
        'equipment.unassigned' => 'Equipment unassigned',

        // Gallery
        'album.export.requested' => 'Album export requested',
        'album.export.downloaded' => 'Album export downloaded',
    ];

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'is_critical',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'is_critical' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }

    public function getParsedUserAgentAttribute(): array
    {
        $userAgent = $this->user_agent ?? '';

        // Simple browser/version parsing
        $browser = 'Unknown';
        $os = 'Unknown';

        // Browser detection
        if (preg_match('/Chrome\/(\d+)/', $userAgent, $matches)) {
            $browser = 'Chrome '.$matches[1];
        } elseif (preg_match('/Firefox\/(\d+)/', $userAgent, $matches)) {
            $browser = 'Firefox '.$matches[1];
        } elseif (preg_match('/Safari\/(\d+)/', $userAgent, $matches)) {
            $browser = 'Safari '.$matches[1];
        } elseif (preg_match('/Edge\/(\d+)/', $userAgent, $matches)) {
            $browser = 'Edge '.$matches[1];
        } elseif (preg_match('/MSIE (\d+)/', $userAgent, $matches)) {
            $browser = 'Internet Explorer '.$matches[1];
        }

        // OS detection
        if (preg_match('/Windows NT ([\d.]+)/', $userAgent, $matches)) {
            $os = 'Windows '.$matches[1];
        } elseif (preg_match('/Mac OS X ([\d._]+)/', $userAgent, $matches)) {
            $os = 'macOS '.$matches[1];
        } elseif (preg_match('/Linux/', $userAgent)) {
            $os = 'Linux';
        } elseif (preg_match('/iPhone/', $userAgent)) {
            $os = 'iOS';
        } elseif (preg_match('/Android/', $userAgent)) {
            $os = 'Android';
        }

        return [
            'browser' => $browser,
            'os' => $os,
        ];
    }

    public function scopeForUser(Builder $query, array $userIds): Builder
    {
        return empty($userIds) ? $query : $query->whereIn('user_id', $userIds);
    }

    public function scopeForAction(Builder $query, array $actions): Builder
    {
        return empty($actions) ? $query : $query->whereIn('action', $actions);
    }

    public function scopeDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from !== null) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query;
    }

    public function scopeForIpAddress(Builder $query, ?string $ip): Builder
    {
        return $ip !== null ? $query->where('ip_address', 'LIKE', '%'.$ip.'%') : $query;
    }

    public static function log(
        string $action,
        ?int $userId = null,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        bool $isCritical = false
    ): void {
        if (! config('auth-security.audit_logging_enabled')) {
            return;
        }

        static::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip() ?? '127.0.0.1',
            'user_agent' => request()->userAgent(),
            'is_critical' => $isCritical,
        ]);
    }
}
