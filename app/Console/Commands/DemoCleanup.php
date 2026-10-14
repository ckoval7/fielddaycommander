<?php

namespace App\Console\Commands;

use App\Models\DemoEvent;
use App\Services\DemoDatabasePool;
use App\Support\DemoCache;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DemoCleanup extends Command
{
    protected $signature = 'demo:cleanup';

    protected $description = 'Drop expired demo_* databases based on demo_provisioned_at timestamp, plus abandoned unfinished ones';

    public function handle(DemoDatabasePool $pool): int
    {
        if (! config('demo.enabled')) {
            $this->info('Demo mode is disabled. Nothing to clean up.');

            return self::SUCCESS;
        }

        $rows = DB::select(
            "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE 'demo\_%'"
        );

        if (empty($rows)) {
            $this->info('No expired demo databases found.');
        } else {
            /**
             * The administrative default DB for the demo connection (e.g. demo_base).
             * The demo_provisioner MySQL user is only granted privileges on demo_*
             * schemas, so we must restore the connection here — NOT to the main app
             * DB — before issuing DROP DATABASE, or PDO's initial USE fails with 1044.
             */
            $adminDemoDb = config('database.connections.demo.database');
            $ttlHours = config('demo.ttl_hours', 24);
            $dropped = 0;

            foreach ($rows as $row) {
                $dbName = $row->schema_name ?? $row->SCHEMA_NAME;

                if (! preg_match('/^demo_[a-f0-9_]{32,40}$/', $dbName)) {
                    $this->warn("Skipping unexpected schema name: {$dbName}");

                    continue;
                }

                try {
                    Config::set('database.connections.demo.database', $dbName);
                    DB::purge('demo');

                    // Unfinished databases are left to the orphan sweep below.
                    if (! Schema::connection('demo')->hasTable('system_config')) {
                        continue;
                    }

                    $provisionedAt = DB::connection('demo')
                        ->table('system_config')
                        ->where('key', 'demo_provisioned_at')
                        ->value('value');

                    if (! $provisionedAt) {
                        continue;
                    }

                    if (Carbon::parse($provisionedAt)->addHours($ttlHours)->isPast()) {
                        DB::purge('demo');
                        Config::set('database.connections.demo.database', $adminDemoDb);
                        DB::connection('demo')->statement("DROP DATABASE `{$dbName}`");
                        DemoCache::forget($dbName);
                        $this->info("Dropped expired demo database: {$dbName}");
                        $dropped++;
                    }
                } catch (\Throwable $e) {
                    $this->warn("Could not process {$dbName}: {$e->getMessage()}");
                }
            }

            Config::set('database.connections.demo.database', $adminDemoDb);
            DB::purge('demo');

            if ($dropped === 0) {
                $this->info('No expired demo databases found.');
            } else {
                $this->info("Dropped {$dropped} expired demo database(s).");
            }

            $this->dropOrphans($pool, $rows);
        }

        $this->pruneAnalytics();

        return self::SUCCESS;
    }

    /**
     * @param  list<object>  $rows
     */
    private function dropOrphans(DemoDatabasePool $pool, array $rows): void
    {
        $names = array_map(fn (object $row): string => $row->schema_name ?? $row->SCHEMA_NAME, $rows);

        foreach ($pool->dropOrphans($names) as $dbName) {
            $this->info("Dropped orphaned demo database: {$dbName}");
        }
    }

    private function pruneAnalytics(): void
    {
        $retentionDays = config('demo.analytics_retention_days', 90);
        $cutoff = Carbon::now()->subDays($retentionDays);

        // Sessions and participants are kept for good; only the detailed event
        // stream behind each session timeline expires.
        $pruned = DemoEvent::where('created_at', '<', $cutoff)->delete();

        if ($pruned > 0) {
            $this->info("Pruned {$pruned} analytics event(s) older than {$retentionDays} days.");
        }
    }
}
