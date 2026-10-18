<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * Scopes the default cache store to a single demo database.
 *
 * Every demo database shares the same IDs (event 1, setting keys, ...), so
 * an unscoped cache serves one visitor's widgets and settings to another.
 * Scoping swaps the default store for a copy of the shared store that keeps
 * its entries apart: its own directory for the file driver, its own key
 * prefix for every other driver.
 */
class DemoCache
{
    /**
     * Name of the per-session store that becomes the default cache store.
     */
    public const STORE = 'demo_session';

    /**
     * Point the default cache store at the given demo database's scope.
     */
    public static function scopeTo(string $dbName): void
    {
        $storeConfig = config('cache.stores.'.config('demo.shared_cache_store'));

        if (($storeConfig['driver'] ?? null) === 'file') {
            $storeConfig['path'] = self::filePath($storeConfig, $dbName);
            $storeConfig['lock_path'] = $storeConfig['path'];
        }

        $storeConfig['prefix'] = config('cache.prefix').$dbName.':';

        Config::set('cache.stores.'.self::STORE, $storeConfig);
        Config::set('cache.default', self::STORE);
        Cache::forgetDriver(self::STORE);
    }

    /**
     * Remove a dropped demo database's cache entries.
     *
     * Only the file driver keeps a session's entries apart from everyone
     * else's; other drivers rely on the entries' own TTLs to expire.
     */
    public static function forget(string $dbName): void
    {
        $storeConfig = config('cache.stores.'.config('demo.shared_cache_store'));

        if (($storeConfig['driver'] ?? null) === 'file') {
            File::deleteDirectory(self::filePath($storeConfig, $dbName));
        }
    }

    /**
     * @param  array{path: string}  $storeConfig
     */
    private static function filePath(array $storeConfig, string $dbName): string
    {
        return rtrim($storeConfig['path'], '/').'/'.$dbName;
    }
}
