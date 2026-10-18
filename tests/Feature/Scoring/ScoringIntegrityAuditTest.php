<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Band;
use App\Models\BonusType;
use App\Models\Contact;
use App\Models\Event;
use App\Models\EventBonus;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\OperatingClass;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\CabrilloExporter;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\OperatingClassSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    $this->seed(EventTypeSeeder::class);
    $this->seed(OperatingClassSeeder::class);

    $this->band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.175, 'allowed_fd' => true, 'sort_order' => 4]);
    $this->otherBand = Band::firstOrCreate(['name' => '40m'], ['meters' => 40, 'frequency_mhz' => 7.1, 'allowed_fd' => true, 'sort_order' => 3]);
    $this->mode = Mode::firstOrCreate(['name' => 'Phone'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    $this->section = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true]);

    $this->event = Event::factory()->create([
        'event_type_id' => EventType::where('code', 'FD')->value('id'),
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => OperatingClass::where('code', 'A')->where('event_type_id', $this->event->event_type_id)->value('id'),
        'max_power_watts' => 100,
    ]);
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'max_power_watts' => 100]);

    $this->user = User::factory()->create();
    Permission::findOrCreate('log-contacts', 'web');
    Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'web'])->givePermissionTo('log-contacts');
    $this->user->assignRole('Operator');

    $this->session = OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'power_watts' => 100,
    ]);
});

function auditSyncPayload(object $test, array $overrides = []): array
{
    return array_merge([
        'uuid' => fake()->uuid(),
        'operating_session_id' => $test->session->id,
        'band_id' => $test->band->id,
        'mode_id' => $test->mode->id,
        'callsign' => 'W1AW',
        'section_id' => $test->section->id,
        'exchange_class' => '3A',
        'power_watts' => 100,
        'qso_time' => now()->toISOString(),
    ], $overrides);
}

test('sync rejects a qso dated before the event start even after the event began', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', auditSyncPayload($this, ['qso_time' => $this->event->start_time->copy()->subHour()->toISOString()]))
        ->assertUnprocessable();

    expect(Contact::count())->toBe(0);
});

test('sync rejects a qso dated in the future beyond clock skew', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', auditSyncPayload($this, ['qso_time' => now()->addHour()->toISOString()]))
        ->assertUnprocessable();

    $this->actingAs($this->user)
        ->postJson('/logging/contacts', auditSyncPayload($this, ['qso_time' => now()->addMinutes(2)->toISOString()]))
        ->assertCreated();
});

test('sync rejects a band or mode that does not match the session', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', auditSyncPayload($this, ['band_id' => $this->otherBand->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('band_id');

    expect(Contact::count())->toBe(0);
});

test('sync bounds power_watts', function () {
    $this->actingAs($this->user)
        ->postJson('/logging/contacts', auditSyncPayload($this, ['power_watts' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('power_watts');
});

test('sync stores the contact and the session tally together', function () {
    $this->actingAs($this->user)->postJson('/logging/contacts', auditSyncPayload($this))->assertCreated();

    expect(Contact::count())->toBe(1)
        ->and($this->session->fresh()->qso_count)->toBe(1);
});

test('cabrillo export skips contacts without band or mode instead of crashing', function () {
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'band_id' => null,
        'mode_id' => null,
        'callsign' => 'K9NUL',
        'qso_time' => now()->subHour(),
        'is_duplicate' => false,
    ]);
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'section_id' => $this->section->id,
        'callsign' => 'K1OK',
        'qso_time' => now()->subHour(),
        'is_duplicate' => false,
    ]);

    $output = app(CabrilloExporter::class)->export($this->config);

    expect($output)->toContain('K1OK')->not->toContain('K9NUL');
});

test('cabrillo export leaves out contacts outside the event period', function () {
    Contact::factory()->create([
        'event_configuration_id' => $this->config->id,
        'operating_session_id' => $this->session->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'callsign' => 'K1EARLY',
        'qso_time' => $this->event->start_time->copy()->subMinutes(3),
        'is_duplicate' => false,
    ]);

    expect(app(CabrilloExporter::class)->export($this->config))->not->toContain('K1EARLY');
});

test('cabrillo category power uses the effective power used for scoring', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'max_power_watts' => 500]);

    expect(app(CabrilloExporter::class)->export($this->config))->toContain('CATEGORY-POWER: HIGH');
});

test('event bonuses allow one row per configuration and bonus type', function () {
    $bonusType = BonusType::create([
        'event_type_id' => $this->event->event_type_id,
        'code' => 'audit_test',
        'name' => 'Audit test',
        'base_points' => 100,
    ]);
    $row = ['event_configuration_id' => $this->config->id, 'bonus_type_id' => $bonusType->id, 'quantity' => 1, 'calculated_points' => 100];

    EventBonus::create($row);
    EventBonus::create($row);
})->throws(UniqueConstraintViolationException::class);
