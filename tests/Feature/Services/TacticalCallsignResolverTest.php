<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\EventConfiguration;
use App\Models\OperatingSession;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRole;
use App\Models\Station;
use App\Models\User;
use App\Services\TacticalCallsignResolver;

beforeEach(function () {
    $this->config = EventConfiguration::factory()->publicService()->create();
    $this->resolver = app(TacticalCallsignResolver::class);
});

test('a station tactical name resolves to the operator in its live session', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => User::factory()->create(['call_sign' => 'W1ABC'])->id,
        'end_time' => null,
        'is_transcription' => false,
    ]);

    expect($this->resolver->resolve($this->config, 'aid 7'))->toBe('W1ABC');
});

test('a closed or transcription session does not count as on duty', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 7']);
    OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => User::factory()->create(['call_sign' => 'W1OLD'])->id,
        'end_time' => now()->subHour(),
    ]);
    OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => User::factory()->create(['call_sign' => 'W1TYP'])->id,
        'end_time' => null,
        'is_transcription' => true,
    ]);

    expect($this->resolver->resolve($this->config, 'Aid 7'))->toBeNull();
});

test('a role tactical name resolves to whoever is checked in to it', function () {
    $role = ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Net Control Operator', 'tactical_callsign' => 'Net Control']);
    $shift = Shift::factory()->create(['event_configuration_id' => $this->config->id, 'shift_role_id' => $role->id]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => User::factory()->create(['call_sign' => 'K1OUT'])->id,
        'status' => ShiftAssignment::STATUS_CHECKED_OUT,
        'checked_in_at' => now()->subHours(3),
    ]);
    ShiftAssignment::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => User::factory()->create(['call_sign' => 'K1NCS'])->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
        'checked_in_at' => now()->subHour(),
    ]);

    expect($this->resolver->resolve($this->config, 'Net Control'))->toBe('K1NCS');
});

test('a station or role name works when no tactical callsign is set', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Finish Line', 'tactical_callsign' => null]);
    OperatingSession::factory()->create([
        'station_id' => $station->id,
        'operator_user_id' => User::factory()->create(['call_sign' => 'N1FIN'])->id,
        'end_time' => null,
        'is_transcription' => false,
    ]);

    expect($this->resolver->resolve($this->config, 'finish line'))->toBe('N1FIN');
});

test('unknown names and other events resolve to nothing', function () {
    $other = EventConfiguration::factory()->publicService()->create();
    Station::factory()->create(['event_configuration_id' => $other->id, 'tactical_callsign' => 'Aid 7']);

    expect($this->resolver->resolve($this->config, 'Aid 7'))->toBeNull()
        ->and($this->resolver->resolve($this->config, 'W1XYZ'))->toBeNull()
        ->and($this->resolver->resolve($this->config, ''))->toBeNull();
});

test('tactical names list stations and roles once each, in natural order', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 10']);
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 2']);
    ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Net Control']);
    ShiftRole::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'net control']);

    expect($this->resolver->tacticalNames($this->config)->all())->toBe(['Aid 2', 'Aid 10', 'Net Control']);
});
