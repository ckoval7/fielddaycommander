<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class AuditCleanup extends Command
{
    protected $signature = 'audit:cleanup {--days=365 : Number of days to keep}';

    protected $description = 'Clean up old audit log entries';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $count = AuditLog::where('created_at', '<', $cutoff)
            ->where('is_critical', false)
            ->delete();

        $this->info("Deleted {$count} old audit log entries");
        $this->info('Critical security events were preserved');

        return 0;
    }
}
