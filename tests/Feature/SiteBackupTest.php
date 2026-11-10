<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\BackupKind;
use App\Exceptions\SiteBackupException;
use App\Models\Contact;
use App\Models\Event;
use App\Services\SiteBackupService;
use App\Services\SiteBackupSettings;
use App\Services\SqliteDatabaseCopier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A migrated SQLite database file, built once per test process and copied for each test.
 */
function migratedSqliteTemplate(): string
{
    static $template = null;

    if ($template === null) {
        $template = storage_path('framework/testing/backup-template-'.getmypid().'.sqlite');
        @unlink($template);
        touch($template);

        Config::set('database.connections.backup_template', [...config('database.connections.sqlite'), 'database' => $template]);
        Artisan::call('migrate', ['--database' => 'backup_template', '--force' => true]);
        DB::purge('backup_template');
    }

    return $template;
}

beforeEach(function () {
    $this->root = storage_path('framework/testing/site-backup-'.Str::uuid());
    File::ensureDirectoryExists($this->root);

    $this->database = "{$this->root}/live.sqlite";
    File::copy(migratedSqliteTemplate(), $this->database);

    Config::set('database.connections.backup_file', [...config('database.connections.sqlite'), 'database' => $this->database]);
    Config::set('database.default', 'backup_file');
    Config::set('backup.path', "{$this->root}/Backups");
    Config::set('backup.keep_automatic', 3);
    Config::set('app.version', '26.11.1');

    $this->files = "{$this->root}/app";
    File::ensureDirectoryExists("{$this->files}/private/gallery/1");
    File::ensureDirectoryExists("{$this->files}/private/exports/gallery/1");
    File::ensureDirectoryExists("{$this->files}/public");
    File::put("{$this->files}/private/gallery/1/photo.jpg", 'photo');
    File::put("{$this->files}/private/exports/gallery/1/album.zip", 'temporary album');
    File::put("{$this->files}/public/logo.png", 'logo');

    File::put("{$this->root}/.env", "APP_NAME=\"Field Day Commander\"\nAPP_KEY=base64:original\nMAIL_HOST=mail.example.org\nDB_DATABASE={$this->database}\n");
    app()->useEnvironmentPath($this->root);

    $this->backups = new SiteBackupService(new SqliteDatabaseCopier, new SiteBackupSettings, null, $this->files);
    app()->instance(SiteBackupService::class, $this->backups);
});

afterEach(function () {
    // RefreshDatabase rolls back the default connection's transaction on teardown.
    Config::set('database.default', 'sqlite');
    DB::purge('backup_file');
    File::deleteDirectory($this->root);
});

/**
 * @return list<string>
 */
function zipEntries(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $entries = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $entries[] = $zip->getNameIndex($index);
    }

    $zip->close();
    sort($entries);

    return $entries;
}

test('a backup holds the database, uploaded files and the settings a new server needs', function () {
    $event = Event::factory()->create(['name' => 'Field Day 2026']);
    Contact::factory()->count(3)->create();

    $path = $this->backups->create();

    expect(basename($path))->toMatch('/^Field Day Commander backup \d{4}-\d{2}-\d{2} \d{4}Z\.zip$/')
        ->and(zipEntries($path))->toBe([
            'backup.json',
            'database.sqlite',
            'files/private/gallery/1/photo.jpg',
            'files/public/logo.png',
            'settings.env',
        ]);

    $zip = new ZipArchive;
    $zip->open($path);
    $settings = $zip->getFromName('settings.env');
    $zip->close();

    expect($settings)->toBe("APP_KEY=base64:original\nMAIL_HOST=mail.example.org\n")
        ->and($this->backups->inspect($path))->toMatchArray([
            'version' => '26.11.1',
            'kind' => 'manual',
            'includes_files' => true,
            'summary' => ['event' => $event->fresh()->name, 'contacts' => 3],
        ]);
});

test('automatic backups leave out uploaded files and only the newest are kept', function () {
    $paths = [];

    foreach (range(1, 5) as $hour) {
        $this->travel(1)->hours();
        $paths[] = $this->backups->create(BackupKind::Automatic);
    }

    $manual = $this->backups->create();

    expect(zipEntries($paths[4]))->toBe(['backup.json', 'database.sqlite', 'settings.env'])
        ->and(collect(File::files(config('backup.path')))->map->getFilename()->sort()->values()->all())
        ->toBe(collect([...array_slice($paths, 2), $manual])->map(fn ($p) => basename($p))->sort()->values()->all());
});

test('two backups in the same minute get different names', function () {
    $this->freezeTime();

    $first = $this->backups->create();
    $second = $this->backups->create();

    expect($second)->not->toBe($first)
        ->and(basename($second))->toEndWith('Z (2).zip');
});

test('a restore brings back the data, files and settings, and saves what it replaced first', function () {
    Contact::factory()->count(2)->create();
    $backup = $this->backups->create();

    Contact::factory()->count(5)->create();
    File::put("{$this->files}/private/gallery/1/photo.jpg", 'changed');
    File::put("{$this->files}/private/gallery/1/new.jpg", 'added after the backup');
    File::put("{$this->root}/.env", "APP_NAME=\"Field Day Commander\"\nAPP_KEY=base64:new-server\nDB_DATABASE={$this->database}\n");

    $result = $this->backups->restore($backup);

    expect(Contact::count())->toBe(2)
        ->and(File::get("{$this->files}/private/gallery/1/photo.jpg"))->toBe('photo')
        ->and(File::exists("{$this->files}/private/gallery/1/new.jpg"))->toBeFalse()
        ->and(File::get("{$this->files}/private/exports/gallery/1/album.zip"))->toBe('temporary album')
        ->and(File::get("{$this->root}/.env"))->toBe("APP_NAME=\"Field Day Commander\"\nAPP_KEY=base64:original\nDB_DATABASE={$this->database}\nMAIL_HOST=mail.example.org\n")
        ->and(basename($result['safety_backup']))->toStartWith('Field Day Commander backup before restore ')
        ->and($this->backups->inspect($result['safety_backup'])['summary']['contacts'])->toBe(7);
});

test('restoring an automatic backup leaves the photos on this server alone', function () {
    $backup = $this->backups->create(BackupKind::Automatic);
    File::put("{$this->files}/private/gallery/1/new.jpg", 'added after the backup');

    $this->backups->restore($backup);

    expect(File::get("{$this->files}/private/gallery/1/new.jpg"))->toBe('added after the backup');
});

test('a backup from a newer version is refused before anything changes', function () {
    $backup = $this->backups->create();
    Config::set('app.version', '26.10.9');
    Contact::factory()->create();

    expect(fn () => $this->backups->restore($backup))
        ->toThrow(SiteBackupException::class, 'made by Field Day Commander 26.11.1');

    expect(Contact::count())->toBe(1)
        ->and(File::files(config('backup.path')))->toHaveCount(1);
});

test('a backup with paths that escape its folder is refused', function (string $entry) {
    $backup = $this->backups->create();
    $zip = new ZipArchive;
    $zip->open($backup);
    $zip->addFromString($entry, 'sneaky');
    $zip->close();

    expect(fn () => $this->backups->restore($backup))
        ->toThrow(SiteBackupException::class, 'unexpected files');

    expect(File::files(config('backup.path')))->toHaveCount(1);
})->with([
    'parent folder' => ['files/private/../../../.env'],
    'other folder' => ['files/framework/views/x.php'],
    'top level' => ['artisan'],
]);

test('a damaged database in a backup is refused', function () {
    $backup = $this->backups->create();
    $zip = new ZipArchive;
    $zip->open($backup);
    $zip->addFromString('database.sqlite', 'not a database');
    $zip->close();

    expect(fn () => $this->backups->restore($backup))
        ->toThrow(SiteBackupException::class, 'damaged');
});

test('a file that is not a backup is refused', function () {
    $notABackup = "{$this->root}/photos.zip";
    $zip = new ZipArchive;
    $zip->open($notABackup, ZipArchive::CREATE);
    $zip->addFromString('IMG_0001.jpg', 'photo');
    $zip->close();

    expect(fn () => $this->backups->inspect($notABackup))
        ->toThrow(SiteBackupException::class, 'not a Field Day Commander backup');
});

test('the commands back up and restore, and report JSON for the Windows scripts', function () {
    Contact::factory()->count(2)->create();

    $this->artisan('backup:create', ['--json' => true])->assertSuccessful();
    $backup = File::files(config('backup.path'))[0]->getPathname();

    Contact::factory()->create();

    $this->artisan('backup:restore', ['file' => $backup, '--inspect' => true, '--json' => true])
        ->expectsOutputToContain('"contacts":2')
        ->assertSuccessful();

    $this->artisan('backup:restore', ['file' => $backup])
        ->expectsConfirmation("Replace all of this server's data with this backup?", 'yes')
        ->assertSuccessful();

    expect(Contact::count())->toBe(2);
});

test('the commands explain that MySQL servers use backup.sh', function () {
    Config::set('database.default', 'mysql');

    $this->artisan('backup:create')
        ->expectsOutputToContain('backup.sh')
        ->assertFailed();
});

test('automatic backups are off unless the install turns them on', function () {
    $commands = collect(app(Schedule::class)->events())->map->command->implode("\n");

    expect($commands)->not->toContain('backup:create');
});

test('when turned on, automatic backups run hourly during an event and daily otherwise', function () {
    Config::set('backup.automatic', true);
    require base_path('routes/console.php');

    $backups = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'backup:create --automatic'));

    expect($backups->map->expression->sort()->values()->all())->toBe(['0 * * * *', '0 3 * * *']);

    Event::factory()->create(['start_time' => now()->subHour(), 'end_time' => now()->addHour()]);

    $due = $backups->filter(fn ($event) => $event->filtersPass($this->app))->map->expression->values()->all();

    expect($due)->toBe(['0 * * * *']);
});
