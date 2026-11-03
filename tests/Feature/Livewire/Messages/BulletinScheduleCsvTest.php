<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Messages\W1awBulletinForm;
use App\Models\BulletinScheduleEntry;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\User;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->travelTo(now());
    $this->seed([EventTypeSeeder::class, BonusTypeSeeder::class]);

    Permission::firstOrCreate(['name' => 'log-contacts']);
    Permission::firstOrCreate(['name' => 'manage-bulletins']);

    $this->event = Event::factory()->create([
        'event_type_id' => EventType::where('code', 'FD')->first()->id,
        'start_time' => '2027-06-26 18:00:00',
        'end_time' => '2027-06-27 20:59:00',
    ]);
    EventConfiguration::factory()->create(['event_id' => $this->event->id]);

    $this->operator = User::factory()->create();
    $this->operator->givePermissionTo('log-contacts');

    $this->manager = User::factory()->create();
    $this->manager->givePermissionTo(['log-contacts', 'manage-bulletins']);

    session(['viewing_event_id' => $this->event->id]);
});

function scheduleCsv(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('schedule.csv', $contents);
}

test('manager imports a schedule from CSV', function () {
    $csv = "\u{FEFF}time_utc,mode,frequencies,source,notes\r\n"
        ."2027-06-26 21:00,CW,\"3.5815, 7.0475,14.0475\",W1AW,\r\n"
        ."2027-06-26T22:00Z,rtty,7.095;14.095,,Listen on USB\r\n"
        ."\r\n"
        ."2027-06-27 01:00 UTC,Phone,3.990,k6kph,\r\n";

    Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->set('scheduleCsv', scheduleCsv($csv))
        ->call('importScheduleCsv')
        ->assertHasNoErrors()
        ->assertSet('scheduleImportErrors', [])
        ->assertDispatched('toast', title: 'Imported 3 transmissions');

    $entries = BulletinScheduleEntry::forEvent($this->event->id)->orderBy('scheduled_at')->get();

    expect($entries)->toHaveCount(3)
        ->and($entries[0]->scheduled_at->format('Y-m-d H:i'))->toBe('2027-06-26 21:00')
        ->and($entries[0]->frequencies)->toBe('3.5815, 7.0475, 14.0475')
        ->and($entries[0]->created_by)->toBe($this->manager->id)
        ->and($entries[1]->mode)->toBe('digital')
        ->and($entries[1]->frequencies)->toBe('7.095, 14.095')
        ->and($entries[1]->source)->toBe('W1AW')
        ->and($entries[1]->notes)->toBe('Listen on USB')
        ->and($entries[2]->mode)->toBe('phone')
        ->and($entries[2]->source)->toBe('K6KPH');
});

test('columns can come in any order with friendly headers', function () {
    $csv = "Mode,Frequencies (MHz),Time (UTC)\ncw,7.0475,2027-06-26 21:00\n";

    Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->set('scheduleCsv', scheduleCsv($csv))
        ->call('importScheduleCsv')
        ->assertSet('scheduleImportErrors', []);

    expect(BulletinScheduleEntry::forEvent($this->event->id)->sole()->source)->toBe('W1AW');
});

test('nothing is imported when any row is invalid', function () {
    $csv = "time_utc,mode,frequencies,source\n"
        ."2027-06-26 21:00,cw,7.0475,W1AW\n"
        ."06/26/2027 22:00,cw,7.0475,W1AW\n"
        ."2027-06-26 23:00,sstv,7.0475,W1AW\n"
        ."2027-06-27 00:00,cw,forty meters,W1AW\n"
        ."2027-06-27 01:00,cw,7.0475,N0CALL\n"
        ."2026-06-27 01:00,cw,7.0475,W1AW\n";

    $component = Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->set('scheduleCsv', scheduleCsv($csv))
        ->call('importScheduleCsv')
        ->assertNotDispatched('toast')
        ->assertSee('Nothing was imported');

    $errors = $component->get('scheduleImportErrors');

    expect($errors)->toHaveCount(5)
        ->and($errors[0])->toStartWith('Row 3:')->toContain('YYYY-MM-DD HH:MM')
        ->and($errors[1])->toStartWith('Row 4:')->toContain('isn\'t a mode')
        ->and($errors[2])->toStartWith('Row 5:')->toContain('frequencies')
        ->and($errors[3])->toStartWith('Row 6:')->toContain('isn\'t a sender')
        ->and($errors[4])->toStartWith('Row 7:')->toContain('is not during')
        ->and(BulletinScheduleEntry::count())->toBe(0);
});

test('a file without the required columns is rejected', function () {
    Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->set('scheduleCsv', scheduleCsv("when,what\n2027-06-26 21:00,cw\n"))
        ->call('importScheduleCsv')
        ->assertSet('scheduleImportErrors', fn (array $errors) => str_contains($errors[0], 'Missing: time_utc, mode, frequencies'));

    expect(BulletinScheduleEntry::count())->toBe(0);
});

test('transmissions already on the schedule are skipped', function () {
    BulletinScheduleEntry::factory()->create([
        'event_id' => $this->event->id,
        'scheduled_at' => '2027-06-26 21:00:00',
        'mode' => 'cw',
        'source' => 'W1AW',
    ]);

    $csv = "time_utc,mode,frequencies,source\n"
        ."2027-06-26 21:00,cw,7.0475,W1AW\n"
        ."2027-06-26 22:00,phone,3.990,W1AW\n"
        ."2027-06-26 22:00,phone,3.990,W1AW\n";

    Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->set('scheduleCsv', scheduleCsv($csv))
        ->call('importScheduleCsv')
        ->assertDispatched('toast', title: 'Imported 1 transmission, skipped 2 already on the schedule');

    expect(BulletinScheduleEntry::forEvent($this->event->id)->count())->toBe(2);
});

test('replacing the schedule removes the old transmissions first', function () {
    BulletinScheduleEntry::factory()->count(3)->create(['event_id' => $this->event->id]);
    $otherEvent = BulletinScheduleEntry::factory()->create();

    Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->set('scheduleCsv', scheduleCsv("time_utc,mode,frequencies\n2027-06-26 21:00,cw,7.0475\n"))
        ->set('replaceSchedule', true)
        ->call('importScheduleCsv')
        ->assertSet('replaceSchedule', false);

    expect(BulletinScheduleEntry::forEvent($this->event->id)->count())->toBe(1)
        ->and($otherEvent->fresh())->not->toBeNull();
});

test('an exported schedule imports back unchanged', function () {
    BulletinScheduleEntry::factory()->create([
        'event_id' => $this->event->id,
        'scheduled_at' => '2027-06-26 21:00:00',
        'mode' => 'digital',
        'frequencies' => '7.095, 14.095',
        'source' => 'K6KPH',
        'notes' => '=Listen on USB',
    ]);

    $response = Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->call('exportScheduleCsv')
        ->assertFileDownloaded();

    $csv = $response->effects['download']['content'] ?? null;
    $csv = base64_decode($csv);

    expect($csv)->toContain("time_utc,mode,frequencies,source,notes\n")
        ->toContain("\"2027-06-26 21:00\",digital,\"7.095, 14.095\",K6KPH,\"'=Listen on USB\"");

    BulletinScheduleEntry::query()->delete();

    Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->set('scheduleCsv', scheduleCsv($csv))
        ->call('importScheduleCsv')
        ->assertSet('scheduleImportErrors', []);

    $entry = BulletinScheduleEntry::sole();

    expect($entry->scheduled_at->format('Y-m-d H:i'))->toBe('2027-06-26 21:00')
        ->and($entry->mode)->toBe('digital')
        ->and($entry->frequencies)->toBe('7.095, 14.095')
        ->and($entry->source)->toBe('K6KPH')
        ->and($entry->notes)->toBe('=Listen on USB');
});

test('operators cannot import or export, or see the controls', function () {
    Livewire::actingAs($this->operator)
        ->test(W1awBulletinForm::class)
        ->assertDontSee('Upload Schedule')
        ->assertDontSee('Import CSV')
        ->call('openScheduleUpload')
        ->assertForbidden();

    Livewire::actingAs($this->operator)
        ->test(W1awBulletinForm::class)
        ->set('scheduleCsv', scheduleCsv("time_utc,mode,frequencies\n2027-06-26 21:00,cw,7.0475\n"))
        ->call('importScheduleCsv')
        ->assertForbidden();

    Livewire::actingAs($this->operator)
        ->test(W1awBulletinForm::class)
        ->call('exportScheduleCsv')
        ->assertForbidden();

    expect(BulletinScheduleEntry::count())->toBe(0);
});

test('managers open the upload modal from the schedule card', function () {
    Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->assertSee('Upload Schedule')
        ->assertSeeHtml('aria-label="Download schedule as CSV"')
        ->assertSet('showScheduleUpload', false)
        ->set('scheduleImportErrors', ['Row 2: stale error'])
        ->call('openScheduleUpload')
        ->assertSet('showScheduleUpload', true)
        ->assertSet('scheduleImportErrors', [])
        ->assertSeeHtml('accept=".csv,text/csv"');
});

test('a successful import closes the modal and a failed one keeps it open', function () {
    Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->call('openScheduleUpload')
        ->set('scheduleCsv', scheduleCsv("time_utc,mode,frequencies\n2027-06-26 21:00,sstv,7.0475\n"))
        ->call('importScheduleCsv')
        ->assertSet('showScheduleUpload', true)
        ->set('scheduleCsv', scheduleCsv("time_utc,mode,frequencies\n2027-06-26 21:00,cw,7.0475\n"))
        ->call('importScheduleCsv')
        ->assertSet('showScheduleUpload', false)
        ->assertSet('scheduleImportErrors', []);
});

test('times with a UTC offset are converted to UTC and unknown zones are rejected', function () {
    $csv = "time_utc,mode,frequencies\n"
        ."2027-06-26 17:00-04:00,cw,7.0475\n"
        ."2027-06-26 18:00 EST,cw,7.0475\n";

    $component = Livewire::actingAs($this->manager)
        ->test(W1awBulletinForm::class)
        ->set('scheduleCsv', scheduleCsv($csv))
        ->call('importScheduleCsv');

    expect($component->get('scheduleImportErrors'))->toHaveCount(1)
        ->and($component->get('scheduleImportErrors')[0])->toStartWith('Row 3:');

    $component->set('scheduleCsv', scheduleCsv("time_utc,mode,frequencies\n2027-06-26 17:00-04:00,cw,7.0475\n"))
        ->call('importScheduleCsv')
        ->assertSet('scheduleImportErrors', []);

    expect(BulletinScheduleEntry::sole()->scheduled_at->format('Y-m-d H:i'))->toBe('2027-06-26 21:00');
});
