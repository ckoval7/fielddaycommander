<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\DemoPoolDatabase;
use App\Support\DemoCache;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Keeps a small stock of migrated demo databases ready for visitors and
 * limits how many sandboxes are built at once.
 *
 * Migrating a fresh database is most of the cost of provisioning, so a
 * visitor who claims a pooled database only waits for seeding. When the
 * pool runs dry, provisioning falls back to building a database inline.
 */
class DemoDatabasePool
{
    /**
     * How long a provisioning slot is held if the request dies without
     * releasing it. Comfortably longer than a cold migrate and seed.
     */
    private const SLOT_LOCK_SECONDS = 120;

    private const SLOT_RETRY_MICROSECONDS = 250_000;

    /**
     * How long a database must stay unfinished before it counts as orphaned.
     * Provisioning finishes in seconds, so anything unfinished across two
     * hourly cleanups was abandoned.
     */
    private const ORPHAN_GRACE_MINUTES = 30;

    public static function databaseName(string $uuid): string
    {
        $dbName = 'demo_'.str_replace('-', '_', $uuid);

        if (! preg_match('/^demo_[a-f0-9_]{32,40}$/', $dbName)) {
            throw new InvalidArgumentException('Invalid demo session identifier.');
        }

        return $dbName;
    }

    /**
     * Take the oldest pooled database out of the pool.
     *
     * Deleting by primary key and checking the affected row count makes the
     * claim atomic, so two visitors can never receive the same database.
     *
     * @return string|null The claimed session UUID, or null when the pool is empty.
     */
    public function claim(): ?string
    {
        while ($candidate = DemoPoolDatabase::query()->oldest('id')->first()) {
            if (DemoPoolDatabase::query()->whereKey($candidate->getKey())->delete() === 1) {
                return $candidate->session_uuid;
            }
        }

        return null;
    }

    /**
     * Create an empty demo database and return its session UUID.
     */
    public function createDatabase(): string
    {
        $uuid = (string) Str::uuid();
        $dbName = self::databaseName($uuid);

        DB::statement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        return $uuid;
    }

    /**
     * Point the demo connection at the session's database and run any
     * outstanding migrations. On a pooled database this only applies
     * migrations added since it was built.
     */
    public function migrate(string $uuid): void
    {
        Config::set('database.connections.demo.database', self::databaseName($uuid));
        DB::purge('demo');

        Artisan::call('migrate', [
            '--database' => 'demo',
            '--force' => true,
            '--no-interaction' => true,
        ]);
    }

    /**
     * Bring the pool to exactly the target size.
     *
     * Entries whose database has disappeared are forgotten first, then new
     * databases are built (or surplus ones dropped) to reach the target.
     *
     * @return array{created: int, dropped: int, forgotten: int}
     */
    public function fill(int $target): array
    {
        $baseDatabase = config('database.connections.demo.database');
        $existing = $this->existingDemoDatabases();
        $forgotten = 0;

        foreach (DemoPoolDatabase::all() as $entry) {
            if (! in_array(self::databaseName($entry->session_uuid), $existing, true)) {
                $entry->delete();
                $forgotten++;
            }
        }

        $ready = DemoPoolDatabase::count();
        $created = 0;
        $dropped = 0;

        while ($ready < $target) {
            $uuid = $this->createDatabase();

            try {
                $this->migrate($uuid);
            } catch (Throwable $e) {
                $this->dropDatabase(self::databaseName($uuid), $baseDatabase);

                throw $e;
            }

            DemoPoolDatabase::create(['session_uuid' => $uuid]);
            $ready++;
            $created++;
        }

        while ($ready > $target && ($uuid = $this->claim()) !== null) {
            $this->dropDatabase(self::databaseName($uuid), $baseDatabase);
            $ready--;
            $dropped++;
        }

        return ['created' => $created, 'dropped' => $dropped, 'forgotten' => $forgotten];
    }

    /**
     * Names of every pooled database, so session counts can leave them out.
     *
     * @return list<string>
     */
    public function pooledDatabaseNames(): array
    {
        return DemoPoolDatabase::query()
            ->pluck('session_uuid')
            ->map(fn (string $uuid): string => self::databaseName($uuid))
            ->all();
    }

    /**
     * Wait briefly for one of the limited provisioning slots.
     *
     * Building a sandbox ties up a web worker and hammers the disk, so only
     * a few run at once. The caller must release the returned lock.
     */
    public function acquireProvisioningSlot(): ?Lock
    {
        $store = Cache::store(config('demo.shared_cache_store'));
        $slots = max(1, (int) config('demo.provision_concurrency', 2));
        $deadline = microtime(true) + (float) config('demo.provision_wait_seconds', 3);

        while (true) {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $lock = $store->lock("demo:provision-slot:{$slot}", self::SLOT_LOCK_SECONDS);

                if ($lock->get()) {
                    return $lock;
                }
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(self::SLOT_RETRY_MICROSECONDS);
        }
    }

    /**
     * Drop demo databases that were abandoned before they were finished.
     *
     * A sandbox whose migration or seeding died, or a pool database whose
     * build was cut short, never records demo_provisioned_at, so TTL cleanup
     * never removes it and it counts against the session cap forever. Such a
     * database is only dropped once it has stayed unfinished and unpooled
     * across cleanups, so a visitor's sandbox that is still being seeded is
     * never touched.
     *
     * @param  list<string>  $databaseNames  Existing demo_* schema names.
     * @return list<string> The databases that were dropped.
     */
    public function dropOrphans(array $databaseNames): array
    {
        $baseDatabase = config('database.connections.demo.database');
        $cache = Cache::store(config('demo.shared_cache_store'));
        $pooled = $this->pooledDatabaseNames();
        $dropped = [];

        foreach ($databaseNames as $dbName) {
            if (! preg_match('/^demo_[a-f0-9_]{32,40}$/', $dbName)) {
                continue;
            }

            $firstSeenKey = "demo:orphan-first-seen:{$dbName}";

            try {
                if (in_array($dbName, $pooled, true) || $this->isProvisioned($dbName)) {
                    $cache->forget($firstSeenKey);

                    continue;
                }

                $firstSeen = $cache->get($firstSeenKey);

                if ($firstSeen === null) {
                    $cache->put($firstSeenKey, now()->getTimestamp(), now()->addDay());

                    continue;
                }

                if (now()->getTimestamp() - $firstSeen < self::ORPHAN_GRACE_MINUTES * 60) {
                    continue;
                }

                $this->dropDatabase($dbName, $baseDatabase);
                DemoCache::forget($dbName);
                $cache->forget($firstSeenKey);
                $dropped[] = $dbName;
            } catch (Throwable $e) {
                report($e);
            }
        }

        Config::set('database.connections.demo.database', $baseDatabase);
        DB::purge('demo');

        return $dropped;
    }

    /**
     * Whether the database finished seeding. One that never got as far as
     * creating system_config is unfinished too.
     */
    protected function isProvisioned(string $dbName): bool
    {
        Config::set('database.connections.demo.database', $dbName);
        DB::purge('demo');

        if (! Schema::connection('demo')->hasTable('system_config')) {
            return false;
        }

        return DB::connection('demo')
            ->table('system_config')
            ->where('key', 'demo_provisioned_at')
            ->exists();
    }

    /**
     * @return list<string>
     */
    protected function existingDemoDatabases(): array
    {
        $rows = DB::select(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'demo\_%'"
        );

        return array_values(array_map(fn (object $row): string => $row->schema_name ?? $row->SCHEMA_NAME, $rows));
    }

    /**
     * The demo_provisioner user only has privileges on demo_* schemas, so the
     * connection is pointed back at the base demo database before dropping.
     */
    protected function dropDatabase(string $dbName, string $baseDatabase): void
    {
        Config::set('database.connections.demo.database', $baseDatabase);
        DB::purge('demo');

        DB::connection('demo')->statement("DROP DATABASE IF EXISTS `{$dbName}`");
    }
}
