<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

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

    $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'log-contacts']);
    $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web']);
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
            'received_exchange' => 'W1AW 3A CT',
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
        'received_exchange' => 'K5ABC 3A CT',
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
            'received_exchange' => 'W1AW 3A CT',
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
            'received_exchange' => 'N0CALL 1A CT',
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
            'received_exchange' => '',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertUnprocessable();
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
            'received_exchange' => 'W1AW 3A CT',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertForbidden();
});

test('cannot sync contacts to an ended session', function () {
    $this->session->update(['end_time' => now()]);

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', [
            'uuid' => fake()->uuid(),
            'operating_session_id' => $this->session->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->mode->id,
            'callsign' => 'W1AW',
            'section_id' => $this->section->id,
            'received_exchange' => 'W1AW 3A CT',
            'power_watts' => 100,
            'qso_time' => now()->toISOString(),
        ])
        ->assertUnprocessable();
});
