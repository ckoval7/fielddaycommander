<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\ParticipantCountService;
use App\Services\SubmissionReportService;
use Illuminate\Support\Facades\DB;

/**
 * Give a configuration loggers, GOTA operators, volunteers and the rows that
 * must not count. Returns the expected participant count.
 */
function seedParticipants(EventConfiguration $config, int $extraLoggers = 0): int
{
    $logger = User::factory()->create();
    $gotaOperator = User::factory()->create();
    $volunteer = User::factory()->create();
    $noShow = User::factory()->create();
    $dupeOnly = User::factory()->create();
    $system = User::where('call_sign', User::SYSTEM_CALL_SIGN)->first()
        ?? User::factory()->create(['call_sign' => User::SYSTEM_CALL_SIGN]);

    $contact = fn (array $attributes): Contact => Contact::factory()->create(array_merge([
        'event_configuration_id' => $config->id,
        'is_duplicate' => false,
        'is_gota_contact' => false,
    ], $attributes));

    $freeTextGota = [
        'logger_user_id' => $logger->id,
        'is_gota_contact' => true,
        'gota_operator_user_id' => null,
        'gota_operator_first_name' => 'Pat',
        'gota_operator_last_name' => 'Visitor',
    ];

    $contact(['logger_user_id' => $logger->id]);
    $contact(['logger_user_id' => $logger->id]);
    $contact(['logger_user_id' => $logger->id, 'is_gota_contact' => true, 'gota_operator_user_id' => $gotaOperator->id]);
    $contact($freeTextGota);
    $contact($freeTextGota);
    $contact(['logger_user_id' => $dupeOnly->id, 'is_duplicate' => true]);
    $contact(['logger_user_id' => $system->id]);

    for ($i = 0; $i < $extraLoggers; $i++) {
        $contact(['logger_user_id' => User::factory()->create()->id]);
    }

    $shift = Shift::factory()->create(['event_configuration_id' => $config->id]);

    foreach ([
        [$logger, ShiftAssignment::STATUS_CHECKED_OUT],
        [$volunteer, ShiftAssignment::STATUS_CHECKED_IN],
        [$noShow, ShiftAssignment::STATUS_NO_SHOW],
        [$system, ShiftAssignment::STATUS_CHECKED_IN],
    ] as [$user, $status]) {
        ShiftAssignment::factory()->create(['shift_id' => $shift->id, 'user_id' => $user->id, 'status' => $status]);
    }

    $deletedShift = Shift::factory()->create(['event_configuration_id' => $config->id]);
    ShiftAssignment::factory()->create([
        'shift_id' => $deletedShift->id,
        'user_id' => User::factory()->create()->id,
        'status' => ShiftAssignment::STATUS_CHECKED_IN,
    ]);
    $deletedShift->delete();

    // Logger, GOTA operator, free-text GOTA operator and volunteer, plus extras.
    return 4 + $extraLoggers;
}

test('counts loggers, GOTA operators and volunteers once each', function () {
    $config = EventConfiguration::factory()->create();
    $expected = seedParticipants($config);

    expect(app(ParticipantCountService::class)->forConfiguration($config))->toBe($expected);
});

test('batched counts match single counts and the submission sheet', function () {
    $first = EventConfiguration::factory()->create();
    $second = EventConfiguration::factory()->create();
    $empty = EventConfiguration::factory()->create();
    seedParticipants($first);
    seedParticipants($second, extraLoggers: 2);

    $service = app(ParticipantCountService::class);

    expect($service->forConfigurations([$first->id, $second->id, $empty->id]))
        ->toBe([$first->id => 4, $second->id => 6, $empty->id => 0])
        ->and($service->forConfiguration($second))->toBe(6)
        ->and(app(SubmissionReportService::class)->getData($second)['participant_count'])->toBe(6);
});

test('batched counts run the same number of queries however many events there are', function () {
    $configs = EventConfiguration::factory()->count(5)->create();
    $configs->each(fn (EventConfiguration $config): int => seedParticipants($config));

    $queriesFor = function (array $ids): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ParticipantCountService::class)->forConfigurations($ids);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    expect($queriesFor($configs->take(1)->pluck('id')->all()))
        ->toBe($queriesFor($configs->pluck('id')->all()));
});

test('returns an empty array without querying when given no configurations', function () {
    DB::enableQueryLog();

    expect(app(ParticipantCountService::class)->forConfigurations([null]))->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});
