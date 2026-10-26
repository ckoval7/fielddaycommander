<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\OperationalPeriod;
use App\Services\OperationalPeriodService;

beforeEach(function () {
    $this->event = Event::factory()->create([
        'start_time' => appNow()->subHours(10),
        'end_time' => appNow()->addHours(14),
    ]);
    $this->config = EventConfiguration::factory()->create(['event_id' => $this->event->id]);
    $this->service = app(OperationalPeriodService::class);

    $this->makePeriod = fn (string $name, int $fromHours, int $toHours) => OperationalPeriod::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => $name,
        'start_time' => appNow()->addHours($fromHours),
        'end_time' => appNow()->addHours($toHours),
    ]);
});

test('an event without periods is one unsaved period covering the event', function () {
    $period = $this->service->current($this->config);

    expect($period->exists)->toBeFalse()
        ->and($period->name)->toBe('Whole event')
        ->and($period->start_time->equalTo($this->event->start_time))->toBeTrue()
        ->and($period->end_time->equalTo($this->event->end_time))->toBeTrue()
        ->and($this->service->periodsOrWholeEvent($this->config))->toHaveCount(1);
});

test('the current period is the one under way', function () {
    ($this->makePeriod)('Night', -10, -2);
    $now = ($this->makePeriod)('Day', -2, 6);

    expect($this->service->current($this->config)->is($now))->toBeTrue()
        ->and($this->service->at($this->config, appNow())->is($now))->toBeTrue();
});

test('between periods the next one is current, and after them the last', function () {
    ($this->makePeriod)('Earlier', -10, -2);
    $next = ($this->makePeriod)('Later', 2, 8);

    expect($this->service->current($this->config)->is($next))->toBeTrue()
        ->and($this->service->at($this->config, appNow()))->toBeNull();

    $next->update(['start_time' => appNow()->subHours(2), 'end_time' => appNow()->subHour()]);

    expect($this->service->current($this->config)->is($next))->toBeTrue();
});

test('a period\'s end belongs to the next period', function () {
    $first = ($this->makePeriod)('First', -4, 0);
    $second = ($this->makePeriod)('Second', 0, 4);

    expect($first->contains(appNow()))->toBeFalse()
        ->and($second->contains(appNow()))->toBeTrue();
});

test('resolving ignores another event\'s period', function () {
    $mine = ($this->makePeriod)('Mine', -2, 6);
    $theirs = OperationalPeriod::factory()->create();

    expect($this->service->resolve($this->config, $theirs->id)->is($mine))->toBeTrue()
        ->and($this->service->resolve($this->config, $mine->id)->is($mine))->toBeTrue();
});
