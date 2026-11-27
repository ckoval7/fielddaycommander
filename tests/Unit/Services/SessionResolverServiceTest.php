<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Band;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use App\Services\SessionResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    $this->config = EventConfiguration::factory()->create();
    $this->station = Station::factory()->create(['event_configuration_id' => $this->config->id]);
    $this->user = User::factory()->create();
    $this->band = Band::create(['name' => '20m', 'meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    $this->mode = Mode::create(['name' => 'CW', 'category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);

    $this->resolver = new SessionResolverService;
});

test('creates a new session when none exists', function () {
    $session = $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: $this->user->id,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
    );

    expect($session)->toBeInstanceOf(OperatingSession::class)
        ->and($session->station_id)->toBe($this->station->id)
        ->and($session->operator_user_id)->toBe($this->user->id)
        ->and($session->band_id)->toBe($this->band->id)
        ->and($session->mode_id)->toBe($this->mode->id);
});

test('reuses existing session for same station operator band mode', function () {
    $existing = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_transcription' => false,
        'end_time' => null,
    ]);

    $session = $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: $this->user->id,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
    );

    expect($session->id)->toBe($existing->id);
});

test('creates new session when band differs', function () {
    $otherBand = Band::create(['name' => '40m', 'meters' => 40, 'frequency_mhz' => 7.0, 'sort_order' => 3]);

    OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_transcription' => false,
        'end_time' => null,
    ]);

    $session = $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: $this->user->id,
        bandId: $otherBand->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
    );

    expect($session->band_id)->toBe($otherBand->id)
        ->and(OperatingSession::count())->toBe(2);
});

test('sets external_source when provided', function () {
    $session = $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: $this->user->id,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
        externalSource: 'n1mm',
    );

    expect($session->external_source)->toBe('n1mm');
});

test('updates last_activity_at when updating activity', function () {
    $session = $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: $this->user->id,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
    );

    $this->resolver->touchActivity($session);

    $session->refresh();
    expect($session->last_activity_at)->not->toBeNull();
});

test('handles null operator', function () {
    $session = $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: null,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
    );

    expect($session->operator_user_id)->toBeNull();
});

test('closes session by setting end_time', function () {
    $session = $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: $this->user->id,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
    );

    $this->resolver->closeSession($session);

    $session->refresh();
    expect($session->end_time)->not->toBeNull();
});

test('an operator change ends the station\'s previous logger session', function () {
    $previous = OperatingSession::factory()->external()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_transcription' => false,
        'start_time' => Carbon::parse('2026-06-28 17:00:00'),
        'end_time' => null,
    ]);
    $nextOperator = User::factory()->create();

    $session = $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: $nextOperator->id,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
        externalSource: 'N1MM',
    );

    expect($session->id)->not->toBe($previous->id)
        ->and($previous->fresh()->end_time->toDateTimeString())->toBe('2026-06-28 18:00:00')
        ->and(OperatingSession::where('station_id', $this->station->id)->whereNull('end_time')->count())->toBe(1);
});

test('a contact for the open logger session leaves it open', function () {
    $open = OperatingSession::factory()->external()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_transcription' => false,
        'end_time' => null,
    ]);

    $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: $this->user->id,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
        externalSource: 'N1MM',
    );

    expect($open->fresh()->end_time)->toBeNull();
});

test('a logger contact leaves sessions started in the browser open', function () {
    $browser = OperatingSession::factory()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_transcription' => false,
        'external_source' => null,
        'end_time' => null,
    ]);

    $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: User::factory()->create()->id,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
        externalSource: 'N1MM',
    );

    expect($browser->fresh()->end_time)->toBeNull();
});

test('a backdated contact does not end a session before it started', function () {
    $previous = OperatingSession::factory()->external()->create([
        'station_id' => $this->station->id,
        'operator_user_id' => $this->user->id,
        'band_id' => $this->band->id,
        'mode_id' => $this->mode->id,
        'is_transcription' => false,
        'start_time' => Carbon::parse('2026-06-28 19:00:00'),
        'end_time' => null,
    ]);

    $this->resolver->resolve(
        stationId: $this->station->id,
        operatorUserId: User::factory()->create()->id,
        bandId: $this->band->id,
        modeId: $this->mode->id,
        startTime: Carbon::parse('2026-06-28 18:00:00'),
        externalSource: 'N1MM',
    );

    expect($previous->fresh()->end_time->toDateTimeString())->toBe('2026-06-28 19:00:00');
});
