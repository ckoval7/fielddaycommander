<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemoSession extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'session_uuid',
        'role',
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
}
