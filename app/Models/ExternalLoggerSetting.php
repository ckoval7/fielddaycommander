<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalLoggerSetting extends Model
{
    protected $fillable = [
        'event_configuration_id',
        'listener_type',
        'is_enabled',
        'port',
        'pid',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function eventConfiguration(): BelongsTo
    {
        return $this->belongsTo(EventConfiguration::class);
    }
}
