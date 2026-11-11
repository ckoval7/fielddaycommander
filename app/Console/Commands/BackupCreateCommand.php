<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Enums\BackupKind;
use App\Exceptions\SiteBackupException;
use App\Services\SiteBackupService;
use Illuminate\Console\Command;

class BackupCreateCommand extends Command
{
    protected $signature = 'backup:create
        {--automatic : Back up the database and settings without uploaded files, and delete older automatic backups}
        {--json : Print the result as JSON for the Windows backup tools}';

    protected $description = 'Back up the Field Day Commander database, uploaded photos and files, and settings to a ZIP file (SQLite only)';

    public function handle(SiteBackupService $backups): int
    {
        $kind = $this->option('automatic') ? BackupKind::Automatic : BackupKind::Manual;

        try {
            $path = $backups->create($kind);
        } catch (SiteBackupException $e) {
            return $this->report(['ok' => false, 'error' => $e->getMessage()], self::FAILURE);
        }

        return $this->report(['ok' => true, 'path' => $path], self::SUCCESS);
    }

    /**
     * @param  array{ok: bool, path?: string, error?: string}  $result
     */
    private function report(array $result, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        } elseif ($result['ok']) {
            $this->info("Backup saved to {$result['path']}");
        } else {
            $this->error($result['error']);
        }

        return $exitCode;
    }
}
