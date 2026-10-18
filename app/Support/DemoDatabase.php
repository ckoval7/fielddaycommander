<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tracks and switches which visitor database the 'demo' connection points at.
 *
 * Requests (DemoMiddleware), the activity simulator and queued jobs all
 * activate a visitor's database the same way so the connection, the cache
 * scope and the permission cache never disagree about whose data is loaded.
 */
class DemoDatabase
{
    /**
     * Prevent instantiation — this class exposes only static helpers.
     */
    private function __construct() {}

    /**
     * Check whether a schema name belongs to a demo visitor (not demo_base).
     */
    public static function isVisitorDatabase(?string $dbName): bool
    {
        return $dbName !== null && preg_match('/^demo_[a-f0-9_]{32,40}$/', $dbName) === 1;
    }

    /**
     * The visitor database the 'demo' connection currently points at, if any.
     */
    public static function current(): ?string
    {
        if (! config('demo.enabled')) {
            return null;
        }

        $dbName = config('database.connections.demo.database');

        return self::isVisitorDatabase($dbName) ? $dbName : null;
    }

    /**
     * Point the 'demo' connection, cache and permission cache at a visitor's database.
     *
     * Does nothing when the database is already active, so reconnecting never
     * drops an open transaction mid-request (e.g. a sync queued job).
     */
    public static function activate(string $dbName): void
    {
        if (config('database.connections.demo.database') === $dbName) {
            return;
        }

        Config::set('database.connections.demo.database', $dbName);
        DB::purge('demo');

        // Every demo DB reuses the same IDs, so cached data must be kept per visitor
        DemoCache::scopeTo($dbName);

        // Clear Spatie permission cache so it re-loads from the demo DB
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
