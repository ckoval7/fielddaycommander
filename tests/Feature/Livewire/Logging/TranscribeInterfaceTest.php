<?php

use App\Livewire\Logging\TranscribeInterface;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo('log-contacts');
    $this->user->assignRole($role);

    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);
    $this->mode = Mode::first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);

    Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );
    Section::firstOrCreate(
        ['code' => 'ME'],
        ['name' => 'Maine', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->event = Event::factory()->has(
        EventConfiguration::factory()->has(
            Station::factory(),
            'stations'
        ),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->station = $this->event->eventConfiguration->stations->first();
});

test('initializes working date and contact time from event start', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->assertSet('workingDate', $this->event->start_time->format('Y-m-d'))
        ->assertSet('contactTime', $this->event->start_time->format('H:i'));
});

test('rejects contact time outside event bounds with buffer', function () {
    $this->actingAs($this->user);

    $tooEarly = $this->event->start_time->copy()->subMinutes(10);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('workingDate', $tooEarly->format('Y-m-d'))
        ->set('contactTime', $tooEarly->format('H:i'))
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact')
        ->assertSet('parseError', 'Contact time must be within the event window (±5 minutes).');
});

test('allows contact time within 5 minute buffer before start', function () {
    $this->actingAs($this->user);

    $justBeforeStart = $this->event->start_time->copy()->subMinutes(4);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('workingDate', $justBeforeStart->format('Y-m-d'))
        ->set('contactTime', $justBeforeStart->format('H:i'))
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact')
        ->assertSet('parseError', '');
});

test('inline time in exchange sets contact time', function () {
    $this->actingAs($this->user);

    $eventStart = $this->event->start_time;
    $inlineTime = $eventStart->copy()->addHour();

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('workingDate', $eventStart->format('Y-m-d'))
        ->set('exchangeInput', $inlineTime->format('Hi').' W5XYZ 1B CT')
        ->call('logContact')
        ->assertSet('parseError', '');

    $contact = Contact::where('callsign', 'W5XYZ')->first();
    expect($contact->qso_time->format('H:i'))->toBe($inlineTime->format('H:i'));
});

test('exchange without inline time uses last contact time', function () {
    $this->actingAs($this->user);

    $eventStart = $this->event->start_time;
    $contactTimeStr = $eventStart->copy()->addMinutes(30)->format('H:i');

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('workingDate', $eventStart->format('Y-m-d'))
        ->set('contactTime', $contactTimeStr)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact')
        ->assertSet('parseError', '');

    $contact = Contact::where('callsign', 'W5XYZ')->first();
    expect($contact->qso_time->format('H:i'))->toBe($contactTimeStr);
});

test('inline time persists for subsequent contacts without time', function () {
    $this->actingAs($this->user);

    $eventStart = $this->event->start_time;
    $inlineTime = $eventStart->copy()->addHour();

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('workingDate', $eventStart->format('Y-m-d'))
        ->set('exchangeInput', $inlineTime->format('Hi').' W5XYZ 1B CT')
        ->call('logContact')
        ->set('exchangeInput', 'K1ABC 1B ME')
        ->call('logContact');

    $second = Contact::where('callsign', 'K1ABC')->first();
    expect($second->qso_time->format('H:i'))->toBe($inlineTime->format('H:i'));
});

test('accepts flexible inline time formats', function (string $timeInput, string $expected) {
    $this->actingAs($this->user);

    // Full-day event so all time values are within the window
    $today = now()->startOfDay();
    $fullDayEvent = Event::factory()->has(
        EventConfiguration::factory()->has(Station::factory(), 'stations'),
        'eventConfiguration'
    )->create([
        'start_time' => $today,
        'end_time' => $today->copy()->addHours(24),
    ]);
    $station = $fullDayEvent->eventConfiguration->stations->first();
    $expectedTime = Carbon::parse($today->format('Y-m-d').' '.$expected, 'UTC');

    Livewire::test(TranscribeInterface::class, ['station' => $station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('workingDate', $today->format('Y-m-d'))
        ->set('exchangeInput', $timeInput.' W5XYZ 1B CT')
        ->call('logContact')
        ->assertSet('parseError', '');

    $this->assertDatabaseHas('contacts', [
        'callsign' => 'W5XYZ',
        'qso_time' => $expectedTime->format('Y-m-d H:i:s'),
    ]);
})->with([
    '4-digit' => ['1423', '14:23'],
    'colon-separated' => ['14:23', '14:23'],
    '3-digit' => ['123', '01:23'],
    'midnight' => ['0023', '00:23'],
    '12-hour pm' => ['2:23pm', '14:23'],
    'shorthand pm' => ['223p', '14:23'],
    'Z suffix' => ['1423z', '14:23'],
    'hour only' => ['14', '14:00'],
    'hour with pm' => ['2pm', '14:00'],
]);

test('rejects invalid time format', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('contactTime', 'abc')
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact')
        ->assertSet('parseError', 'Invalid time format. Try 1423, 14:23, or 2:23pm.');
});

test('converts local time to UTC when timeIsLocal is true', function () {
    $this->actingAs($this->user);

    // Set user timezone to US/Eastern (UTC-4 in summer, UTC-5 in winter)
    $this->user->update(['preferred_timezone' => 'America/New_York']);

    $validDate = $this->event->start_time->format('Y-m-d');
    $localTime = '14:23';
    $expectedUtc = Carbon::parse($validDate.' '.$localTime, 'America/New_York')->utc();

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('workingDate', $validDate)
        ->set('timeIsLocal', true)
        ->set('exchangeInput', '1423 K1XYZ 1B CT')
        ->call('logContact')
        ->assertSet('parseError', '');

    $contact = Contact::where('callsign', 'K1XYZ')->first();
    expect($contact->qso_time->format('Y-m-d H:i'))->toBe($expectedUtc->format('Y-m-d H:i'));
});

test('stores clean exchange without time prefix', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('exchangeInput', '1423 W5XYZ 1B CT')
        ->call('logContact');

    $this->assertDatabaseHas('contacts', [
        'callsign' => 'W5XYZ',
        'received_exchange' => 'W5XYZ 1B CT',
    ]);
});

test('saves contact with is_transcribed true', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact');

    $this->assertDatabaseHas('contacts', [
        'callsign' => 'W5XYZ',
        'is_transcribed' => true,
    ]);
});

test('creates synthetic operating session on first save', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact');

    $this->assertDatabaseHas('operating_sessions', [
        'station_id' => $this->station->id,
        'is_transcription' => true,
    ]);
});

test('reuses existing transcription session on subsequent saves', function () {
    $this->actingAs($this->user);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('exchangeInput', 'W5XYZ 1B CT')
        ->call('logContact')
        ->set('exchangeInput', 'K1ABC 1B ME')
        ->call('logContact');

    $this->assertDatabaseCount('operating_sessions', 1);
    $this->assertDatabaseCount('contacts', 2);
});

test('archived event shows read-only message', function () {
    $this->actingAs($this->user);

    $archivedEvent = Event::factory()->has(
        EventConfiguration::factory()->has(Station::factory(), 'stations'),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);

    $archivedStation = $archivedEvent->eventConfiguration->stations->first();

    Livewire::test(TranscribeInterface::class, ['station' => $archivedStation])
        ->assertSee('This event is archived');
});
