<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\AdifImportStatus;
use App\Enums\AdifRecordStatus;
use App\Jobs\ImportAdifContacts;
use App\Jobs\ValidateAdifImport;
use App\Livewire\Admin\AdifImport;
use App\Models\AdifImport as AdifImportModel;
use App\Models\AdifImportRecord;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\AdifImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'sort_order' => 3]);
    Mode::create(['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    Mode::create(['name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    Mode::create(['name' => 'Digital', 'category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
    Section::create(['code' => 'CT', 'name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);
    Section::create(['code' => 'NNJ', 'name' => 'Northern New Jersey', 'region' => 'W2', 'is_active' => true]);

    $eventType = EventType::firstOrCreate(
        ['code' => 'FD'],
        ['name' => 'Field Day', 'description' => 'ARRL Field Day', 'is_active' => true],
    );

    OperatingClass::firstOrCreate(
        ['event_type_id' => $eventType->id, 'code' => 'A'],
        ['name' => 'Class A', 'description' => 'Class A', 'allows_gota' => false, 'allows_free_vhf' => false, 'requires_emergency_power' => false],
    );

    OperatingClass::firstOrCreate(
        ['event_type_id' => $eventType->id, 'code' => 'B'],
        ['name' => 'Class B', 'description' => 'Class B', 'allows_gota' => false, 'allows_free_vhf' => false, 'requires_emergency_power' => false],
    );

    $this->event = Event::factory()->create([
        'event_type_id' => $eventType->id,
        // The fixtures log QSOs at fixed times on today's date, so the window
        // spans the whole day rather than a range around the current time.
        'start_time' => now()->startOfDay(),
        'end_time' => now()->endOfDay(),
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'DESKTOP-11-2024',
    ]);

    $this->user = User::factory()->create(['call_sign' => 'K3CPK']);
    $permission = Permission::firstOrCreate(['name' => 'import-contacts']);
    $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $this->user->assignRole($role);

    // Set event context (key matches EventContextService::SESSION_KEY = 'viewing_event_id')
    session(['viewing_event_id' => $this->event->id]);
});

test('non-admin cannot access import page', function () {
    $regularUser = User::factory()->create();

    $this->actingAs($regularUser)
        ->get(route('admin.import-adif'))
        ->assertForbidden();
});

test('admin can access import page', function () {
    $this->actingAs($this->user)
        ->get(route('admin.import-adif'))
        ->assertOk();
});

test('can upload and parse an ADIF file', function () {
    $adifContent = "ADIF Export\n<EOH>\n <CALL:4>W1AW <QSO_DATE:8>20260410 <TIME_ON:6>005505 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>\n <CALL:5>W2WSX <QSO_DATE:8>20260410 <TIME_ON:6>005853 <ARRL_SECT:3>NNJ <BAND:3>40M <STATION_CALLSIGN:5>K3CPK <MODE:4>RTTY <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>2B <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>";

    $file = UploadedFile::fake()->createWithContent('test.adi', $adifContent);

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', $file)
        ->call('uploadFile')
        ->assertSet('step', 2);
});

test('full import flow creates contacts', function () {
    $qsoDate = now()->format('Ymd');
    $adifContent = "ADIF Export\n<EOH>\n <CALL:4>W1AW <QSO_DATE:8>{$qsoDate} <TIME_ON:6>120000 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>";

    $file = UploadedFile::fake()->createWithContent('test.adi', $adifContent);

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', $file)
        ->call('uploadFile')
        ->call('applyMappingsAndContinue')
        ->assertSet('step', 3)
        ->call('executeImport')
        ->assertSet('importStatus', 'completed');

    expect(Contact::where('callsign', 'W1AW')->exists())->toBeTrue();
});

test('full import flow stores the ADIF submode on the contact', function () {
    $qsoDate = now()->format('Ymd');
    $adifContent = "ADIF Export\n<EOH>\n <CALL:4>W1AW <QSO_DATE:8>{$qsoDate} <TIME_ON:6>120000 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:4>MFSK <SUBMODE:3>FT8 <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>\n <CALL:5>W2WSX <QSO_DATE:8>{$qsoDate} <TIME_ON:6>121000 <ARRL_SECT:3>NNJ <BAND:3>40M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>2B <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>";

    $file = UploadedFile::fake()->createWithContent('test.adi', $adifContent);

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', $file)
        ->call('uploadFile')
        ->call('applyMappingsAndContinue')
        ->call('executeImport');

    expect(Contact::where('callsign', 'W1AW')->value('submode'))->toBe('FT8')
        ->and(Contact::where('callsign', 'W2WSX')->value('submode'))->toBe('SSB');
});

/**
 * An ADIF file whose N1MM station name matches no configured station.
 */
function adifWithUnknownStation(): UploadedFile
{
    $qsoDate = now()->format('Ymd');
    $adifContent = "ADIF Export\n<EOH>\n <CALL:4>W1AW <QSO_DATE:8>{$qsoDate} <TIME_ON:6>120000 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:7>OTHERPC <EOR>";

    return UploadedFile::fake()->createWithContent('test.adi', $adifContent);
}

test('import id cannot be changed from the client', function () {
    $otherImport = AdifImportModel::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => User::factory()->create()->id,
    ]);

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('importId', $otherImport->id);
})->throws(CannotUpdateLockedPropertyException::class);

test('rejects a station mapping that belongs to another event', function () {
    $otherConfig = EventConfiguration::factory()->create();
    $foreignStation = Station::factory()->create(['event_configuration_id' => $otherConfig->id]);

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithUnknownStation())
        ->call('uploadFile')
        ->set('stationMappings.OTHERPC', $foreignStation->id)
        ->call('applyMappingsAndContinue')
        ->assertHasErrors('mapping')
        ->assertSet('step', 2);

    expect(AdifImportRecord::where('station_id', $foreignStation->id)->exists())->toBeFalse();
});

test('accepts a station mapping from the import event', function () {
    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithUnknownStation())
        ->call('uploadFile')
        ->set('stationMappings.OTHERPC', $this->station->id)
        ->call('applyMappingsAndContinue')
        ->assertHasNoErrors()
        ->assertSet('step', 3);
});

test('rejects an operator mapping to a nonexistent user', function () {
    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithUnknownStation())
        ->call('uploadFile')
        ->set('stationMappings.OTHERPC', $this->station->id)
        ->set('operatorMappings.K3CPK', 999999)
        ->call('applyMappingsAndContinue')
        ->assertHasErrors('mapping')
        ->assertSet('step', 2);
});

test('reports failure and logs when the import service throws', function () {
    Log::spy();

    $this->mock(AdifImportService::class)
        ->shouldReceive('import')
        ->andThrow(new RuntimeException('boom'));

    $qsoDate = now()->format('Ymd');
    $adifContent = "ADIF Export\n<EOH>\n <CALL:4>W1AW <QSO_DATE:8>{$qsoDate} <TIME_ON:6>120000 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>";

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', UploadedFile::fake()->createWithContent('test.adi', $adifContent))
        ->call('uploadFile')
        ->call('applyMappingsAndContinue')
        ->call('executeImport')
        ->assertSet('importStatus', 'failed');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'ADIF import failed'
            && $context['exception'] instanceof RuntimeException);
});

test('reports failure when the import service marks the import failed', function () {
    $qsoDate = now()->format('Ymd');
    $adifContent = "ADIF Export\n<EOH>\n <CALL:4>W1AW <QSO_DATE:8>{$qsoDate} <TIME_ON:6>120000 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>";

    $this->mock(AdifImportService::class)
        ->shouldReceive('import')
        ->andReturnUsing(fn (AdifImportModel $import) => $import->update(['status' => AdifImportStatus::Failed]));

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', UploadedFile::fake()->createWithContent('test.adi', $adifContent))
        ->call('uploadFile')
        ->call('applyMappingsAndContinue')
        ->call('executeImport')
        ->assertSet('importStatus', 'failed');
});

test('validation and the import run on the queue while the wizard polls their progress', function () {
    Queue::fake();

    $qsoDate = now()->format('Ymd');
    $adifContent = "ADIF Export\n<EOH>\n <CALL:4>W1AW <QSO_DATE:8>{$qsoDate} <TIME_ON:6>120000 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>";

    $component = Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', UploadedFile::fake()->createWithContent('test.adi', $adifContent))
        ->call('uploadFile')
        ->call('applyMappingsAndContinue')
        ->assertSet('step', 3)
        ->assertSet('importStatus', 'validating')
        ->assertSeeHtml('wire:poll.1s.keep-alive="refreshProgress"');

    $import = AdifImportModel::sole();
    Queue::assertPushed(ValidateAdifImport::class, fn (ValidateAdifImport $job) => $job->adifImportId === $import->id);

    app()->call([new ValidateAdifImport($import->id), 'handle']);

    $component->call('refreshProgress')
        ->assertSet('importStatus', '')
        ->assertSet('matchSummary.new', 1)
        ->call('executeImport')
        ->assertSet('importStatus', 'importing')
        ->assertSee('0 of 1 records');

    Queue::assertPushed(ImportAdifContacts::class, fn (ImportAdifContacts $job) => $job->adifImportId === $import->id);
    expect(Contact::count())->toBe(0);

    app()->call([new ImportAdifContacts($import->id), 'handle']);

    $component->call('refreshProgress')
        ->assertSet('importStatus', 'completed')
        ->assertSee('Import complete!');

    expect(Contact::where('callsign', 'W1AW')->exists())->toBeTrue();
});

test('an import job that runs out of time queues another run for the rest', function () {
    Queue::fake();

    $import = AdifImportModel::factory()->create([
        'event_configuration_id' => $this->config->id,
        'user_id' => $this->user->id,
        'status' => AdifImportStatus::PendingReview,
    ]);

    $service = Mockery::mock(AdifImportService::class);
    $service->shouldReceive('import')->once()->andReturnFalse();

    (new ImportAdifContacts($import->id))->handle($service);

    Queue::assertPushed(ImportAdifContacts::class, fn (ImportAdifContacts $job) => $job->adifImportId === $import->id);
});

test('upload and auto-mapping use a bounded number of queries however many records the file has', function () {
    $qsoDate = now()->format('Ymd');
    $record = fn (int $i) => sprintf(
        ' <CALL:%d>%s <QSO_DATE:8>%s <TIME_ON:6>120000 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>',
        strlen("K{$i}ABC"), "K{$i}ABC", $qsoDate,
    );
    $file = fn (int $count) => UploadedFile::fake()->createWithContent('test.adi', "ADIF Export\n<EOH>\n".implode("\n", array_map($record, range(1, $count))));

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $countFor = function (int $records) use ($file, &$queries): int {
        $component = Livewire::actingAs($this->user)->test(AdifImport::class)->set('adifFile', $file($records));
        $queries = 0;
        $component->call('uploadFile');

        return $queries;
    };

    $few = $countFor(5);
    $many = $countFor(200);

    expect($many - $few)->toBeLessThan(10);
});

/**
 * An ADIF file with in-window records and records a day before the event.
 */
function adifWithOutOfWindowRecords(int $valid, int $outOfWindow): UploadedFile
{
    $record = fn (string $call, string $date) => sprintf(
        ' <CALL:%d>%s <QSO_DATE:8>%s <TIME_ON:6>120000 <ARRL_SECT:2>CT <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>',
        strlen($call), $call, $date,
    );

    $records = [];
    for ($i = 1; $i <= $valid; $i++) {
        $records[] = $record("K{$i}VAL", now()->format('Ymd'));
    }
    for ($i = 1; $i <= $outOfWindow; $i++) {
        $records[] = $record("K{$i}OUT", now()->subDays(3)->format('Ymd'));
    }

    return UploadedFile::fake()->createWithContent('test.adi', "ADIF Export\n<EOH>\n".implode("\n", $records));
}

test('skip all invalid skips every invalid record at once so the import can run', function () {
    $component = Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithOutOfWindowRecords(3, 4))
        ->call('uploadFile')
        ->call('applyMappingsAndContinue')
        ->assertSet('step', 3)
        ->assertSet('matchSummary.invalid', 4)
        ->assertSee('4 invalid records')
        ->assertSee('Skip all 4 invalid');

    $import = AdifImportModel::sole();

    $component->call('executeImport');
    expect($import->refresh()->status)->toBe(AdifImportStatus::PendingReview);

    $component->call('skipAllInvalid')
        ->assertSet('matchSummary.invalid', 0)
        ->assertSet('matchSummary.skip', 4)
        ->assertSet('matchSummary.new', 3)
        ->assertDontSee('Skip all');

    // A skipped invalid record keeps its errors and can't be included again.
    $skipped = $import->records()->where('status', AdifRecordStatus::Skipped)->first();
    expect($skipped->notes)->toContain('outside event window');

    $component->assertDontSeeHtml("toggleSkip({$skipped->id})")
        ->call('toggleSkip', $skipped->id);
    expect($skipped->refresh()->status)->toBe(AdifRecordStatus::Skipped);

    $component->call('executeImport')
        ->assertSet('importStatus', 'completed');

    expect(Contact::count())->toBe(3)
        ->and($import->refresh()->skipped_records)->toBe(4);
});

test('a skipped valid record can still be included again', function () {
    $component = Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithOutOfWindowRecords(2, 0))
        ->call('uploadFile')
        ->call('applyMappingsAndContinue');

    $record = AdifImportRecord::query()->where('status', AdifRecordStatus::Ready)->first();

    $component->call('toggleSkip', $record->id)
        ->assertSet('matchSummary.skip', 1)
        ->assertSeeHtml("toggleSkip({$record->id})")
        ->call('toggleSkip', $record->id)
        ->assertSet('matchSummary.skip', 0);

    expect($record->refresh()->status)->toBe(AdifRecordStatus::Ready);
});

test('the review table is paginated and can be filtered by record status', function () {
    $perPage = AdifImport::RECORDS_PER_PAGE;

    $component = Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithOutOfWindowRecords($perPage, 5))
        ->call('uploadFile')
        ->call('applyMappingsAndContinue')
        ->assertSet('step', 3);

    $records = $component->instance()->importRecords;
    expect($records->total())->toBe($perPage + 5)
        ->and($records->count())->toBe($perPage);

    $component->call('setPage', 2);
    expect($component->instance()->importRecords->count())->toBe(5);

    $component->set('recordFilter', 'invalid');
    $invalid = $component->instance()->importRecords;
    expect($invalid->currentPage())->toBe(1)
        ->and($invalid->total())->toBe(5)
        ->and($invalid->getCollection()->every(fn (AdifImportRecord $r) => $r->status === AdifRecordStatus::Invalid))->toBeTrue();
    $component->assertSee('K1OUT')->assertDontSee('K1VAL');

    $component->set('recordFilter', 'duplicate')
        ->assertSee('No records match this filter.');
    expect($component->instance()->importRecords->total())->toBe(0);

    $component->set('recordFilter', 'bogus')
        ->assertSet('recordFilter', 'all');
});

test('the progress poll picks up completion written to the database', function () {
    Queue::fake();

    $component = Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithOutOfWindowRecords(1, 0))
        ->call('uploadFile')
        ->call('applyMappingsAndContinue');

    $import = AdifImportModel::sole();
    app()->call([new ValidateAdifImport($import->id), 'handle']);

    $component->call('refreshProgress')
        ->call('executeImport')
        ->assertSet('importStatus', 'importing')
        ->assertSeeHtml('wire:poll.1s.keep-alive="refreshProgress"');

    // The queued job finishes in another process.
    AdifImportModel::query()->whereKey($import->id)->update([
        'status' => AdifImportStatus::Completed,
        'imported_records' => 1,
    ]);

    $component->call('refreshProgress')
        ->assertSet('importStatus', 'completed')
        ->assertSee('Import complete!')
        ->assertSee('1 imported')
        ->assertDontSeeHtml('wire:poll');
});

test('the review table shows the remapped section, not the one in the file', function () {
    $adif = UploadedFile::fake()->createWithContent('test.adi', "ADIF Export\n<EOH>\n".sprintf(
        ' <CALL:5>K1ABC <QSO_DATE:8>%s <TIME_ON:6>120000 <ARRL_SECT:3>XYZ <BAND:3>20M <STATION_CALLSIGN:5>K3CPK <MODE:3>SSB <OPERATOR:5>K3CPK <APP_N1MM_EXCHANGE1:2>3A <APP_N1MM_NETBIOSNAME:15>DESKTOP-11-2024 <EOR>',
        now()->format('Ymd'),
    ));
    $nnj = Section::where('code', 'NNJ')->first();

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', $adif)
        ->call('uploadFile')
        ->set('sectionMappings.XYZ', $nnj->id)
        ->call('applyMappingsAndContinue')
        ->assertSet('step', 3)
        ->assertSeeHtml('<td>NNJ</td>')
        ->assertDontSeeHtml('<td>XYZ</td>');
});

/**
 * A WSJT-X style ADIF file: no OPERATOR, and optionally no section or station.
 */
function adifWithoutOperator(bool $withSection = true, bool $withStation = true): UploadedFile
{
    $qsoDate = now()->format('Ymd');
    $section = $withSection ? '<ARRL_SECT:2>CT ' : '';
    $station = $withStation ? '<STATION_CALLSIGN:15>DESKTOP-11-2024 ' : '';
    $records = '';

    foreach (['W1AW', 'W2WSX', 'K1ABC'] as $i => $call) {
        $records .= sprintf(
            ' <CALL:%d>%s <QSO_DATE:8>%s <TIME_ON:6>12%02d00 %s<BAND:3>20M %s<MODE:3>FT8 <CLASS:2>3A <EOR>'."\n",
            strlen($call), $call, $qsoDate, $i, $section, $station,
        );
    }

    return UploadedFile::fake()->createWithContent('wsjtx.adi', "WSJT-X ADIF Export\n<EOH>\n".$records);
}

test('offers the importing user as operator for records without one', function () {
    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithoutOperator())
        ->call('uploadFile')
        ->assertSet('mappingReport.missing_operators', 3)
        ->assertSet('missingDefaults.operator_user_id', $this->user->id)
        ->assertSee('Missing Fields')
        ->assertSee('Operator for 3 records with no operator')
        ->assertSeeHtml('id="missing-operator"');
});

test('imports records without an operator under the chosen default', function () {
    $operator = User::factory()->create(['call_sign' => 'W9OP']);

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithoutOperator())
        ->call('uploadFile')
        ->set('missingDefaults.operator_user_id', (string) $operator->id)
        ->call('applyMappingsAndContinue')
        ->assertHasNoErrors()
        ->assertSet('step', 3)
        ->call('executeImport')
        ->assertSet('importStatus', 'completed');

    expect(Contact::count())->toBe(3)
        ->and(Contact::pluck('logger_user_id')->unique()->values()->all())->toBe([$operator->id]);
});

test('still blocks records without an operator when the default is cleared', function () {
    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithoutOperator())
        ->call('uploadFile')
        ->set('missingDefaults.operator_user_id', '')
        ->call('applyMappingsAndContinue')
        ->assertHasErrors('mapping')
        ->assertSet('step', 2);
});

test('rejects a missing-operator default that is not a real user', function () {
    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithoutOperator())
        ->call('uploadFile')
        ->set('missingDefaults.operator_user_id', 999999)
        ->call('applyMappingsAndContinue')
        ->assertHasErrors('mapping')
        ->assertSet('step', 2);

    expect(AdifImportRecord::whereNotNull('operator_user_id')->exists())->toBeFalse();
});

test('offers defaults for records without a section or station', function () {
    $section = Section::where('code', 'NNJ')->first();

    $component = Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithoutOperator(withSection: false, withStation: false))
        ->call('uploadFile')
        ->assertSet('mappingReport.missing_sections', 3)
        ->assertSet('mappingReport.missing_stations', 3)
        ->assertSet('mappingReport.unmapped_sections', [])
        ->assertSet('mappingReport.unmapped_stations', [])
        ->assertSet('missingDefaults.station_id', $this->station->id)
        ->assertSee('Section for 3 records with no section')
        ->assertSee('Station for 3 records with no station');

    // Section has no safe guess, so it blocks until the person picks one.
    $component->call('applyMappingsAndContinue')
        ->assertHasErrors('mapping')
        ->assertSet('step', 2);

    $component->set('missingDefaults.section_id', (string) $section->id)
        ->call('applyMappingsAndContinue')
        ->assertHasNoErrors()
        ->assertSet('step', 3);

    expect(AdifImportRecord::where('section_id', $section->id)->where('station_id', $this->station->id)->count())->toBe(3);
});

test('rejects a missing-station default from another event', function () {
    $foreignStation = Station::factory()->create(['event_configuration_id' => EventConfiguration::factory()->create()->id]);

    Livewire::actingAs($this->user)
        ->test(AdifImport::class)
        ->set('adifFile', adifWithoutOperator(withStation: false))
        ->call('uploadFile')
        ->set('missingDefaults.station_id', $foreignStation->id)
        ->call('applyMappingsAndContinue')
        ->assertHasErrors('mapping')
        ->assertSet('step', 2);
});
