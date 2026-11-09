<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which visitor database a relay's enrollment code or token belongs to.
 *
 * Relay API requests carry only a bearer token or an enrollment code, never
 * the demo_session cookie, so this is how they find their sandbox. Only the
 * secret's hash is kept, the same hash the visitor database stores.
 */
class DemoRelayRoute extends Model
{
    protected $fillable = [
        'secret_hash',
        'database',
    ];

    /**
     * Lives on the main database, never the per-visitor demo connection.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('demo.analytics_connection');
    }

    /**
     * Record that a secret's hash belongs to a visitor database.
     */
    public static function remember(string $secretHash, string $database): void
    {
        static::query()->updateOrCreate(['secret_hash' => $secretHash], ['database' => $database]);
    }

    /**
     * The visitor database a secret's hash belongs to, if it is known.
     */
    public static function databaseFor(string $secretHash): ?string
    {
        return static::query()->where('secret_hash', $secretHash)->value('database');
    }
}
