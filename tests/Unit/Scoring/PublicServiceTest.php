<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Mode;
use App\Models\Station;
use App\Scoring\Rules\PublicService;
use App\Scoring\RuleSetFactory;
use Database\Seeders\EventTypeSeeder;

uses()->group('unit', 'scoring');

test('resolves PublicService for a Public Service event', function () {
    $event = Event::factory()->publicService()->create();

    expect(app(RuleSetFactory::class)->forEvent($event))->toBeInstanceOf(PublicService::class);
});

test('resolves PublicService whatever rules_version the event carries', function () {
    $event = Event::factory()->publicService()->create(['rules_version' => '2026']);

    expect(app(RuleSetFactory::class)->forEvent($event))->toBeInstanceOf(PublicService::class);
});

test('scores nothing and offers no bonuses', function () {
    $config = EventConfiguration::factory()->publicService()->create();
    $rules = new PublicService;

    expect($config->scoreBreakdown()->total)->toBe(0)
        ->and($config->scoreBreakdown()->lines)->toBe([])
        ->and($rules->strategies())->toBe([])
        ->and($rules->bonus('safety_officer'))->toBeNull()
        ->and($rules->bonusRuleReference('safety_officer'))->toBeNull()
        ->and($rules->pointsForContact(Mode::factory()->create(), Station::factory()->create()))->toBe(0);
});

test('only the Public Service event type is unscored', function () {
    $this->seed(EventTypeSeeder::class);

    expect(EventType::where('code', 'FD')->first()->isScored())->toBeTrue()
        ->and(EventType::where('code', 'WFD')->first()->isScored())->toBeTrue()
        ->and(EventType::where('code', 'PS')->first()->isScored())->toBeFalse();
});

test('events and configurations report whether they are scored', function () {
    $scored = EventConfiguration::factory()->create();
    $unscored = EventConfiguration::factory()->publicService()->create();

    expect($scored->isScored())->toBeTrue()
        ->and($scored->event->isScored())->toBeTrue()
        ->and($unscored->isScored())->toBeFalse()
        ->and($unscored->event->isScored())->toBeFalse();
});
