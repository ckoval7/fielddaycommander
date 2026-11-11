<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Support;

use Illuminate\Foundation\Application;

/**
 * Keeps everything the app writes outside the code folder when FDC_DATA_PATH
 * is set: the .env file, storage/ and the bootstrap cache files. The Windows
 * server installs the code read-only under Program Files and its data under
 * ProgramData, so an upgrade can replace the code folder wholesale.
 */
final class DataDirectory
{
    /**
     * The bootstrap cache files Laravel lets an env var relocate, and their names.
     *
     * @var array<string, string>
     */
    private const array CACHE_FILES = [
        'APP_CONFIG_CACHE' => 'config.php',
        'APP_EVENTS_CACHE' => 'events.php',
        'APP_PACKAGES_CACHE' => 'packages.php',
        'APP_ROUTES_CACHE' => 'routes-v7.php',
        'APP_SERVICES_CACHE' => 'services.php',
    ];

    private function __construct()
    {
        // Static helpers only.
    }

    /**
     * Point the app at the data folder, if one is configured.
     */
    public static function apply(Application $app, ?string $path = null): void
    {
        $path ??= self::configuredPath();

        if ($path === null) {
            return;
        }

        $path = rtrim($path, '/\\');

        // Laravel only treats cache paths starting with / or \ as absolute,
        // which misses Windows drive paths like C:\ProgramData.
        $app->addAbsoluteCachePathPrefix($path);

        $app->useEnvironmentPath($path);
        $app->useStoragePath($path.DIRECTORY_SEPARATOR.'storage');

        // Laravel reads these through $_SERVER. putenv() is avoided because
        // FrankenPHP runs requests on threads that share one environment.
        foreach (self::CACHE_FILES as $key => $file) {
            if (! isset($_SERVER[$key]) && getenv($key) === false) {
                $_SERVER[$key] = $path.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.$file;
            }
        }
    }

    private static function configuredPath(): ?string
    {
        $path = $_SERVER['FDC_DATA_PATH'] ?? $_ENV['FDC_DATA_PATH'] ?? getenv('FDC_DATA_PATH');

        return is_string($path) && $path !== '' ? $path : null;
    }
}
