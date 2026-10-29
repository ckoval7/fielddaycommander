<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\PowerSource;
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
use App\Services\SubmissionReportService;
use Database\Seeders\BonusTypeSeeder;
use Database\Seeders\EventTypeSeeder;
use Database\Seeders\OperatingClassSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    $this->seed(EventTypeSeeder::class);
    $this->seed(OperatingClassSeeder::class);
    $this->seed(BonusTypeSeeder::class);

    $this->band = Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.175, 'allowed_fd' => true, 'sort_order' => 4]);
    $this->cw = Mode::firstOrCreate(['name' => 'CW'], ['category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    $this->section = Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'country' => 'US', 'is_active' => true]);

    $fd = EventType::where('code', 'FD')->value('id');
    $this->event = Event::factory()->create([
        'event_type_id' => $fd,
        'rules_version' => '2025',
        'start_time' => now()->subHours(12),
        'end_time' => now()->addHours(12),
    ]);
    $this->config = EventConfiguration::factory()->create([
        'event_id' => $this->event->id,
        'section_id' => $this->section->id,
        'operating_class_id' => OperatingClass::where('code', 'A')->where('event_type_id', $fd)->value('id'),
        'transmitter_count' => 1,
        'max_power_watts' => 100,
        'uses_commercial_power' => false,
        'uses_generator' => true,
        'uses_battery' => false,
        'uses_solar' => false,
        'uses_wind' => false,
        'uses_water' => false,
        'uses_other_power' => null,
    ]);
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'max_power_watts' => 100,
        'power_source' => PowerSource::Generator,
    ]);
    $this->user = User::factory()->create();

    $this->logContact = function (): Contact {
        $session = OperatingSession::factory()->create([
            'station_id' => $this->station->id,
            'operator_user_id' => $this->user->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->cw->id,
            'power_watts' => 100,
        ]);

        return Contact::factory()->create([
            'event_configuration_id' => $this->config->id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $this->user->id,
            'band_id' => $this->band->id,
            'mode_id' => $this->cw->id,
            'section_id' => $this->section->id,
            'power_watts' => 100,
            'points' => 2,
        ]);
    };
});

test('a contact records its station power source when it is logged', function () {
    $contact = ($this->logContact)();

    $this->station->update(['power_source' => PowerSource::Solar]);

    expect($contact->fresh()->power_source)->toBe(PowerSource::Generator);
});

test('the emergency power bonus stays lost after a station switches back from commercial mains', function () {
    ($this->logContact)();
    expect($this->config->fresh()->calculateEmergencyPowerBonus())->toBe(100);

    $this->station->update(['power_source' => PowerSource::CommercialMains]);
    ($this->logContact)();
    $this->station->update(['power_source' => PowerSource::Generator]);

    expect($this->config->fresh()->calculateEmergencyPowerBonus())->toBe(0);
});

test('the submission sheet checks commercial mains and shows A-Commercial after a contact on mains', function () {
    $this->station->update(['power_source' => PowerSource::CommercialMains]);
    ($this->logContact)();
    $this->station->update(['power_source' => PowerSource::Generator]);

    $data = app(SubmissionReportService::class)->getData($this->config->fresh());

    expect($data['uses_commercial_power'])->toBeTrue()
        ->and($data['uses_generator'])->toBeTrue()
        ->and($data['entry_class'])->toBe('1A')
        ->and($data['entry_category'])->toBe('A-Commercial');
});

test('the submission sheet checks a station power source the event flags leave out', function () {
    $this->station->update(['power_source' => PowerSource::Solar]);
    ($this->logContact)();

    $data = app(SubmissionReportService::class)->getData($this->config->fresh());

    expect($data['uses_solar'])->toBeTrue()
        ->and($data['uses_commercial_power'])->toBeFalse()
        ->and($data['entry_category'])->toBeNull();
});

test('a saved emergency power row no longer shows on the sheet once the bonus is lost', function () {
    $bonusType = BonusType::where('code', 'emergency_power')
        ->where('event_type_id', $this->event->event_type_id)
        ->where('rules_version', '2025')
        ->firstOrFail();
    EventBonus::factory()->create([
        'event_configuration_id' => $this->config->id,
        'bonus_type_id' => $bonusType->id,
        'calculated_points' => 100,
        'is_verified' => true,
    ]);

    $this->station->update(['power_source' => PowerSource::CommercialMains]);
    ($this->logContact)();

    $config = $this->config->fresh();
    $data = app(SubmissionReportService::class)->getData($config);
    $emergency = collect($data['bonus_checklist'])->firstWhere('code', 'emergency_power');
    $claimedLines = collect($data['bonus_checklist'])
        ->where('claimed', true)
        ->reject(fn (array $item): bool => $item['code'] === '_gota')
        ->sum('points');

    expect($emergency['claimed'] ?? false)->toBeFalse()
        ->and($claimedLines)->toBe($data['bonus_score'])
        ->and($data['final_score'])->toBe($config->calculateFinalScore());
});

test('upgrading fills in contact power sources from their stations and removes stale emergency power rows', function () {
    $contact = ($this->logContact)();

    $bonusType = BonusType::where('code', 'emergency_power')->where('rules_version', '2025')->firstOrFail();
    EventBonus::factory()->create([
        'event_configuration_id' => $this->config->id,
        'bonus_type_id' => $bonusType->id,
        'calculated_points' => 100,
    ]);

    $migration = require database_path('migrations/2026_10_28_000002_add_power_source_to_contacts_table.php');
    $migration->down();
    $migration->up();

    expect(DB::table('contacts')->where('id', $contact->id)->value('power_source'))->toBe(PowerSource::Generator->value)
        ->and(EventBonus::where('bonus_type_id', $bonusType->id)->exists())->toBeFalse();
});

test('a contact made on a generator rules out the 5x multiplier after the station moves to battery', function () {
    $this->config->update(['uses_generator' => false, 'uses_battery' => true, 'max_power_watts' => 5]);
    $this->station->update(['power_source' => PowerSource::Generator, 'max_power_watts' => 5]);
    ($this->logContact)()->update(['power_watts' => 5]);
    $this->station->update(['power_source' => PowerSource::Battery]);

    $config = $this->config->fresh();

    expect($config->hasQrpNaturalPowerBonus())->toBeFalse()
        ->and($config->calculatePowerMultiplier())->toBe('2');
});
