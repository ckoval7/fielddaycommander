<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pre-migrated demo database waiting to be claimed by a visitor.
 */
class DemoPoolDatabase extends Model
{
    protected $fillable = [
        'session_uuid',
    ];

    /**
     * The pool lives on the main database, never the per-visitor
     * demo connection that DemoMiddleware swaps in.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('demo.analytics_connection');
    }
}
