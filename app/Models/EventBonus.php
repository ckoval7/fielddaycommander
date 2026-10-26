<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\EventBonusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventBonus extends Model
{
    /** @use HasFactory<EventBonusFactory> */
    use HasFactory;

    protected $fillable = [
        'event_configuration_id',
        'bonus_type_id',
        'claimed_by_user_id',
        'quantity',
        'manual_quantity_adjustment',
        'calculated_points',
        'notes',
        'proof_file_path',
        'is_verified',
        'invalidated_by_rescore',
        'verified_by_user_id',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'invalidated_by_rescore' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Points this claim is worth: its calculated points, or, for a pending
     * claim stored before anything was calculated, what verifying it would
     * award (quantity × base points, capped at the type's maximum).
     */
    public function claimedPoints(): int
    {
        if ((int) $this->calculated_points > 0) {
            return (int) $this->calculated_points;
        }

        $bonusType = $this->bonusType;

        if (! $bonusType) {
            return 0;
        }

        $points = max(1, (int) ($this->quantity ?? 1)) * (int) $bonusType->base_points;

        return $bonusType->max_points !== null ? min($points, (int) $bonusType->max_points) : $points;
    }

    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }

    public function bonusType(): BelongsTo
    {
        return $this->belongsTo(BonusType::class);
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
