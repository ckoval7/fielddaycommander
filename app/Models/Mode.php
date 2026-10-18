<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Database\Factories\ModeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mode extends Model
{
    /** @use HasFactory<ModeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'points_fd',
        'points_wfd',
        'description',
    ];
}
