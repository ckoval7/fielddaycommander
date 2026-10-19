<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventType extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'is_scored',
        'setup_offset_hours',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_scored' => 'boolean',
        ];
    }

    /**
     * Whether events of this type are scored as a contest.
     *
     * Unscored types (public service, nets, drills) hide scoring, bonuses,
     * Cabrillo and the section map, and do not require contest fields.
     */
    public function isScored(): bool
    {
        return $this->is_scored ?? true;
    }
}
