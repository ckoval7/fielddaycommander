<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Exceptions\SqliteCopyException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use SQLite3;

/**
 * Copies a live SQLite database to a file and back while other processes
 * (web server, queue worker, scheduler, listeners) keep it open.
 */
class SqliteDatabaseCopier
{
    /**
     * How long a restore waits for other processes to finish writing, in milliseconds.
     */
    private const int BUSY_TIMEOUT_MS = 10000;

    /**
     * Path of the connection's database file.
     *
     * @throws SqliteCopyException If the connection isn't SQLite or the database is in memory
     */
    public function databasePath(Connection $connection): string
    {
        if ($connection->getDriverName() !== 'sqlite') {
            throw new SqliteCopyException('This only works with a SQLite database.');
        }

        $database = (string) $connection->getConfig('database');

        if ($database === '' || $database === ':memory:' || str_contains($database, 'mode=memory')) {
            throw new SqliteCopyException('An in-memory database cannot be copied.');
        }

        return $database;
    }

    /**
     * Write a consistent copy of the database to $destination, which must not
     * exist yet. VACUUM INTO reads one snapshot of the database, so writers
     * carry on meanwhile, and the copy comes out compacted.
     */
    public function copyTo(Connection $connection, string $destination): void
    {
        $this->databasePath($connection);

        $connection->statement('VACUUM INTO ?', [$destination]);
    }

    /**
     * Replace the live database with the one in $source using SQLite's backup
     * API, which takes the database's own locks: other processes see either
     * the old database or the restored one, never a mix.
     *
     * @throws SqliteCopyException If the copy fails
     */
    public function restoreFrom(Connection $connection, string $source): void
    {
        $databasePath = $this->databasePath($connection);
        $name = $connection->getName();

        DB::disconnect($name);

        $from = null;
        $to = null;

        try {
            $from = new SQLite3($source, SQLITE3_OPEN_READONLY);
            $to = new SQLite3($databasePath, SQLITE3_OPEN_READWRITE);
            $to->busyTimeout(self::BUSY_TIMEOUT_MS);
            $from->enableExceptions(true);
            $to->enableExceptions(true);

            if (! $from->backup($to)) {
                throw new SqliteCopyException($to->lastErrorMsg());
            }
        } finally {
            $from?->close();
            $to?->close();
            DB::reconnect($name);
        }
    }

    /**
     * Whether $path is an intact SQLite database.
     */
    public function isIntact(string $path): bool
    {
        try {
            $database = new SQLite3($path, SQLITE3_OPEN_READONLY);
            $database->enableExceptions(true);
            $result = $database->querySingle('PRAGMA quick_check');
            $database->close();

            return $result === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }
}
