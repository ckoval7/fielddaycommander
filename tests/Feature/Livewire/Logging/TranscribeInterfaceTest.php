<?php

use App\Events\ContactLogged;
use App\Livewire\Logging\TranscribeInterface;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
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
        'exchange_class' => '1B',
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

// --- QSO Edit / Delete / Restore ---

function createTranscriptionContact(object $test, array $overrides = []): Contact
{
    $session = OperatingSession::firstOrCreate(
        [
            'station_id' => $test->station->id,
            'is_transcription' => true,
        ],
        [
            'operator_user_id' => $test->user->id,
            'start_time' => $test->event->start_time,
            'end_time' => $test->event->end_time,
            'is_transcription' => true,
            'power_watts' => 100,
            'qso_count' => 0,
        ]
    );

    $section = Section::where('code', 'CT')->first();

    $contact = Contact::factory()->create(array_merge([
        'event_configuration_id' => $test->event->eventConfiguration->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $test->user->id,
        'band_id' => $test->band->id,
        'mode_id' => $test->mode->id,
        'callsign' => 'W1TST',
        'exchange_class' => '3A',
        'section_id' => $section->id,
        'is_transcribed' => true,
        'qso_time' => now(),
    ], $overrides));

    $session->increment('qso_count');

    return $contact;
}

test('deleteContact soft-deletes transcribed contact and decrements qso_count', function () {
    $this->actingAs($this->user);

    $contact = createTranscriptionContact($this);
    $session = $contact->operatingSession;

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('deleteContact', $contact->id);

    expect($contact->fresh()->trashed())->toBeTrue()
        ->and($session->fresh()->qso_count)->toBe(0);
});

test('deleteContact logs audit entry', function () {
    $this->actingAs($this->user);

    $contact = createTranscriptionContact($this);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('deleteContact', $contact->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'contact.deleted',
        'auditable_type' => Contact::class,
        'auditable_id' => $contact->id,
        'user_id' => $this->user->id,
    ]);
});

test('deleteContact rejects contact from another station', function () {
    $this->actingAs($this->user);

    $otherEvent = Event::factory()->has(
        EventConfiguration::factory()->has(Station::factory(), 'stations'),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $otherStation = $otherEvent->eventConfiguration->stations->first();
    $otherSession = OperatingSession::create([
        'station_id' => $otherStation->id,
        'operator_user_id' => $this->user->id,
        'start_time' => $otherEvent->start_time,
        'end_time' => $otherEvent->end_time,
        'is_transcription' => true,
        'power_watts' => 100,
        'qso_count' => 1,
    ]);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $otherEvent->eventConfiguration->id,
        'operating_session_id' => $otherSession->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1OTH',
        'is_transcribed' => true,
        'qso_time' => now(),
    ]);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('deleteContact', $contact->id)
        ->assertForbidden();

    expect($contact->fresh()->trashed())->toBeFalse();
});

test('deleteContact rejects when event is archived', function () {
    $this->actingAs($this->user);

    $archivedEvent = Event::factory()->has(
        EventConfiguration::factory()->has(Station::factory(), 'stations'),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subDays(60),
        'end_time' => now()->subDays(59),
    ]);

    $archivedStation = $archivedEvent->eventConfiguration->stations->first();
    $session = OperatingSession::create([
        'station_id' => $archivedStation->id,
        'operator_user_id' => $this->user->id,
        'start_time' => $archivedEvent->start_time,
        'end_time' => $archivedEvent->end_time,
        'is_transcription' => true,
        'power_watts' => 100,
        'qso_count' => 1,
    ]);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $archivedEvent->eventConfiguration->id,
        'operating_session_id' => $session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1ARC',
        'is_transcribed' => true,
        'qso_time' => $archivedEvent->start_time,
    ]);

    Livewire::test(TranscribeInterface::class, ['station' => $archivedStation])
        ->call('deleteContact', $contact->id)
        ->assertForbidden();

    expect($contact->fresh()->trashed())->toBeFalse();
});

test('restoreContact restores soft-deleted contact and increments qso_count', function () {
    $this->actingAs($this->user);

    $contact = createTranscriptionContact($this, ['deleted_at' => now()]);
    $session = $contact->operatingSession;
    // Decrement since the contact is "deleted"
    $session->decrement('qso_count');

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('restoreContact', $contact->id);

    expect($contact->fresh()->trashed())->toBeFalse()
        ->and($session->fresh()->qso_count)->toBe(1);
});

test('restoreContact logs audit entry', function () {
    $this->actingAs($this->user);

    $contact = createTranscriptionContact($this, ['deleted_at' => now()]);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('restoreContact', $contact->id);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'contact.restored',
        'auditable_type' => Contact::class,
        'auditable_id' => $contact->id,
        'user_id' => $this->user->id,
    ]);
});

test('restoreContact rejects contact from another station', function () {
    $this->actingAs($this->user);

    $otherEvent = Event::factory()->has(
        EventConfiguration::factory()->has(Station::factory(), 'stations'),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $otherStation = $otherEvent->eventConfiguration->stations->first();
    $otherSession = OperatingSession::create([
        'station_id' => $otherStation->id,
        'operator_user_id' => $this->user->id,
        'start_time' => $otherEvent->start_time,
        'end_time' => $otherEvent->end_time,
        'is_transcription' => true,
        'power_watts' => 100,
        'qso_count' => 0,
    ]);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $otherEvent->eventConfiguration->id,
        'operating_session_id' => $otherSession->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1OTR',
        'is_transcribed' => true,
        'qso_time' => now(),
        'deleted_at' => now(),
    ]);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('restoreContact', $contact->id)
        ->assertForbidden();

    expect($contact->fresh()->trashed())->toBeTrue();
});

test('updateContact updates contact fields', function () {
    $this->actingAs($this->user);

    Section::firstOrCreate(
        ['code' => 'STX'],
        ['name' => 'South Texas', 'region' => 'W5', 'country' => 'US', 'is_active' => true],
    );

    $contact = createTranscriptionContact($this, [
        'callsign' => 'W1OLD',
        'exchange_class' => '3A',
    ]);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('updateContact', $contact->id, 'W1NEW 1D STX');

    $contact->refresh();
    $stxSection = Section::where('code', 'STX')->first();
    expect($contact->callsign)->toBe('W1NEW')
        ->and($contact->exchange_class)->toBe('1D')
        ->and($contact->section_id)->toBe($stxSection->id);
});

test('updateContact logs audit entry with old and new values', function () {
    $this->actingAs($this->user);

    $contact = createTranscriptionContact($this, [
        'callsign' => 'W1AUD',
        'exchange_class' => '3A',
    ]);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('updateContact', $contact->id, 'W1NEW 1B CT');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'contact.updated',
        'auditable_type' => Contact::class,
        'auditable_id' => $contact->id,
        'user_id' => $this->user->id,
    ]);
});

test('updateContact rejects invalid exchange', function () {
    $this->actingAs($this->user);

    $contact = createTranscriptionContact($this);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('updateContact', $contact->id, 'INVALID')
        ->assertSet('parseError', fn ($v) => $v !== '');

    expect($contact->fresh()->callsign)->toBe('W1TST');
});

test('updateContact rejects contact from another station', function () {
    $this->actingAs($this->user);

    $otherEvent = Event::factory()->has(
        EventConfiguration::factory()->has(Station::factory(), 'stations'),
        'eventConfiguration'
    )->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $otherStation = $otherEvent->eventConfiguration->stations->first();
    $otherSession = OperatingSession::create([
        'station_id' => $otherStation->id,
        'operator_user_id' => $this->user->id,
        'start_time' => $otherEvent->start_time,
        'end_time' => $otherEvent->end_time,
        'is_transcription' => true,
        'power_watts' => 100,
        'qso_count' => 1,
    ]);

    $contact = Contact::factory()->create([
        'event_configuration_id' => $otherEvent->eventConfiguration->id,
        'operating_session_id' => $otherSession->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1OTH',
        'exchange_class' => '3A',
        'is_transcribed' => true,
        'qso_time' => now(),
    ]);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('updateContact', $contact->id, 'W1NEW 1B CT')
        ->assertForbidden();

    expect($contact->fresh()->callsign)->toBe('W1OTH');
});

test('updateContact with inline time updates qso_time', function () {
    $this->actingAs($this->user);

    $originalTime = $this->event->start_time->copy()->addHours(2);

    $contact = createTranscriptionContact($this, [
        'callsign' => 'W1TST',
        'exchange_class' => '3A',
        'qso_time' => $originalTime,
    ]);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('updateContact', $contact->id, '1530 W1TST 3A CT');

    $contact->refresh();
    expect($contact->qso_time->format('H:i'))->toBe('15:30')
        ->and($contact->qso_time->format('Y-m-d'))->toBe($originalTime->format('Y-m-d'))
        ->and($contact->exchange_class)->toBe('3A');
});

test('updateContact without inline time preserves original qso_time', function () {
    $this->actingAs($this->user);

    $originalTime = $this->event->start_time->copy()->addHours(2);

    $contact = createTranscriptionContact($this, [
        'callsign' => 'W1OLD',
        'exchange_class' => '3A',
        'qso_time' => $originalTime,
    ]);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('updateContact', $contact->id, 'W1NEW 3A CT');

    $contact->refresh();
    expect($contact->qso_time->format('H:i'))->toBe($originalTime->format('H:i'))
        ->and($contact->callsign)->toBe('W1NEW');
});

test('updateContact with inline time stores exchange without time prefix', function () {
    $this->actingAs($this->user);

    $contact = createTranscriptionContact($this);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('updateContact', $contact->id, '1423 K1ABC 1B ME');

    $contact->refresh();
    expect($contact->exchange_class)->toBe('1B')
        ->and($contact->callsign)->toBe('K1ABC');
});

test('updateContact re-runs duplicate detection', function () {
    $this->actingAs($this->user);

    $contact1 = createTranscriptionContact($this, [
        'callsign' => 'W1DUP',
        'exchange_class' => '3A',
    ]);

    $contact2 = createTranscriptionContact($this, [
        'callsign' => 'K1ABC',
        'exchange_class' => '1B',
        'section_id' => Section::where('code', 'ME')->first()->id,
    ]);

    // Update contact2 to have the same callsign as contact1
    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->call('updateContact', $contact2->id, 'W1DUP 3A CT');

    $contact2->refresh();
    expect($contact2->is_duplicate)->toBeTrue()
        ->and($contact2->points)->toBe(0);
});

test('logContact dispatches ContactLogged event', function () {
    $this->actingAs($this->user);

    EventFacade::fake([ContactLogged::class]);

    Livewire::test(TranscribeInterface::class, ['station' => $this->station])
        ->set('selectedBandId', $this->band->id)
        ->set('selectedModeId', $this->mode->id)
        ->set('powerWatts', 100)
        ->set('exchangeInput', 'W5TEST 1A CT')
        ->call('logContact')
        ->assertSet('parseError', '');

    EventFacade::assertDispatched(ContactLogged::class, function (ContactLogged $event) {
        return $event->contact->callsign === 'W5TEST'
            && $event->event->id === $this->event->id;
    });
});
