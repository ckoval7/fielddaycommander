<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The .env settings a whole-site backup carries to the server it's restored
 * on: APP_KEY, which two-factor secrets are encrypted with, and mail
 * settings. The same ones restore.sh carries over with --new-server.
 */
class SiteBackupSettings
{
    private const string CARRIED = '/^(APP_KEY|APP_PREVIOUS_KEYS|MAIL_[A-Z0-9_]+)$/';

    /**
     * The carried .env lines from this server, so a new server can read the data.
     */
    public function current(): string
    {
        $lines = collect(preg_split('/\R/', (string) @file_get_contents(app()->environmentFilePath())))
            ->filter(fn (string $line): bool => preg_match(self::CARRIED, Str::before($line, '=')) === 1);

        if (! $lines->contains(fn (string $line): bool => str_starts_with($line, 'APP_KEY='))) {
            $lines->prepend('APP_KEY='.config('app.key'));
        }

        return $lines->implode("\n")."\n";
    }

    /**
     * Write the backup's carried settings into this server's .env, replacing
     * the lines already there and adding any that are missing.
     */
    public function apply(string $settings): void
    {
        $envPath = app()->environmentFilePath();

        if ($settings === '' || ! File::exists($envPath)) {
            return;
        }

        $env = File::get($envPath);

        foreach (preg_split('/\R/', $settings) as $line) {
            $key = Str::before($line, '=');

            if ($line === '' || ! str_contains($line, '=') || preg_match(self::CARRIED, $key) !== 1) {
                continue;
            }

            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $env = preg_match($pattern, $env) === 1
                ? (string) preg_replace_callback($pattern, fn (): string => $line, $env, 1)
                : rtrim($env, "\r\n")."\n{$line}\n";
        }

        File::put($envPath, $env);
    }
}
