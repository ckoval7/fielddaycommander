<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Enums\StationUnavailableReason;
use App\Events\StationStatusChanged;
use App\Models\Band;
use App\Models\Event as FieldDayEvent;
use App\Models\EventConfiguration;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use App\Support\BroadcastChannels;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->fdEvent = FieldDayEvent::factory()->create();
    $config = EventConfiguration::factory()->create(['event_id' => $this->fdEvent->id]);
    $this->station = Station::factory()->create(['event_configuration_id' => $config->id]);
});

test('starting a session broadcasts a station status change on the event channel', function () {
    Event::fake([StationStatusChanged::class]);

    OperatingSession::factory()->active()->create(['station_id' => $this->station->id]);

    Event::assertDispatched(StationStatusChanged::class, function (StationStatusChanged $event) {
        return $event->eventId === $this->fdEvent->id
            && $event->stationId === $this->station->id
            && $event->broadcastAs() === BroadcastChannels::STATION_STATUS_CHANGED
            && $event->broadcastOn()[0]->name === 'private-'.BroadcastChannels::event($this->fdEvent->id);
    });
});

test('changing band or ending a session broadcasts a station status change', function () {
    $session = OperatingSession::factory()->active()->create(['station_id' => $this->station->id]);
    $otherBand = Band::factory()->create(['name' => 'test-band']);

    Event::fake([StationStatusChanged::class]);
    $session->update(['band_id' => $otherBand->id]);
    Event::assertDispatchedTimes(StationStatusChanged::class, 1);

    $session->update(['end_time' => now()]);
    Event::assertDispatchedTimes(StationStatusChanged::class, 2);
});

test('logging a contact count update does not broadcast a station status change', function () {
    $session = OperatingSession::factory()->active()->create(['station_id' => $this->station->id]);

    Event::fake([StationStatusChanged::class]);
    $session->increment('qso_count');

    Event::assertNotDispatched(StationStatusChanged::class);
});

test('transcription sessions do not broadcast a station status change', function () {
    Event::fake([StationStatusChanged::class]);

    OperatingSession::factory()->active()->create([
        'station_id' => $this->station->id,
        'is_transcription' => true,
    ]);

    Event::assertNotDispatched(StationStatusChanged::class);
});

test('marking a station unavailable and ready broadcasts a station status change', function () {
    Event::fake([StationStatusChanged::class]);

    $this->station->markUnavailable(StationUnavailableReason::cases()[0], null, User::factory()->create());
    Event::assertDispatchedTimes(StationStatusChanged::class, 1);

    $this->station->markAvailable();
    Event::assertDispatchedTimes(StationStatusChanged::class, 2);
});
