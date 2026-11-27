<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <support@fielddaycommander.org>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class AuditCleanup extends Command
{
    protected $signature = 'audit:cleanup {--days= : Number of days to keep (default: AUDIT_RETENTION_DAYS)}';

    protected $description = 'Clean up old audit log entries';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('auth-security.audit_retention_days')));
        $cutoff = now()->subDays($days);

        $count = AuditLog::where('created_at', '<', $cutoff)
            ->where('is_critical', false)
            ->delete();

        $this->info("Deleted {$count} old audit log entries");
        $this->info('Critical security events were preserved');

        return 0;
    }
}
