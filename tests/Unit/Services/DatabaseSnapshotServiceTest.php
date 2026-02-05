<?php

use App\Services\DatabaseSnapshotService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    // Set up temporary test directory
    $this->testPath = storage_path('framework/testing/snapshots');
    Config::set('developer.snapshot_path', $this->testPath);
    Config::set('developer.max_snapshots', 10);

    // Ensure clean state
    if (File::isDirectory($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
    File::makeDirectory($this->testPath, 0755, true);

    $this->service = new DatabaseSnapshotService;
});

afterEach(function () {
    // Clean up test directory
    if (File::isDirectory($this->testPath)) {
        File::deleteDirectory($this->testPath);
    }
});

describe('createSnapshot', function () {
    it('sanitizes name correctly', function () {
        Process::fake([
            '*mysqldump*' => function ($process) {
                // Extract the output file from the command
                preg_match("/> '([^']+)'/", $process->command, $matches);
                if (isset($matches[1])) {
                    File::put($matches[1], 'fake sql content');
                }

                return Process::result(output: 'success');
            },
        ]);

        $filename = $this->service->createSnapshot('Test Snapshot!@#$%^&*()');

        expect($filename)
            ->toContain('Test-Snapshot')
            ->toEndWith('.sql');
    });

    it('creates snapshot directory if it does not exist', function () {
        // Delete the directory to test creation
        File::deleteDirectory($this->testPath);
        expect(File::isDirectory($this->testPath))->toBeFalse();

        Process::fake([
            '*mysqldump*' => function ($process) {
                preg_match("/> '([^']+)'/", $process->command, $matches);
                if (isset($matches[1])) {
                    File::put($matches[1], 'fake sql content');
                }

                return Process::result(output: 'success');
            },
        ]);

        $this->service->createSnapshot('test');

        expect(File::isDirectory($this->testPath))->toBeTrue();
    });

    it('creates JSON metadata sidecar file', function () {
        Process::fake([
            '*mysqldump*' => function ($process) {
                preg_match("/> '([^']+)'/", $process->command, $matches);
                if (isset($matches[1])) {
                    File::put($matches[1], 'fake sql content');
                }

                return Process::result(output: 'success');
            },
        ]);

        $filename = $this->service->createSnapshot('test', 'Test description');
        $metadataPath = "{$this->testPath}/{$filename}.json";

        expect(File::exists($metadataPath))->toBeTrue();

        $metadata = json_decode(File::get($metadataPath), true);

        expect($metadata)
            ->toHaveKey('name')
            ->toHaveKey('description')
            ->toHaveKey('created_at')
            ->toHaveKey('size')
            ->and($metadata['name'])->toBe('test')
            ->and($metadata['description'])->toBe('Test description');
    });

    it('calls enforceLimit after creation', function () {
        Config::set('developer.max_snapshots', 2);

        Process::fake([
            '*mysqldump*' => function ($process) {
                preg_match("/> '([^']+)'/", $process->command, $matches);
                if (isset($matches[1])) {
                    File::put($matches[1], 'fake sql content');
                }

                return Process::result(output: 'success');
            },
        ]);

        // Create 3 snapshots
        $this->service->createSnapshot('snapshot-1');
        sleep(1); // Ensure different timestamps
        $this->service->createSnapshot('snapshot-2');
        sleep(1);
        $this->service->createSnapshot('snapshot-3');

        // Should only have 2 snapshots (newest)
        $snapshots = $this->service->listSnapshots();
        expect($snapshots)->toHaveCount(2);
    });

    it('throws RuntimeException on mysqldump failure', function () {
        Process::fake([
            '*mysqldump*' => Process::result(
                output: '',
                errorOutput: 'mysqldump: command not found',
                exitCode: 127
            ),
        ]);

        expect(fn () => $this->service->createSnapshot('test'))
            ->toThrow(RuntimeException::class, 'Failed to create database snapshot');
    });

    it('throws RuntimeException if file is empty', function () {
        Process::fake([
            '*mysqldump*' => function ($process) {
                // Create an empty file
                preg_match("/> '([^']+)'/", $process->command, $matches);
                if (isset($matches[1])) {
                    File::put($matches[1], '');
                }

                return Process::result(output: 'success');
            },
        ]);

        expect(fn () => $this->service->createSnapshot('test'))
            ->toThrow(RuntimeException::class, 'Database snapshot file is empty');
    });
});

describe('listSnapshots', function () {
    it('returns empty collection when no snapshots exist', function () {
        $snapshots = $this->service->listSnapshots();

        expect($snapshots)->toBeInstanceOf(\Illuminate\Support\Collection::class)
            ->and($snapshots)->toBeEmpty();
    });

    it('returns snapshots sorted by date descending', function () {
        // Create fake snapshot files with different timestamps
        $files = [
            'snapshot-1-2026-01-01_120000.sql' => '2026-01-01T12:00:00+00:00',
            'snapshot-2-2026-01-02_120000.sql' => '2026-01-02T12:00:00+00:00',
            'snapshot-3-2026-01-03_120000.sql' => '2026-01-03T12:00:00+00:00',
        ];

        foreach ($files as $filename => $createdAt) {
            $fullPath = "{$this->testPath}/{$filename}";
            File::put($fullPath, 'fake sql content');

            $metadata = [
                'name' => pathinfo($filename, PATHINFO_FILENAME),
                'description' => null,
                'created_at' => $createdAt,
                'size' => strlen('fake sql content'),
            ];
            File::put("{$fullPath}.json", json_encode($metadata));
        }

        $snapshots = $this->service->listSnapshots();

        expect($snapshots)->toHaveCount(3)
            ->and($snapshots->first()['filename'])->toBe('snapshot-3-2026-01-03_120000.sql')
            ->and($snapshots->last()['filename'])->toBe('snapshot-1-2026-01-01_120000.sql');
    });

    it('reads metadata from JSON sidecar files', function () {
        $filename = 'test-snapshot-2026-01-01_120000.sql';
        $fullPath = "{$this->testPath}/{$filename}";

        File::put($fullPath, 'fake sql content');

        $metadata = [
            'name' => 'test-snapshot',
            'description' => 'Test description',
            'created_at' => '2026-01-01T12:00:00+00:00',
            'size' => 123456,
        ];
        File::put("{$fullPath}.json", json_encode($metadata));

        $snapshots = $this->service->listSnapshots();

        expect($snapshots)->toHaveCount(1);

        $snapshot = $snapshots->first();
        expect($snapshot['filename'])->toBe($filename)
            ->and($snapshot['name'])->toBe('test-snapshot')
            ->and($snapshot['description'])->toBe('Test description')
            ->and($snapshot['created_at'])->toBe('2026-01-01T12:00:00+00:00')
            ->and($snapshot['size'])->toContain('KB');
    });

    it('handles SQL files without metadata gracefully', function () {
        $filename = 'test-snapshot-2026-01-01_120000.sql';
        $fullPath = "{$this->testPath}/{$filename}";

        File::put($fullPath, 'fake sql content');
        // No metadata file created

        $snapshots = $this->service->listSnapshots();

        expect($snapshots)->toHaveCount(1);

        $snapshot = $snapshots->first();
        expect($snapshot['filename'])->toBe($filename)
            ->and($snapshot['name'])->toBe('test-snapshot-2026-01-01_120000')
            ->and($snapshot['description'])->toBeNull()
            ->and($snapshot['created_at'])->toBeString()
            ->and($snapshot['size'])->toBeString();
    });
});

describe('deleteSnapshot', function () {
    it('deletes both SQL file and JSON sidecar', function () {
        $filename = 'test-snapshot-2026-01-01_120000.sql';
        $fullPath = "{$this->testPath}/{$filename}";
        $metadataPath = "{$fullPath}.json";

        File::put($fullPath, 'fake sql content');
        File::put($metadataPath, json_encode(['name' => 'test']));

        expect(File::exists($fullPath))->toBeTrue()
            ->and(File::exists($metadataPath))->toBeTrue();

        $result = $this->service->deleteSnapshot($filename);

        expect($result)->toBeTrue()
            ->and(File::exists($fullPath))->toBeFalse()
            ->and(File::exists($metadataPath))->toBeFalse();
    });

    it('returns false if file not found', function () {
        $result = $this->service->deleteSnapshot('nonexistent-file.sql');

        expect($result)->toBeFalse();
    });

    it('returns true on success', function () {
        $filename = 'test-snapshot-2026-01-01_120000.sql';
        $fullPath = "{$this->testPath}/{$filename}";

        File::put($fullPath, 'fake sql content');

        $result = $this->service->deleteSnapshot($filename);

        expect($result)->toBeTrue();
    });
});

describe('enforceLimit', function () {
    it('does nothing when under limit', function () {
        Config::set('developer.max_snapshots', 5);

        // Create 3 snapshots
        for ($i = 1; $i <= 3; $i++) {
            $filename = "snapshot-{$i}-2026-01-0{$i}_120000.sql";
            $fullPath = "{$this->testPath}/{$filename}";
            File::put($fullPath, 'fake sql content');

            $metadata = [
                'name' => "snapshot-{$i}",
                'description' => null,
                'created_at' => "2026-01-0{$i}T12:00:00+00:00",
                'size' => 100,
            ];
            File::put("{$fullPath}.json", json_encode($metadata));
        }

        $this->service->enforceLimit();

        $snapshots = $this->service->listSnapshots();
        expect($snapshots)->toHaveCount(3);
    });

    it('deletes oldest snapshots when over limit', function () {
        Config::set('developer.max_snapshots', 2);

        // Create 4 snapshots
        for ($i = 1; $i <= 4; $i++) {
            $filename = "snapshot-{$i}-2026-01-0{$i}_120000.sql";
            $fullPath = "{$this->testPath}/{$filename}";
            File::put($fullPath, 'fake sql content');

            $metadata = [
                'name' => "snapshot-{$i}",
                'description' => null,
                'created_at' => "2026-01-0{$i}T12:00:00+00:00",
                'size' => 100,
            ];
            File::put("{$fullPath}.json", json_encode($metadata));
        }

        $this->service->enforceLimit();

        $snapshots = $this->service->listSnapshots();

        expect($snapshots)->toHaveCount(2)
            ->and($snapshots->first()['filename'])->toBe('snapshot-4-2026-01-04_120000.sql')
            ->and($snapshots->last()['filename'])->toBe('snapshot-3-2026-01-03_120000.sql');
    });

    it('respects config max_snapshots value', function () {
        Config::set('developer.max_snapshots', 3);

        // Create 5 snapshots
        for ($i = 1; $i <= 5; $i++) {
            $filename = "snapshot-{$i}-2026-01-0{$i}_120000.sql";
            $fullPath = "{$this->testPath}/{$filename}";
            File::put($fullPath, 'fake sql content');

            $metadata = [
                'name' => "snapshot-{$i}",
                'description' => null,
                'created_at' => "2026-01-0{$i}T12:00:00+00:00",
                'size' => 100,
            ];
            File::put("{$fullPath}.json", json_encode($metadata));
        }

        $this->service->enforceLimit();

        $snapshots = $this->service->listSnapshots();
        expect($snapshots)->toHaveCount(3);
    });
});

describe('restoreSnapshot', function () {
    it('throws exception if file not found', function () {
        expect(fn () => $this->service->restoreSnapshot('nonexistent.sql'))
            ->toThrow(RuntimeException::class, 'Snapshot file not found');
    });

    it('throws exception on mysql failure', function () {
        $filename = 'test-snapshot-2026-01-01_120000.sql';
        $fullPath = "{$this->testPath}/{$filename}";

        File::put($fullPath, 'fake sql content');

        Process::fake([
            '*mysql*' => Process::result(
                output: '',
                errorOutput: 'mysql: command not found',
                exitCode: 127
            ),
        ]);

        expect(fn () => $this->service->restoreSnapshot($filename))
            ->toThrow(RuntimeException::class, 'Failed to restore database snapshot');
    });

    it('returns true on successful restore', function () {
        $filename = 'test-snapshot-2026-01-01_120000.sql';
        $fullPath = "{$this->testPath}/{$filename}";

        File::put($fullPath, 'fake sql content');

        Process::fake([
            '*mysql*' => Process::result(output: 'success'),
        ]);

        $result = $this->service->restoreSnapshot($filename);

        expect($result)->toBeTrue();
    });
});
