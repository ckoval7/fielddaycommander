<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperatingClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_type_id',
        'code',
        'name',
        'description',
        'allows_gota',
        'allows_free_vhf',
        'requires_emergency_power',
    ];

    protected function casts(): array
    {
        return [
            'allows_gota' => 'boolean',
            'allows_free_vhf' => 'boolean',
            'requires_emergency_power' => 'boolean',
        ];
    }
}
