<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\ModeRulePointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModeRulePoint extends Model
{
    /** @use HasFactory<ModeRulePointFactory> */
    use HasFactory;

    protected $fillable = [
        'event_type_id',
        'rules_version',
        'mode_id',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function mode(): BelongsTo
    {
        return $this->belongsTo(Mode::class);
    }
}
