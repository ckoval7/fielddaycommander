<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Enums\BackupKind;
use App\Exceptions\SiteBackupException;
use FilesystemIterator;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ZipArchive;

/**
 * Whole-site backups for SQLite installs: one zip file holding the database,
 * uploaded photos and files, and the settings a new server needs to read the
 * data (APP_KEY, which two-factor secrets are encrypted with, and mail
 * settings). The same settings restore.sh carries over with --new-server.
 */
class SiteBackupService
{
    /**
     * Bumped when the layout inside the zip changes.
     */
    public const int FORMAT = 1;

    public const string MANIFEST_ENTRY = 'backup.json';

    public const string DATABASE_ENTRY = 'database.sqlite';

    public const string SETTINGS_ENTRY = 'settings.env';

    public const string FILES_PREFIX = 'files/';

    /**
     * Folders under storage/app that hold uploaded files.
     *
     * @var list<string>
     */
    private const array FILE_ROOTS = ['private', 'public'];

    /**
     * Folders under storage/app that are never backed up or replaced:
     * album downloads, which are temporary.
     *
     * @var list<string>
     */
    private const array SKIPPED_PATHS = ['private/exports'];

    /**
     * Already-compressed files are stored as they are, which saves time on
     * small hardware and loses nothing.
     *
     * @var list<string>
     */
    private const array STORED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'zip', 'gz', 'mp4', 'mov', 'pdf'];

    private readonly string $filesRoot;

    /**
     * @param  string|null  $connectionName  The database connection to back up; null for the default
     * @param  string|null  $filesRoot  The folder holding private/ and public/; null for storage/app
     */
    public function __construct(
        private readonly SqliteDatabaseCopier $copier,
        private readonly SiteBackupSettings $settings,
        private readonly ?string $connectionName = null,
        ?string $filesRoot = null,
    ) {
        $this->filesRoot = $filesRoot ?? storage_path('app');
    }

    /**
     * Whether this install can use these backups (only SQLite installs can).
     */
    public function isSupported(): bool
    {
        return $this->connection()->getDriverName() === 'sqlite';
    }

    /**
     * Make a backup and return its full path.
     *
     * @throws SiteBackupException
     */
    public function create(BackupKind $kind = BackupKind::Manual): string
    {
        $this->ensureSupported();

        $directory = $this->directory();
        $path = $this->uniquePath($directory, $kind, now());
        $partial = "{$path}.part";
        $work = $this->workDirectory();

        try {
            $databaseCopy = $work.'/'.self::DATABASE_ENTRY;
            $this->copier->copyTo($this->connection(), $databaseCopy);

            $zip = new ZipArchive;

            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new SiteBackupException("The backup file could not be created in {$directory}.");
            }

            $zip->addFile($databaseCopy, self::DATABASE_ENTRY);
            $zip->addFromString(self::SETTINGS_ENTRY, $this->settings->current());

            if ($kind->includesFiles()) {
                $this->addFiles($zip);
            }

            $zip->addFromString(self::MANIFEST_ENTRY, (string) json_encode($this->manifest($kind), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new SiteBackupException("The backup could not be saved. Check the free disk space for {$directory}.");
            }

            File::move($partial, $path);
        } catch (SiteBackupException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Site backup failed', ['path' => $path, 'error' => $e->getMessage()]);

            throw new SiteBackupException('The backup could not be saved: '.$e->getMessage(), previous: $e);
        } finally {
            File::delete($partial);
            File::deleteDirectory($work);
        }

        Log::info('Site backup created', ['path' => $path, 'kind' => $kind->value]);

        if ($kind === BackupKind::Automatic) {
            $this->pruneAutomatic();
        }

        return $path;
    }

    /**
     * Read and check a backup's manifest without changing anything.
     *
     * @return array{format: int, version: string, created_at: string, kind: string, includes_files: bool, summary: array{event: string|null, contacts: int}}
     *
     * @throws SiteBackupException If the file isn't a backup this version can restore
     */
    public function inspect(string $path): array
    {
        $zip = $this->openBackup($path);

        try {
            $manifest = json_decode((string) $zip->getFromName(self::MANIFEST_ENTRY), true);

            if (! is_array($manifest) || ! isset($manifest['format'], $manifest['version']) || $zip->locateName(self::DATABASE_ENTRY) === false) {
                throw new SiteBackupException('This file is not a Field Day Commander backup.');
            }
        } finally {
            $zip->close();
        }

        if ((int) $manifest['format'] > self::FORMAT || $this->isNewerVersion((string) $manifest['version'])) {
            throw new SiteBackupException("This backup was made by Field Day Commander {$manifest['version']}. Update Field Day Commander to that version or later, then try again.");
        }

        return [
            'format' => (int) $manifest['format'],
            'version' => (string) $manifest['version'],
            'created_at' => (string) ($manifest['created_at'] ?? ''),
            'kind' => (string) ($manifest['kind'] ?? BackupKind::Manual->value),
            'includes_files' => (bool) ($manifest['includes_files'] ?? false),
            'summary' => [
                'event' => $manifest['summary']['event'] ?? null,
                'contacts' => (int) ($manifest['summary']['contacts'] ?? 0),
            ],
        ];
    }

    /**
     * Replace this server's data with a backup. A backup of the current data
     * is made first, so a restore can be undone by restoring that one.
     * Run it with the web server, queue worker, scheduler and Reverb stopped.
     *
     * @return array{manifest: array<string, mixed>, safety_backup: string}
     *
     * @throws SiteBackupException
     */
    public function restore(string $path): array
    {
        $this->ensureSupported();

        $manifest = $this->inspect($path);
        $work = $this->workDirectory();

        try {
            $this->copier->databasePath($this->connection());
            $this->extract($path, $work);

            $database = $work.'/'.self::DATABASE_ENTRY;

            if (! $this->copier->isIntact($database)) {
                throw new SiteBackupException('The database in this backup is damaged. Nothing was restored or changed.');
            }

            $safetyBackup = $this->create(BackupKind::BeforeRestore);

            $this->copier->restoreFrom($this->connection(), $database);

            if ($manifest['includes_files']) {
                $this->replaceFiles($work.'/files');
            }

            $this->settings->apply((string) @file_get_contents($work.'/'.self::SETTINGS_ENTRY));

            Artisan::call('migrate', ['--force' => true, '--database' => $this->connection()->getName()]);

            // The cache lives in the database, so the restore brought back
            // the backup's scheduler locks and listener heartbeats.
            Artisan::call('cache:clear');
        } catch (SiteBackupException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Site restore failed', ['path' => $path, 'error' => $e->getMessage()]);

            throw new SiteBackupException('The restore failed. The data may be partly restored. Restore the newest "backup before restore" file. Error: '.$e->getMessage(), previous: $e);
        } finally {
            File::deleteDirectory($work);
        }

        Log::info('Site restored from backup', ['path' => $path, 'safety_backup' => $safetyBackup]);

        return ['manifest' => $manifest, 'safety_backup' => $safetyBackup];
    }

    /**
     * Where backups are written.
     */
    public function directory(): string
    {
        $directory = rtrim((string) config('backup.path'), '/\\');

        File::ensureDirectoryExists($directory);

        return $directory;
    }

    /**
     * Delete the oldest automatic backups beyond the configured number.
     */
    public function pruneAutomatic(): void
    {
        $prefix = 'Field Day Commander '.BackupKind::Automatic->fileLabel().' ';

        collect(File::files($this->directory()))
            ->filter(fn (SplFileInfo $file): bool => str_starts_with($file->getFilename(), $prefix) && $file->getExtension() === 'zip')
            // Names carry a sortable UTC time, which survives copies that reset file times.
            ->sortByDesc(fn (SplFileInfo $file): string => $file->getFilename())
            ->slice(max(0, (int) config('backup.keep_automatic')))
            ->each(fn (SplFileInfo $file) => File::delete($file->getPathname()));
    }

    private function connection(): Connection
    {
        return DB::connection($this->connectionName);
    }

    /**
     * @throws SiteBackupException
     */
    private function ensureSupported(): void
    {
        if (! $this->isSupported()) {
            throw new SiteBackupException('These backups only work with SQLite databases, as used by the Windows server. For Linux or Docker servers, use backup.sh.');
        }
    }

    /**
     * A file name like "Field Day Commander backup 2026-06-28 1405Z.zip".
     * Times are UTC, like the log.
     */
    private function uniquePath(string $directory, BackupKind $kind, Carbon $time): string
    {
        $base = $directory.DIRECTORY_SEPARATOR."Field Day Commander {$kind->fileLabel()} ".$time->copy()->utc()->format('Y-m-d Hi').'Z';
        $path = "{$base}.zip";

        for ($copy = 2; File::exists($path); $copy++) {
            $path = "{$base} ({$copy}).zip";
        }

        return $path;
    }

    private function workDirectory(): string
    {
        $work = storage_path('framework/backup-'.Str::random(12));

        File::ensureDirectoryExists($work);

        return $work;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(BackupKind $kind): array
    {
        $connection = $this->connection();

        return [
            'format' => self::FORMAT,
            'app' => 'Field Day Commander',
            'version' => (string) config('app.version'),
            'created_at' => now()->toIso8601String(),
            'kind' => $kind->value,
            'includes_files' => $kind->includesFiles(),
            'summary' => [
                'event' => $connection->table('events')->orderByDesc('start_time')->value('name'),
                'contacts' => $connection->table('contacts')->whereNull('deleted_at')->count(),
            ],
        ];
    }

    private function addFiles(ZipArchive $zip): void
    {
        foreach ($this->backedUpFiles() as $relative => $absolute) {
            $entry = self::FILES_PREFIX.$relative;
            $zip->addFile($absolute, $entry);

            if (in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::STORED_EXTENSIONS, true)) {
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
            }
        }
    }

    /**
     * Every uploaded file, keyed by its path under storage/app with forward slashes.
     *
     * @return array<string, string>
     */
    private function backedUpFiles(): array
    {
        $files = [];

        foreach (self::FILE_ROOTS as $root) {
            $directory = "{$this->filesRoot}/{$root}";

            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relative = $root.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));

                if (! $this->isSkipped($relative)) {
                    $files[$relative] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function isSkipped(string $relative): bool
    {
        foreach (self::SKIPPED_PATHS as $skipped) {
            if ($relative === $skipped || str_starts_with($relative, "{$skipped}/")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unpack only the entries a backup should contain, refusing any path
     * that could land outside the work folder.
     *
     * @throws SiteBackupException
     */
    private function extract(string $path, string $work): void
    {
        $zip = $this->openBackup($path);
        $entries = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if (str_ends_with($name, '/')) {
                    continue;
                }

                if (! $this->isAllowedEntry($name)) {
                    throw new SiteBackupException('This backup contains unexpected files. Nothing was restored or changed.');
                }

                $entries[] = $name;
            }

            if (! $zip->extractTo($work, $entries)) {
                throw new SiteBackupException('The backup could not be unpacked. Check the free disk space.');
            }
        } finally {
            $zip->close();
        }
    }

    private function isAllowedEntry(string $name): bool
    {
        if (in_array($name, [self::MANIFEST_ENTRY, self::DATABASE_ENTRY, self::SETTINGS_ENTRY], true)) {
            return true;
        }

        if (! str_starts_with($name, self::FILES_PREFIX) || str_contains($name, '\\') || str_contains($name, ':')) {
            return false;
        }

        $segments = explode('/', substr($name, strlen(self::FILES_PREFIX)));

        return in_array($segments[0], self::FILE_ROOTS, true)
            && count($segments) > 1
            && ! in_array('..', $segments, true)
            && ! in_array('', $segments, true);
    }

    /**
     * Swap this server's uploaded files for the backup's, keeping the
     * folders that are never backed up.
     */
    private function replaceFiles(string $extracted): void
    {
        foreach (self::FILE_ROOTS as $root) {
            $target = "{$this->filesRoot}/{$root}";
            File::ensureDirectoryExists($target);

            foreach (File::files($target, true) as $file) {
                File::delete($file->getPathname());
            }

            foreach (File::directories($target) as $directory) {
                if (! $this->isSkipped($root.'/'.basename($directory))) {
                    File::deleteDirectory($directory);
                }
            }

            if (is_dir("{$extracted}/{$root}")) {
                File::copyDirectory("{$extracted}/{$root}", $target);
            }
        }
    }

    /**
     * @throws SiteBackupException
     */
    private function openBackup(string $path): ZipArchive
    {
        if (! File::isFile($path)) {
            throw new SiteBackupException("The backup file could not be found at {$path}.");
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new SiteBackupException('This file is not a Field Day Commander backup, or it is damaged.');
        }

        return $zip;
    }

    /**
     * Whether a backup's version is newer than this install. Development
     * builds can restore anything.
     */
    private function isNewerVersion(string $backupVersion): bool
    {
        $current = (string) config('app.version');

        if (str_contains($current, 'dev') || str_contains($backupVersion, 'dev')) {
            return false;
        }

        return version_compare($backupVersion, $current, '>');
    }
}
