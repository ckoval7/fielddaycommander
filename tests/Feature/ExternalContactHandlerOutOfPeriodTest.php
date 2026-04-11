<?php

use App\DTOs\ExternalContactDto;
use App\Exceptions\OutOfPeriodContactException;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Services\ExternalContactHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);

    Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    Mode::firstOrCreate(['name' => 'SSB'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->handler = app(ExternalContactHandler::class);
});

function makeConfig(array $eventOverrides = []): EventConfiguration
{
    $config = EventConfiguration::factory()
        ->for(Event::factory()->state($eventOverrides))
        ->create();

    Station::factory()->create(['event_configuration_id' => $config->id]);

    return $config;
}

function makeDto(string $callsign = 'W1AW', ?Carbon $timestamp = null, ?string $externalId = null): ExternalContactDto
{
    return new ExternalContactDto(
        callsign: $callsign,
        timestamp: $timestamp ?? now(),
        source: 'n1mm',
        bandName: '20m',
        modeName: 'SSB',
        sectionCode: 'CT',
        externalId: $externalId,
    );
}

test('contact within event window is accepted', function () {
    $config = makeConfig([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHour(),
    ]);

    $contact = $this->handler->handleContact(makeDto(timestamp: now()), $config);

    expect($contact)->toBeInstanceOf(Contact::class);
    expect(Contact::count())->toBe(1);
});

test('contact before event start throws OutOfPeriodContactException and creates no contact', function () {
    $config = makeConfig([
        'start_time' => now()->addHour(),
        'end_time' => now()->addHours(25),
    ]);

    expect(fn () => $this->handler->handleContact(makeDto(timestamp: now()->subMinute()), $config))
        ->toThrow(OutOfPeriodContactException::class);

    expect(Contact::count())->toBe(0);
});

test('contact after event end throws OutOfPeriodContactException and creates no contact', function () {
    $config = makeConfig([
        'start_time' => now()->subHours(25),
        'end_time' => now()->subHour(),
    ]);

    expect(fn () => $this->handler->handleContact(makeDto(timestamp: now()), $config))
        ->toThrow(OutOfPeriodContactException::class);

    expect(Contact::count())->toBe(0);
});

test('contact is accepted when event has no start_time', function () {
    $config = makeConfig([
        'start_time' => null,
        'end_time' => now()->addHour(),
    ]);

    $contact = $this->handler->handleContact(makeDto(timestamp: now()->subDays(10)), $config);

    expect($contact)->toBeInstanceOf(Contact::class);
});

test('contact is accepted when event has no end_time', function () {
    $config = makeConfig([
        'start_time' => now()->subHour(),
        'end_time' => null,
    ]);

    $contact = $this->handler->handleContact(makeDto(timestamp: now()), $config);

    expect($contact)->toBeInstanceOf(Contact::class);
});

test('contact is accepted when event has no window at all', function () {
    $config = makeConfig([
        'start_time' => null,
        'end_time' => null,
    ]);

    $contact = $this->handler->handleContact(makeDto(timestamp: now()), $config);

    expect($contact)->toBeInstanceOf(Contact::class);
});

test('replace with out-of-period time is silently rejected and existing contact is preserved', function () {
    $config = makeConfig([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHour(),
    ]);

    // Create an in-period contact
    $this->handler->handleContact(makeDto(callsign: 'W1AW', timestamp: now(), externalId: 'ext-id-001'), $config);
    $contact = Contact::where('external_id', 'ext-id-001')->firstOrFail();
    $originalCallsign = $contact->callsign;

    // Attempt replace with out-of-period time
    $replaceDto = new ExternalContactDto(
        callsign: 'K1XYZ',
        timestamp: now()->addDays(2),
        source: 'n1mm',
        bandName: '20m',
        modeName: 'SSB',
        sectionCode: 'CT',
        externalId: 'ext-id-001',
        isReplace: true,
    );
    $this->handler->handleReplace($replaceDto, $config);

    $contact->refresh();
    expect($contact->callsign)->toBe($originalCallsign);
});

test('replace with in-period time updates the contact', function () {
    $config = makeConfig([
        'start_time' => now()->subHour(),
        'end_time' => now()->addHour(),
    ]);

    $this->handler->handleContact(makeDto(callsign: 'W1AW', timestamp: now(), externalId: 'ext-id-002'), $config);

    $replaceDto = new ExternalContactDto(
        callsign: 'K1XYZ',
        timestamp: now()->addMinutes(10),
        source: 'n1mm',
        bandName: '20m',
        modeName: 'SSB',
        sectionCode: 'CT',
        externalId: 'ext-id-002',
        isReplace: true,
    );
    $this->handler->handleReplace($replaceDto, $config);

    $contact = Contact::where('external_id', 'ext-id-002')->firstOrFail();
    expect($contact->callsign)->toBe('K1XYZ');
});
