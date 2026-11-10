<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('opens SQLite database files ready for several processes writing at once', function () {
    $path = storage_path('framework/testing/sqlite-settings-'.Str::uuid().'.sqlite');
    touch($path);

    Config::set('database.connections.sqlite_file', [
        ...config('database.connections.sqlite'),
        'database' => $path,
    ]);

    try {
        $connection = DB::connection('sqlite_file');

        expect($connection->selectOne('PRAGMA journal_mode')->journal_mode)->toBe('wal')
            ->and((int) $connection->selectOne('PRAGMA busy_timeout')->timeout)->toBe(5000)
            ->and((int) $connection->selectOne('PRAGMA synchronous')->synchronous)->toBe(1)
            ->and($connection->getConfig('transaction_mode'))->toBe('IMMEDIATE');
    } finally {
        DB::purge('sqlite_file');
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($path.$suffix);
        }
    }
});
