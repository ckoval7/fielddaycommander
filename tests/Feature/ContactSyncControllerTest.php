<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Livewire\Logging\StationSelect;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\DeveloperClockService;
use Carbon\CarbonInterface;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\OperatingClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->seed(EventTypeSeeder::class);
    $this->seed(OperatingClassSeeder::class);

    $this->band = Band::first() ?? Band::create([
        'name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.175,
        'allowed_fd' => true, 'sort_order' => 4,
    ]);

    $this->mode = Mode::where('name', 'Phone')->first() ?? Mode::create([
        'name' => 'Phone', 'category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1,
    ]);

    $this->section = Section::firstOrCreate(
        ['code' => 'CT'],
        ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true],
    );

    $this->event = Event::factory()->create([
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
    ]);

    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
    ]);

    $this->user = User::factory()->create();

    $permission = Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $this->user->assignRole($role);

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);
});

/**
 * A live operating session on a Winter Field Day event, for asserting that
 * each rulebook accepts only its own class letters.
 */
function wfdSession(User $user, Band $band, Mode $mode): OperatingSession
{
    $wfdType = EventType::where('code', 'WFD')->firstOrFail();

    $event = Event::factory()->create([
        'event_type_id' => $wfdType->id,
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);

    $config = EventConfiguration::factory()->create(['event_id' => $event->id]);
    $station = Station::factory()->create(['event_configuration_id' => $config->id]);

    return OperatingSession::factory()->active()->create([
        'station_id' => $station->id,
        'operator_user_id' => $user->id,
        'band_id' => $band->id,
        'mode_id' => $mode->id,
        'power_watts' => 100,
    ]);
}

test('unauthenticated users cannot sync contacts', function () {
    $this->postJson('/logging/contacts', [])
        ->assertUnauthorized();
});

test('syncing a contact creates it in the database', function () {
    $uuid = fake()->uuid();

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => $uuid,
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertCreated()
        ->assertJsonStructure(['uuid', 'contact_id', 'points', 'is_duplicate']);

    $this->assertDatabaseHas('contacts', [
        'uuid' => $uuid,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
    ]);
});

test('duplicate uuid returns success without creating a second contact', function () {
    $uuid = fake()->uuid();
    $payload = [
        'uuid' => $uuid,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'K5ABC',
        'section_id' => $this->section->id,
        'exchange_class' => '3A',
        'power_watts' => 100,
        'qso_time' => now()->toISOString(),
    ];

    $this->actingAs($this->user)->postJson('/logging/contacts', $payload)->assertCreated();
    $this->actingAs($this->user)->postJson('/logging/contacts', $payload)->assertOk();

    expect(Contact::where('uuid', $uuid)->count())->toBe(1);
});

test('syncing a duplicate callsign marks it as duplicate', function () {
    // Create an existing non-duplicate contact for same callsign/band/mode/event
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'is_duplicate' => false,
        'points' => 1,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertCreated();

    expect($response->json('is_duplicate'))->toBeTrue();
    expect($response->json('points'))->toBe(0);
});

test('syncing a contact increments session qso_count', function () {
    $initialCount = $this->session->qso_count;

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'N0CALL',
            'section_id' => $this->section->id,
            'exchange_class' => '1A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertCreated();

    expect($this->session->fresh()->qso_count)->toBe($initialCount + 1);
});

test('syncing a contact with invalid data returns validation error', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => '',
            'section_id' => $this->section->id,
            'exchange_class' => '',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertUnprocessable();
});

test('validation errors use friendly field names', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => null,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['band_id' => 'The band field is required.']);
});

test('cannot sync contacts to another users session', function () {
    $otherUser = User::factory()->create();
    $otherUser->assignRole('Operator');

    $this->actingAs($otherUser)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertForbidden();
});

test('cannot sync contacts after event has ended', function () {
    $this->event->update(['end_time' => now()->subHours(1)]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'This event has ended. Use Transcribe Paper Log to enter contacts from paper logs.']);
});

test('cannot sync contacts before event has started', function () {
    // Event starts in 10 minutes (within setup window but not yet started)
    $this->event->update(['start_time' => now()->addMinutes(10)]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJson(['message' => 'This event has not started yet. Contacts can only be logged once the event begins.']);
});

test('syncing a gota contact scores it by mode', function () {
    $gotaStation = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'is_gota' => true,
    ]);

    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $gotaSession->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
            'gota_operator_first_name' => 'John',
            'gota_operator_last_name' => 'Doe',
        ])
        ->assertCreated();

    expect($response->json('points'))->toBe((int) $this->mode->points_fd);
    expect($response->json('is_duplicate'))->toBeFalse();
});

test('syncing a duplicate gota contact assigns 0 points', function () {
    $gotaStation = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'is_gota' => true,
    ]);

    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);

    // Create existing GOTA contact for same callsign/band/mode
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $gotaSession->id,
        'logger_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'W1AW',
        'section_id' => $this->section->id,
        'is_duplicate' => false,
        'is_gota_contact' => true,
        'points' => 5,
    ]);

    $response = $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $gotaSession->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
            'gota_operator_first_name' => 'Jane',
            'gota_operator_last_name' => 'Doe',
        ])
        ->assertCreated();

    expect($response->json('points'))->toBe(0);
    expect($response->json('is_duplicate'))->toBeTrue();
});

/**
 * Sync payload for a contact on the shared session, made at the given time.
 *
 * @return array<string, mixed>
 */
function syncPayload(object $test, CarbonInterface $qsoTime): array
{
    return [
        'uuid' => fake()->uuid(),
        'operating_session_id' => $test->session->id,
        'band_id' => $test->band->id,
        'mode_id' => $test->mode->id,
        'callsign' => 'W1AW',
        'section_id' => $test->section->id,
        'exchange_class' => '3A',
        'power_watts' => 100,
        'qso_time' => $qsoTime->toISOString(),
    ];
}

test('cannot sync contacts timed after their session ended', function () {
    $this->session->update(['start_time' => now()->subHours(2), 'end_time' => now()->subMinutes(10)]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, now()->subMinutes(5)))
        ->assertUnprocessable()
        ->assertJson(['message' => 'This operating session has ended.']);

    expect(Contact::count())->toBe(0);
});

test('cannot sync contacts timed before their ended session started', function () {
    $this->session->update(['start_time' => now()->subHours(2), 'end_time' => now()->subMinutes(10)]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, now()->subHours(3)))
        ->assertUnprocessable()
        ->assertJson(['message' => 'This operating session has ended.']);
});

test('queued contacts made during a session sync after the station is taken over', function () {
    $this->session->update(['start_time' => now()->subHours(2)]);

    // A second operator takes over the station while the first laptop is offline.
    $otherOperator = User::factory()->create();
    $otherOperator->assignRole('Operator');
    $this->actingAs($otherOperator);
    Livewire::test(StationSelect::class)
        ->set('takeoverStationId', $this->station->id)
        ->call('confirmTakeover');

    expect($this->session->fresh()->end_time)->not->toBeNull();

    $payload = syncPayload($this, now()->subHour());

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', $payload)
        ->assertCreated()
        ->assertJson(['is_duplicate' => false]);

    $contact = Contact::where('uuid', $payload['uuid'])->firstOrFail();
    expect($contact->operating_session_id)->toBe($this->session->id)
        ->and($contact->points)->toBeGreaterThan(0)
        ->and($this->session->fresh()->qso_count)->toBe(1);

    // A second queued contact with the same callsign still gets the dupe check.
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, now()->subMinutes(50)))
        ->assertCreated()
        ->assertJson(['is_duplicate' => true, 'points' => 0]);
});

test('contacts made before the event ended sync after it ends', function () {
    $this->session->update(['start_time' => now()->subHours(2)]);
    $this->event->update(['end_time' => now()->subMinutes(2)]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, now()->subMinutes(4)))
        ->assertCreated();
});

test('syncing rejects a class the events rulebook does not define', function (string $exchangeClass) {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => $exchangeClass,
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('exchange_class');
})->with(['1H', '2I', '3O', '10M']);

test('syncing a contact accepts Winter Field Day exchange classes', function (string $exchangeClass) {
    $uuid = fake()->uuid();
    $session = wfdSession($this->user, $this->band, $this->mode);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => $uuid,
            'operating_session_id' => $session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => $exchangeClass,
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertCreated();

    $this->assertDatabaseHas('contacts', [
        'uuid' => $uuid,
        'exchange_class' => $exchangeClass,
    ]);
})->with(['1H', '2I', '3O', '10M']);

test('syncing a contact rejects an unknown exchange class letter', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3Z',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('exchange_class');
});

test('syncing a contact stores an optional uppercase submode', function () {
    $uuid = fake()->uuid();

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => $uuid,
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'submode' => 'ssb',
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertCreated();

    expect(Contact::where('uuid', $uuid)->value('submode'))->toBe('SSB');
});

test('syncing a contact without a submode stores null', function () {
    $uuid = fake()->uuid();

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => $uuid,
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertCreated();

    expect(Contact::where('uuid', $uuid)->value('submode'))->toBeNull();
});

test('syncing a contact rejects an overlong submode', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'submode' => str_repeat('X', 21),
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('submode');
});

test('syncing a gota contact without a gota operator is rejected', function (array $operator) {
    $gotaStation = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'is_gota' => true,
    ]);
    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $gotaSession->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
            ...$operator,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['gota_operator_first_name' => 'Enter the GOTA operator']);

    expect(Contact::count())->toBe(0);
})->with([
    'no operator' => [[]],
    'first name only' => [['gota_operator_first_name' => 'Jane']],
    'callsign only' => [['gota_operator_callsign' => 'KD2ABC']],
]);

test('syncing a gota contact with a linked gota operator is accepted', function () {
    $gotaStation = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'is_gota' => true,
    ]);
    $gotaSession = OperatingSession::factory()->active()->create([
        'station_id' => $gotaStation->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);
    $gotaOperator = User::factory()->create();

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $gotaSession->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'exchange_class' => '3A',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
            'gota_operator_user_id' => $gotaOperator->id,
        ])
        ->assertCreated();

    expect(Contact::firstOrFail()->gota_operator_user_id)->toBe($gotaOperator->id);
});

test('sync responses carry the server application clock for the queue to measure its offset', function () {
    Date::setTestNow(now()->startOfSecond());

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, now()))
        ->assertCreated()
        ->assertHeader('X-Server-Time', (string) now()->getTimestampMs());
});

test('a future qso time is rejected with the server time so the queue can re-stamp it', function () {
    Date::setTestNow(now()->startOfSecond());

    // A device clock well ahead, near the end of the event: told it is in the
    // future (correctable), not that the event has ended.
    $this->event->update(['end_time' => now()->addMinutes(30)]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, now()->addHour()))
        ->assertUnprocessable()
        ->assertJson([
            'code' => 'qso_time_in_future',
            'server_time' => now()->getTimestampMs(),
        ])
        ->assertHeader('X-Server-Time', (string) now()->getTimestampMs());
});

test('with the developer fake clock set, contacts stamped on the server clock are accepted', function () {
    config(['developer.enabled' => true]);
    $fakeNow = now()->subDays(3)->startOfSecond();
    app(DeveloperClockService::class)->setFakeTime($fakeNow);

    $this->event->update(['start_time' => $fakeNow->copy()->subHours(2), 'end_time' => $fakeNow->copy()->addHours(20)]);
    $this->session->update(['start_time' => $fakeNow->copy()->subHour()]);

    // The queue stamps qso_time on the server clock it learned from X-Server-Time.
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, $fakeNow->copy()->addSeconds(30)))
        ->assertCreated()
        ->assertHeader('X-Server-Time', (string) $fakeNow->getTimestampMs());

    // A raw device timestamp (real time, days after the fake clock) is a future time.
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, now()))
        ->assertUnprocessable()
        ->assertJson(['code' => 'qso_time_in_future', 'server_time' => $fakeNow->getTimestampMs()]);
});

/**
 * Put the session on 20m so dial frequencies can be checked against a known range.
 *
 * @return array<string, mixed>
 */
function twentyMeterPayload(object $test, array $advanced): array
{
    $band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'allowed_fd' => true, 'sort_order' => 6]);
    $test->session->update(['band_id' => $band->id]);

    return [
        ...syncPayload($test, now()),
        'band_id' => $band->id,
        ...$advanced,
    ];
}

test('syncing a contact stores its advanced fields', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', twentyMeterPayload($this, [
            'frequency_hz' => 14_074_000,
            'rst_sent' => '599',
            'rst_received' => ' 579 ',
            'grid_square' => 'fn31PR',
            'contact_name' => ' Bob ',
            'notes' => 'Big signal',
        ]))
        ->assertCreated();

    expect(Contact::sole())
        ->frequency_hz->toBe(14_074_000)
        ->rst_sent->toBe('599')
        ->rst_received->toBe('579')
        ->grid_square->toBe('FN31pr')
        ->contact_name->toBe('Bob')
        ->notes->toBe('Big signal');
});

test('syncing a contact stores blank advanced fields as null', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', twentyMeterPayload($this, [
            'frequency_hz' => null,
            'rst_sent' => '',
            'grid_square' => '  ',
        ]))
        ->assertCreated();

    expect(Contact::sole())
        ->frequency_hz->toBeNull()
        ->rst_sent->toBeNull()
        ->grid_square->toBeNull();
});

test('syncing a contact accepts digital-mode dB reports', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', twentyMeterPayload($this, ['rst_sent' => '-10', 'rst_received' => '+05']))
        ->assertCreated();

    expect(Contact::sole()->rst_sent)->toBe('-10');
});

test('syncing a contact rejects malformed advanced fields', function (array $advanced, string $field) {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', twentyMeterPayload($this, $advanced))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Contact::count())->toBe(0);
})->with([
    'RST out of range' => [['rst_sent' => '699'], 'rst_sent'],
    'RST with letters' => [['rst_received' => '5NN'], 'rst_received'],
    'grid too short' => [['grid_square' => 'FN3'], 'grid_square'],
    'grid out of range' => [['grid_square' => 'ZZ31'], 'grid_square'],
    'name too long' => [['contact_name' => str_repeat('A', 51)], 'contact_name'],
]);

test('syncing a contact rejects a dial frequency on another band', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', twentyMeterPayload($this, ['frequency_hz' => 7_074_000]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['frequency_hz' => '7.074 MHz is not on 20m.']);
});

test('cannot sync a contact made after a Field Day station\'s 24-hour operating period', function () {
    $this->event->update(['setup_started_early' => true]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'qso_time' => now()->subHours(25),
    ]);
    $this->event->update(['start_time' => now()->subHours(26)]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, now()))
        ->assertUnprocessable()
        ->assertJson(['message' => 'Your 24-hour operating period has ended. Use Transcribe Paper Log to enter contacts from paper logs.']);

    $this->event->update(['setup_started_early' => false]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', syncPayload($this, now()))
        ->assertSuccessful();
});

test('station select explains that the 24-hour operating period is over', function () {
    $this->event->update(['setup_started_early' => true, 'start_time' => now()->subHours(26)]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'qso_time' => now()->subHours(25),
    ]);

    $otherOperator = User::factory()->create();
    $otherOperator->assignRole('Operator');
    $this->actingAs($otherOperator);

    Livewire::test(StationSelect::class)
        ->assertSee('Operating Period Over')
        ->assertDontSee($this->station->name);
});
