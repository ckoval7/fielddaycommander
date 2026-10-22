<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\StationUnavailableReason;
use App\Exceptions\RollCallException;
use App\Models\EventConfiguration;
use App\Models\Net;
use App\Models\NetLogEntry;
use App\Models\OperatingSession;
use App\Models\RollCall;
use App\Models\Station;
use App\Models\User;
use App\Services\RollCallService;

beforeEach(function () {
    $this->config = EventConfiguration::factory()->publicService()->create();
    $this->net = Net::factory()->create(['event_configuration_id' => $this->config->id, 'net_control_station' => 'Net Control']);
    $this->user = User::factory()->create();
    $this->service = app(RollCallService::class);

    $this->aid2 = Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 2']);
    $this->aid10 = Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 10']);
});

test('starting a roll call lists the net positions and logs the call', function () {
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Spare Radio']);
    Station::factory()->create(['event_configuration_id' => $this->config->id, 'tactical_callsign' => 'Aid 12'])
        ->markUnavailable(StationUnavailableReason::cases()[0], null, $this->user);

    $rollCall = $this->service->start($this->net, $this->user);

    expect($rollCall->stations->pluck('name')->sort(SORT_NATURAL)->values()->all())->toBe(['Aid 2', 'Aid 10'])
        ->and($rollCall->isActive())->toBeTrue();

    $entry = NetLogEntry::sole();
    expect($entry->from_station)->toBe('Net Control')
        ->and($entry->to_station)->toBe('ALL STATIONS')
        ->and($entry->message)->toBe('ROLL CALL (PAR): 2 STATIONS')
        ->and($entry->net_id)->toBe($this->net->id);
});

test('starting again returns the open roll call', function () {
    $first = $this->service->start($this->net, $this->user);

    expect($this->service->start($this->net, $this->user)->id)->toBe($first->id)
        ->and(RollCall::count())->toBe(1)
        ->and(NetLogEntry::count())->toBe(1);
});

test('a net with no stations to call cannot start a roll call', function () {
    Station::query()->delete();

    $this->service->start($this->net, $this->user);
})->throws(RollCallException::class);

test('an entry from an expected station accounts for it, by tactical name or operator callsign', function () {
    OperatingSession::factory()->create([
        'station_id' => $this->aid10->id,
        'operator_user_id' => User::factory()->create(['call_sign' => 'W1ABC'])->id,
        'end_time' => null,
        'is_transcription' => false,
    ]);
    $rollCall = $this->service->start($this->net, $this->user);

    $toAid2 = NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id, 'from_station' => 'Net Control', 'to_station' => 'Aid 2']);
    $this->service->recordEntry($toAid2);
    expect($rollCall->stations()->whereNotNull('accounted_at')->count())->toBe(0);

    $fromAid2 = NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id, 'from_station' => 'aid 2']);
    $fromW1abc = NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id, 'from_station' => 'W1ABC']);
    $this->service->recordEntry($fromAid2);
    $this->service->recordEntry($fromW1abc);

    expect($rollCall->stations()->whereNull('accounted_at')->count())->toBe(0);
});

test('entries on another net do not count', function () {
    $rollCall = $this->service->start($this->net, $this->user);
    $otherNet = Net::factory()->create(['event_configuration_id' => $this->config->id]);

    $this->service->recordEntry(NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $otherNet->id, 'from_station' => 'Aid 2']));

    expect($rollCall->stations()->whereNotNull('accounted_at')->count())->toBe(0);
});

test('marking a station logs its PAR answer and undoing removes it', function () {
    $rollCall = $this->service->start($this->net, $this->user);
    $aid2 = $rollCall->stations()->where('name', 'Aid 2')->sole();

    $this->service->markAccounted($aid2, $this->user);

    $answer = NetLogEntry::latest('id')->first();
    expect($aid2->fresh()->isAccounted())->toBeTrue()
        ->and($answer->from_station)->toBe('Aid 2')
        ->and($answer->to_station)->toBe('Net Control')
        ->and($answer->message)->toBe('PAR');

    $this->service->unmark($aid2->fresh());

    expect($aid2->fresh()->isAccounted())->toBeFalse()
        ->and($answer->fresh()->trashed())->toBeTrue();
});

test('ending logs the result and names stations not accounted for', function () {
    $rollCall = $this->service->start($this->net, $this->user);
    $this->service->markAccounted($rollCall->stations()->where('name', 'Aid 2')->sole(), $this->user);

    $this->service->end($rollCall->fresh(), $this->user);

    expect($rollCall->fresh()->isActive())->toBeFalse()
        ->and(NetLogEntry::latest('id')->first()->message)->toBe('ROLL CALL COMPLETE: 1 OF 2 ACCOUNTED FOR. NOT ACCOUNTED FOR: AID 10');

    $this->service->recordEntry(NetLogEntry::factory()->create(['event_configuration_id' => $this->config->id, 'net_id' => $this->net->id, 'from_station' => 'Aid 10']));
    expect($rollCall->stations()->where('name', 'Aid 10')->sole()->isAccounted())->toBeFalse();
});

test('a roll call with everyone accounted for ends without a missing list', function () {
    $rollCall = $this->service->start($this->net, $this->user);
    $rollCall->stations->each(fn ($station) => $this->service->markAccounted($station, $this->user));

    $this->service->end($rollCall->fresh(), $this->user);

    expect(NetLogEntry::latest('id')->first()->message)->toBe('ROLL CALL COMPLETE: 2 OF 2 ACCOUNTED FOR');
});
