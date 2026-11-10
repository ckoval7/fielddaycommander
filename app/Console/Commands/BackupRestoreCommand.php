<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Exceptions\SiteBackupException;
use App\Services\SiteBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Number;

class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore
        {file : The backup ZIP file to restore}
        {--force : Restore without asking for confirmation}
        {--inspect : Check the backup and show its contents without restoring it}
        {--json : Print the result as JSON for the Windows backup tools}';

    protected $description = 'Restore a Field Day Commander backup (SQLite only). Stop the server first.';

    public function handle(SiteBackupService $backups): int
    {
        $file = (string) $this->argument('file');

        try {
            $manifest = $backups->inspect($file);

            if ($this->option('inspect')) {
                return $this->report(['ok' => true, 'backup' => $manifest], self::SUCCESS);
            }

            if (! $this->option('json')) {
                $this->describe($manifest);
            }

            if (! $this->option('force') && ! $this->confirm('Replace all of this server\'s data with this backup?')) {
                return self::FAILURE;
            }

            $result = $backups->restore($file);
        } catch (SiteBackupException $e) {
            return $this->report(['ok' => false, 'error' => $e->getMessage()], self::FAILURE);
        }

        // A cached config still holds the old APP_KEY and mail settings.
        if (app()->configurationIsCached()) {
            Artisan::call('config:cache');
        }

        return $this->report(['ok' => true, 'backup' => $result['manifest'], 'safety_backup' => $result['safety_backup']], self::SUCCESS);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function describe(array $manifest): void
    {
        $this->line("Created:  {$manifest['created_at']} with Field Day Commander {$manifest['version']}");
        $this->line('Event:    '.($manifest['summary']['event'] ?? 'none'));
        $this->line('Contacts: '.Number::format($manifest['summary']['contacts']));
        $this->line('Photos and files: '.($manifest['includes_files'] ? 'included' : 'not included (photos and files on this server will be kept)'));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function report(array $result, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        } elseif (! $result['ok']) {
            $this->error($result['error']);
        } elseif (isset($result['safety_backup'])) {
            $this->info('Backup restored. To undo this restore, restore this file: '.$result['safety_backup']);
        } else {
            $this->describe($result['backup']);
        }

        return $exitCode;
    }
}
