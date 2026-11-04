<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\BandSeeder;
use Database\Seeders\ModeSeeder;
use Database\Seeders\SectionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ExternalLoggers\Fldigi;
use Tests\Support\ExternalLoggers\LoggerWire;
use Tests\Support\ExternalLoggers\N1mm;
use Tests\Support\ExternalLoggers\N3fjp;
use Tests\Support\ExternalLoggers\WireHarness;

/*
 * Free-text logger fields longer than the columns they land in. The test
 * database (SQLite) ignores column widths, but MySQL in strict mode fails
 * the insert, and the contact is lost. So these check that what is stored
 * fits: contacts.exchange_class (5), stations.name and hostname (50),
 * users.call_sign (12).
 */

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    Event::fake();
    $this->seed([BandSeeder::class, ModeSeeder::class, SectionSeeder::class]);

    $this->config = EventConfiguration::factory()->create(['is_active' => true, 'callsign' => 'W2XYZ']);
    $this->qsoTime = $this->config->event->start_time->copy()->addHours(2)->setTime(20, 4, 31);
});

/**
 * Every stored value fits its MySQL column.
 */
function expectEverythingFitsItsColumn(): void
{
    foreach (Contact::all() as $contact) {
        expect(mb_strlen((string) $contact->exchange_class))->toBeLessThanOrEqual(5)
            ->and(mb_strlen($contact->callsign))->toBeLessThanOrEqual(20)
            ->and(mb_strlen((string) $contact->submode))->toBeLessThanOrEqual(20);
    }

    foreach (Station::all() as $station) {
        expect(mb_strlen($station->name))->toBeLessThanOrEqual(50)
            ->and(mb_strlen((string) $station->hostname))->toBeLessThanOrEqual(50);
    }

    foreach (User::all() as $user) {
        expect(mb_strlen($user->call_sign))->toBeLessThanOrEqual(12);
    }
}

test('an N1MM exchange typed into the class field is not stored as an over-long class', function () {
    WireHarness::run('external-logger:n1mm', $this->config, 'n1mm', 'udp', fn (LoggerWire $wire) => $wire->burst([
        N1mm::contactInfo(N1mm::fieldDayContact('W1AW', $this->qsoTime, ['exchange1' => '2A CT'])),
        N1mm::contactInfo(N1mm::fieldDayContact('K1ABC', $this->qsoTime, ['exchange1' => 'twelveA'])),
    ]));

    expect(Contact::where('callsign', 'W1AW')->value('exchange_class'))->toBe('2A')
        ->and(Contact::count())->toBe(2);
    expectEverythingFitsItsColumn();
});

test('an operator field holding a name or a long portable call does not create a bad user', function () {
    WireHarness::run('external-logger:n1mm', $this->config, 'n1mm', 'udp', fn (LoggerWire $wire) => $wire->burst([
        N1mm::contactInfo(N1mm::fieldDayContact('W1AW', $this->qsoTime, ['operator' => 'Bob Smith'])),
        N1mm::contactInfo(N1mm::fieldDayContact('K1ABC', $this->qsoTime, ['operator' => 'VE3/K3CPK/MOBILE'])),
        N1mm::contactInfo(N1mm::fieldDayContact('N1XYZ', $this->qsoTime, ['operator' => 'K3CPK'])),
    ]));

    expect(Contact::count())->toBe(3)
        ->and(Contact::where('callsign', 'W1AW')->value('logger_user_id'))->toBeNull()
        ->and(User::where('call_sign', 'K3CPK')->exists())->toBeTrue()
        ->and(User::where('call_sign', 'like', '%BOB%')->orWhere('call_sign', 'like', '%MOBILE%')->exists())->toBeFalse();
    expectEverythingFitsItsColumn();
});

test('an N3FJP station name longer than a station name can be is stored cut to fit, and keeps matching', function () {
    $station = 'Hamden Amateur Radio Association Field Day GOTA station tent 2';

    WireHarness::run('external-logger:n3fjp', $this->config, 'n3fjp', 'tcp', function (LoggerWire $wire) use ($station) {
        $pc = $wire->connect();
        $pc->send(
            N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime, overrides: ['STATION' => $station])),
            N3fjp::add(N3fjp::fieldDayContact('K1ABC', $this->qsoTime, overrides: ['STATION' => $station, 'OPERATOR' => 'KD2ABCDEFGHIJK'])),
        );
        $pc->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 2);
    });

    expect(Contact::count())->toBe(2)
        ->and(Station::count())->toBe(1);
    expectEverythingFitsItsColumn();
});

test('a long fldigi station callsign and operator are stored within their columns', function () {
    WireHarness::run('external-logger:udp-adif', $this->config, 'udp-adif', 'udp', fn (LoggerWire $wire) => $wire->burst([
        Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime, [
            'sta_call' => str_repeat('W2XYZ-FIELD-DAY-', 5),
            'op_call' => 'K3CPK-THE-OPERATOR',
            'arrl_class' => '2A-ish',
        ])),
    ]));

    expect(Contact::count())->toBe(1);
    expectEverythingFitsItsColumn();
});
