<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Service for managing database snapshots using mysqldump/mysql CLI.
 *
 * Provides functionality to create, restore, list, and delete database snapshots.
 * Snapshots are stored as SQL files with JSON sidecar files containing metadata.
 */
class DatabaseSnapshotService
{
    /**
     * Create a new database snapshot.
     *
     * @param  string  $name  The name for the snapshot (alphanumeric, dashes, underscores only)
     * @param  string|null  $description  Optional description for the snapshot
     * @return string The filename of the created snapshot
     *
     * @throws RuntimeException If the snapshot creation fails
     */
    public function createSnapshot(string $name, ?string $description = null): string
    {
        $sanitizedName = $this->sanitizeName($name);
        $timestamp = now()->format('Y-m-d_His');
        $filename = "{$sanitizedName}-{$timestamp}.sql";
        $snapshotPath = $this->getSnapshotPath();
        $fullPath = "{$snapshotPath}/{$filename}";

        $command = $this->buildMysqldumpCommand($fullPath);

        Log::info('Creating database snapshot', [
            'name' => $sanitizedName,
            'filename' => $filename,
        ]);

        $result = Process::run($command);

        if (! $result->successful()) {
            Log::error('Database snapshot creation failed', [
                'filename' => $filename,
                'error' => $result->errorOutput(),
                'exit_code' => $result->exitCode(),
            ]);

            throw new RuntimeException(
                'Failed to create database snapshot: '.$result->errorOutput()
            );
        }

        // Verify the file was created and has content
        if (! File::exists($fullPath) || File::size($fullPath) === 0) {
            Log::error('Database snapshot file is empty or missing', [
                'filename' => $filename,
                'path' => $fullPath,
            ]);

            throw new RuntimeException('Database snapshot file is empty or was not created.');
        }

        // Create metadata sidecar file
        $metadata = [
            'name' => $sanitizedName,
            'description' => $description,
            'created_at' => now()->toIso8601String(),
            'size' => File::size($fullPath),
        ];

        File::put("{$fullPath}.json", json_encode($metadata, JSON_PRETTY_PRINT));

        Log::info('Database snapshot created successfully', [
            'filename' => $filename,
            'size' => $metadata['size'],
        ]);

        // Enforce snapshot limit
        $this->enforceLimit();

        return $filename;
    }

    /**
     * Restore a database from a snapshot file.
     *
     * @param  string  $filename  The filename of the snapshot to restore
     * @return bool True on success
     *
     * @throws RuntimeException If the restore fails or file doesn't exist
     */
    public function restoreSnapshot(string $filename): bool
    {
        $snapshotPath = $this->getSnapshotPath();
        $fullPath = "{$snapshotPath}/{$filename}";

        if (! File::exists($fullPath)) {
            throw new RuntimeException("Snapshot file not found: {$filename}");
        }

        $command = $this->buildMysqlCommand($fullPath);

        Log::info('Restoring database from snapshot', [
            'filename' => $filename,
        ]);

        $result = Process::run($command);

        if (! $result->successful()) {
            Log::error('Database restore failed', [
                'filename' => $filename,
                'error' => $result->errorOutput(),
                'exit_code' => $result->exitCode(),
            ]);

            throw new RuntimeException(
                'Failed to restore database snapshot: '.$result->errorOutput()
            );
        }

        Log::info('Database restored successfully', [
            'filename' => $filename,
        ]);

        return true;
    }

    /**
     * List all available snapshots.
     *
     * @return Collection<int, array{filename: string, name: string, description: string|null, created_at: string, size: string}>
     */
    public function listSnapshots(): Collection
    {
        $snapshotPath = $this->getSnapshotPath();
        $snapshots = collect();

        if (! File::isDirectory($snapshotPath)) {
            return $snapshots;
        }

        $files = File::files($snapshotPath);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'sql') {
                continue;
            }

            $filename = $file->getFilename();
            $metadataPath = "{$snapshotPath}/{$filename}.json";

            if (File::exists($metadataPath)) {
                $metadata = json_decode(File::get($metadataPath), true);
                $snapshots->push([
                    'filename' => $filename,
                    'name' => $metadata['name'] ?? $filename,
                    'description' => $metadata['description'] ?? null,
                    'created_at' => $metadata['created_at'] ?? $file->getMTime(),
                    'size' => $this->formatBytes($metadata['size'] ?? $file->getSize()),
                ]);
            } else {
                // Handle SQL files without metadata
                $snapshots->push([
                    'filename' => $filename,
                    'name' => pathinfo($filename, PATHINFO_FILENAME),
                    'description' => null,
                    'created_at' => date('c', $file->getMTime()),
                    'size' => $this->formatBytes($file->getSize()),
                ]);
            }
        }

        // Sort by created_at descending (newest first)
        return $snapshots->sortByDesc('created_at')->values();
    }

    /**
     * Delete a snapshot and its metadata sidecar file.
     *
     * @param  string  $filename  The filename of the snapshot to delete
     * @return bool True on success, false if file not found
     */
    public function deleteSnapshot(string $filename): bool
    {
        $snapshotPath = $this->getSnapshotPath();
        $fullPath = "{$snapshotPath}/{$filename}";
        $metadataPath = "{$fullPath}.json";

        if (! File::exists($fullPath)) {
            return false;
        }

        // Delete the SQL file
        File::delete($fullPath);

        // Delete the metadata sidecar if it exists
        if (File::exists($metadataPath)) {
            File::delete($metadataPath);
        }

        Log::info('Database snapshot deleted', [
            'filename' => $filename,
        ]);

        return true;
    }

    /**
     * Enforce the maximum snapshot limit by deleting oldest snapshots.
     */
    public function enforceLimit(): void
    {
        $maxSnapshots = config('developer.max_snapshots', 10);
        $snapshots = $this->listSnapshots();

        if ($snapshots->count() <= $maxSnapshots) {
            return;
        }

        // Get snapshots to delete (oldest ones beyond the limit)
        $snapshotsToDelete = $snapshots->slice($maxSnapshots);

        foreach ($snapshotsToDelete as $snapshot) {
            $this->deleteSnapshot($snapshot['filename']);

            Log::info('Snapshot auto-deleted due to limit', [
                'filename' => $snapshot['filename'],
                'max_snapshots' => $maxSnapshots,
            ]);
        }
    }

    /**
     * Get the configured snapshot storage path, creating the directory if needed.
     *
     * @return string The absolute path to the snapshot directory
     */
    private function getSnapshotPath(): string
    {
        $path = config('developer.snapshot_path', storage_path('app/snapshots'));

        if (! File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }

        return $path;
    }

    /**
     * Get MySQL connection credentials from config.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string, socket: string}
     */
    private function getMysqlCredentials(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        return [
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? '3306',
            'database' => $config['database'] ?? 'laravel',
            'username' => $config['username'] ?? 'root',
            'password' => $config['password'] ?? '',
            'socket' => $config['unix_socket'] ?? '',
        ];
    }

    /**
     * Build the mysqldump command with proper credentials and escaping.
     *
     * @param  string  $outputFile  The path to the output SQL file
     * @return string The complete mysqldump command
     */
    private function buildMysqldumpCommand(string $outputFile): string
    {
        $credentials = $this->getMysqlCredentials();

        $command = 'mysqldump';

        // Host/socket
        if (! empty($credentials['socket'])) {
            $command .= ' --socket='.escapeshellarg($credentials['socket']);
        } else {
            $command .= ' --host='.escapeshellarg($credentials['host']);
            $command .= ' --port='.escapeshellarg($credentials['port']);
        }

        // Credentials
        $command .= ' --user='.escapeshellarg($credentials['username']);

        if (! empty($credentials['password'])) {
            $command .= ' --password='.escapeshellarg($credentials['password']);
        }

        // Options for a complete dump
        $command .= ' --single-transaction';
        $command .= ' --routines';
        $command .= ' --triggers';
        $command .= ' --events';

        // Database name
        $command .= ' '.escapeshellarg($credentials['database']);

        // Output file
        $command .= ' > '.escapeshellarg($outputFile);

        return $command;
    }

    /**
     * Build the mysql restore command with proper credentials and escaping.
     *
     * @param  string  $inputFile  The path to the input SQL file
     * @return string The complete mysql command
     */
    private function buildMysqlCommand(string $inputFile): string
    {
        $credentials = $this->getMysqlCredentials();

        $command = 'mysql';

        // Host/socket
        if (! empty($credentials['socket'])) {
            $command .= ' --socket='.escapeshellarg($credentials['socket']);
        } else {
            $command .= ' --host='.escapeshellarg($credentials['host']);
            $command .= ' --port='.escapeshellarg($credentials['port']);
        }

        // Credentials
        $command .= ' --user='.escapeshellarg($credentials['username']);

        if (! empty($credentials['password'])) {
            $command .= ' --password='.escapeshellarg($credentials['password']);
        }

        // Database name
        $command .= ' '.escapeshellarg($credentials['database']);

        // Input file
        $command .= ' < '.escapeshellarg($inputFile);

        return $command;
    }

    /**
     * Sanitize a snapshot name to allow only alphanumeric characters, dashes, and underscores.
     *
     * @param  string  $name  The original name
     * @return string The sanitized name
     */
    private function sanitizeName(string $name): string
    {
        // Replace spaces with dashes
        $name = str_replace(' ', '-', $name);

        // Remove any characters that aren't alphanumeric, dash, or underscore
        $name = preg_replace('/[^a-zA-Z0-9\-_]/', '', $name);

        // Ensure name is not empty
        if (empty($name)) {
            $name = 'snapshot';
        }

        return $name;
    }

    /**
     * Format bytes into a human-readable string.
     *
     * @param  int  $bytes  The number of bytes
     * @return string The formatted string (e.g., "1.5 MB")
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }
}
