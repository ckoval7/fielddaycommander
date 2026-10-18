<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\DashboardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dashboard Model
 *
 * Represents a customizable dashboard configuration with widget layouts.
 * Each user can have multiple dashboards with one marked as default.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property array $config Array of widget configurations
 * @property bool $is_default Whether this is the user's default dashboard
 * @property string $layout_type Layout type (grid, list, etc.)
 * @property string|null $description Optional dashboard description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class Dashboard extends Model
{
    /** @use HasFactory<DashboardFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'config',
        'is_default',
        'layout_type',
        'description',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_default' => 'boolean',
        ];
    }

    /**
     * Get the user that owns this dashboard.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include default dashboards.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to dashboards for a specific user.
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Get the number of visible widgets in this dashboard.
     */
    public function getVisibleWidgetCount(): int
    {
        return collect($this->config)
            ->filter(fn ($widget) => ($widget['visible'] ?? true))
            ->count();
    }
}
