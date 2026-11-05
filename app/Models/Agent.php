<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Field Day Commander Agent install, allowed to send contacts to one event.
 * It starts with a one-time enrollment code, which it trades for a token.
 */
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    protected $fillable = [
        'event_configuration_id',
        'name',
        'enrollment_code_hash',
        'enrollment_expires_at',
        'token_hash',
        'agent_version',
        'created_by_user_id',
        'enrolled_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $hidden = [
        'enrollment_code_hash',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the event configuration this agent sends contacts to.
     */
    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    /**
     * Get the user who issued this agent's enrollment code.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Scope to agents that have not been revoked.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Hash a raw token or enrollment code for storage and lookup.
     */
    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
