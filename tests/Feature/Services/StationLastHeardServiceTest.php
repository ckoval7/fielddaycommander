<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\StationUnavailableReason;
use App\Models\EventConfiguration;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\StationLastHeardService;

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));

    $this->config = EventConfiguration::factory()->publicService()->create();
    $this->net = Net::factory()->create(['event_configuration_id' => $this->config->id, 'overdue_after_minutes' => 30]);

    $this->logEntry = fn (string $from, ?string $to, int $minutesAgo, ?Net $net = null) => NetLogEntry::factory()->create([
        'event_configuration_id' => $this->config->id,
        'net_id' => ($net ?? $this->net)->id,
        'from_station' => $from,
        'to_station' => $to,
        'logged_at' => now()->subMinutes($minutesAgo),
    ]);

    $this->statusFor = fn (string $name, ?Net $net = null) => app(StationLastHeardService::class)
        ->forEvent($this->config, $net === null ? $this->net : $net)
        ->firstWhere('name', $name);
});

test('only stations with a tactical callsign or location are listed, in name order', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Aid Station 10', 'tactical_callsign' => 'Aid 10']);
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Finish', 'location' => 'Main St and 1st']);
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Aid Station 2', 'tactical_callsign' => 'Aid 2']);
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Spare Radio']);
    Station::factory()->create(['tactical_callsign' => 'Other Event']);

    $names = app(StationLastHeardService::class)->forEvent($this->config, $this->net)->pluck('name')->all();

    expect($names)->toBe(['Aid 2', 'Aid 10', 'Finish']);
});

test('a station is last heard from the latest entry to or from its tactical callsign, in any case', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7', 'location' => 'Mile 12']);
    ($this->logEntry)('aid 7', 'NCS', 25);
    ($this->logEntry)('NCS', 'AID 7', 10);
    ($this->logEntry)('Aid 8', 'NCS', 1);

    $status = ($this->statusFor)('Aid 7');

    expect($status['last_heard_at']->equalTo(now()->subMinutes(10)))->toBeTrue()
        ->and($status['location'])->toBe('Mile 12')
        ->and($status['overdue'])->toBeFalse();
});

test('traffic on another net at the event counts, deleted entries do not', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    $otherNet = Net::factory()->create(['event_configuration_id' => $this->config->id]);
    ($this->logEntry)('Aid 7', 'Logistics', 40, $otherNet);
    ($this->logEntry)('Aid 7', 'NCS', 5)->delete();

    expect(($this->statusFor)('Aid 7')['last_heard_at']->equalTo(now()->subMinutes(40)))->toBeTrue();
});

test('the operator on duty is shown and their FCC callsign counts as heard', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => User::factory()->create(['call_sign' => 'W1ABC'])->id,
        'end_time' => null,
        'is_transcription' => false,
    ]);
    ($this->logEntry)('W1ABC', 'NCS', 3);

    $status = ($this->statusFor)('Aid 7');

    expect($status['operator'])->toBe('W1ABC')
        ->and($status['last_heard_at']->equalTo(now()->subMinutes(3)))->toBeTrue();
});

test('a station without a tactical callsign answers to its name', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Finish', 'location' => 'Main St']);
    ($this->logEntry)('NCS', 'finish', 2);

    expect(($this->statusFor)('Finish')['last_heard_at'])->not->toBeNull();
});

test('a station quiet past the net threshold is overdue', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    ($this->logEntry)('Aid 7', 'NCS', 31);

    $status = ($this->statusFor)('Aid 7');

    expect($status['overdue'])->toBeTrue()
        ->and($status['overdue_at']->equalTo(now()->subMinute()))->toBeTrue();
});

test('a station never heard is measured from the net\'s first entry', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);

    expect(($this->statusFor)('Aid 7')['overdue_at'])->toBeNull();

    ($this->logEntry)('Aid 8', 'NCS', 20);
    expect(($this->statusFor)('Aid 7')['overdue'])->toBeFalse();

    $this->travel(11)->minutes();
    expect(($this->statusFor)('Aid 7')['overdue'])->toBeTrue()
        ->and(($this->statusFor)('Aid 7')['last_heard_at'])->toBeNull();
});

test('nothing is overdue without a threshold or a net, or for a station out of service', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    ($this->logEntry)('Aid 7', 'NCS', 90);

    $this->net->update(['overdue_after_minutes' => null]);
    expect(($this->statusFor)('Aid 7')['overdue'])->toBeFalse();

    expect(app(StationLastHeardService::class)->forEvent($this->config)->first()['overdue'])->toBeFalse();

    $this->net->update(['overdue_after_minutes' => 30]);
    $station->markUnavailable(StationUnavailableReason::cases()[0], null, User::factory()->create());

    $status = ($this->statusFor)('Aid 7');
    expect($status['unavailable'])->toBeTrue()
        ->and($status['overdue'])->toBeFalse();
});
