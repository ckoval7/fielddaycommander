<?php

namespace App\Console\Commands;

use App\Models\DemoSession;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DemoCleanup extends Command
{
    protected $signature = 'demo:cleanup';

    protected $description = 'Drop expired demo_* databases based on demo_provisioned_at timestamp';

    public function handle(): int
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

                    $provisionedAt = DB::connection('demo')
                        ->table('system_config')
                        ->where('key', 'demo_provisioned_at')
                        ->value('value');

                    if (! $provisionedAt) {
                        continue;
                    }

                    if (Carbon::parse($provisionedAt)->addHours($ttlHours)->isPast()) {
                        DB::purge('demo');
                        Config::set('database.connections.demo.database', config('database.connections.mysql.database'));
                        DB::statement("DROP DATABASE `{$dbName}`");
                        $this->info("Dropped expired demo database: {$dbName}");
                        $dropped++;
                    }
                } catch (\Throwable $e) {
                    $this->warn("Could not process {$dbName}: {$e->getMessage()}");
                }
            }

            if ($dropped === 0) {
                $this->info('No expired demo databases found.');
            } else {
                $this->info("Dropped {$dropped} expired demo database(s).");
            }
        }

        $this->pruneAnalytics();

        return self::SUCCESS;
    }

    private function pruneAnalytics(): void
    {
        $retentionDays = config('demo.analytics_retention_days', 90);
        $cutoff = Carbon::now()->subDays($retentionDays);

        $pruned = DemoSession::where('provisioned_at', '<', $cutoff)->delete();

        if ($pruned > 0) {
            $this->info("Pruned {$pruned} analytics session(s) older than {$retentionDays} days.");
        }
    }
}
