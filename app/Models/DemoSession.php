<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DemoSession extends Model
{
    protected $fillable = [
        'session_uuid',
        'role',
        'event_type',
        'invite_token',
        'participant_count',
        'visitor_hash',
        'user_agent',
        'device_type',
        'referrer',
        'ip_country',
        'provisioned_at',
        'last_seen_at',
        'total_page_views',
        'total_actions',
        'was_reset',
        'expires_at',
    ];

    protected $attributes = [
        'total_page_views' => 0,
        'total_actions' => 0,
        'participant_count' => 1,
        'was_reset' => false,
    ];

    protected function casts(): array
    {
        return [
            'provisioned_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'was_reset' => 'boolean',
            'total_page_views' => 'integer',
            'total_actions' => 'integer',
            'participant_count' => 'integer',
        ];
    }

    public function getVisitorIdAttribute(): string
    {
        return substr($this->visitor_hash, 0, 8);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DemoEvent::class)->orderBy('id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(DemoParticipant::class)->orderBy('id');
    }

    /**
     * Whether more people can join this sandbox with its invite link.
     */
    public static function invitesEnabled(): bool
    {
        return self::maxParticipants() > 1;
    }

    /**
     * The most people who may share one sandbox, including whoever provisioned it.
     */
    public static function maxParticipants(): int
    {
        return max(1, (int) config('demo.max_users_per_session', 5));
    }

    /**
     * The session's invite token, generating one the first time it is shared.
     */
    public function ensureInviteToken(): string
    {
        return $this->invite_token ?? $this->rotateInviteToken();
    }

    /**
     * Replace the invite token so links shared earlier stop working.
     */
    public function rotateInviteToken(): string
    {
        $this->update(['invite_token' => Str::random(48)]);

        return $this->invite_token;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isFull(): bool
    {
        return $this->participant_count >= self::maxParticipants();
    }

    /**
     * Admit one more person through the invite link.
     *
     * Seats are never given back: the limit caps how many people a link can
     * ever let in, so a widely shared link cannot fill the sandbox again once
     * earlier guests drift away. The check and the increment happen in one
     * conditional UPDATE so two people joining at once cannot exceed it.
     */
    public function claimSeat(): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->where('participant_count', '<', self::maxParticipants())
            ->increment('participant_count') > 0;

        $this->refresh();

        return $claimed;
    }

    public static function parseDeviceType(string $userAgent): string
    {
        if (preg_match('/Mobile|Android.*Mobile|iPhone|iPod|BlackBerry|Opera Mini|IEMobile/i', $userAgent)) {
            return 'mobile';
        }

        if (preg_match('/iPad|Android(?!.*Mobile)|Tablet/i', $userAgent)) {
            return 'tablet';
        }

        return 'desktop';
    }

    public static function visitorHash(string $ip): string
    {
        return hash('sha256', $ip.config('app.key'));
    }

    /**
     * Analytics always live on the main database, never the per-visitor
     * demo connection that DemoMiddleware swaps in.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('demo.analytics_connection');
    }
}
