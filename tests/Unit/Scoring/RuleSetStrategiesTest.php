<?php

use App\Scoring\Bonuses\FieldDay2025\NtsMessageStrategy;
use App\Scoring\Bonuses\FieldDay2025\SmSecMessageStrategy;
use App\Scoring\Bonuses\FieldDay2025\YouthParticipationStrategy;
use App\Scoring\Contracts\BonusStrategy;
use App\Scoring\DomainEvents\MessageChanged;
use App\Scoring\DomainEvents\QsoLogged;
use App\Scoring\Rules\FieldDay2025;
use App\Scoring\Rules\FieldDay2026;
use App\Scoring\Rules\FieldDayTest;

it('FieldDay2025 registers all 12 strategies', function () {
    $map = (new FieldDay2025)->strategies();

    $expected = [
        'sm_sec_message',
        'nts_message',
        'w1aw_bulletin',
        'elected_official_visit',
        'agency_visit',
        'media_publicity',
        'youth_participation',
        'social_media',
        'public_location',
        'public_info_booth',
        'educational_activity',
        'web_submission',
    ];

    expect(array_keys($map))->toEqualCanonicalizing($expected);

    foreach ($map as $class) {
        expect(class_exists($class))->toBeTrue()
            ->and(is_subclass_of($class, BonusStrategy::class))->toBeTrue();
    }
});

it('FieldDay2026 inherits FieldDay2025 strategies', function () {
    expect((new FieldDay2026)->strategies())->toBe((new FieldDay2025)->strategies());
});

it('FieldDayTest returns an empty strategies map', function () {
    expect((new FieldDayTest)->strategies())->toBe([]);
});

test('FieldDay2025::strategiesFor returns only strategies subscribing to the given event class', function () {
    $ruleset = new FieldDay2025;

    $qsoSubscribers = $ruleset->strategiesFor(QsoLogged::class);
    $messageSubscribers = $ruleset->strategiesFor(MessageChanged::class);

    expect($qsoSubscribers)->toContain(YouthParticipationStrategy::class)
        ->not->toContain(NtsMessageStrategy::class);

    expect($messageSubscribers)
        ->toContain(NtsMessageStrategy::class)
        ->toContain(SmSecMessageStrategy::class)
        ->not->toContain(YouthParticipationStrategy::class);
});

test('FieldDay2025::strategiesFor returns empty array for unrelated event class', function () {
    $ruleset = new FieldDay2025;

    expect($ruleset->strategiesFor(stdClass::class))->toBe([]);
});

test('FieldDayTest::strategiesFor returns empty array regardless of event class', function () {
    $ruleset = new FieldDayTest;

    expect($ruleset->strategiesFor(QsoLogged::class))->toBe([]);
});

test('FieldDay2025 STRATEGY_INDEX matches every strategy subscribesTo()', function () {
    $ruleset = new FieldDay2025;

    foreach ($ruleset->strategies() as $class) {
        /** @var BonusStrategy $strategy */
        $strategy = new $class;

        foreach ($strategy->subscribesTo() as $eventClass) {
            expect($ruleset->strategiesFor($eventClass))->toContain($class);
        }
    }
});
