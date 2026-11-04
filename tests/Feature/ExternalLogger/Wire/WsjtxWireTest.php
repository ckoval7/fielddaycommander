<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Contact;
use App\Models\Event as EventModel;
use App\Models\EventConfiguration;
use App\Models\EventType;
use App\Models\Station;
use Database\Seeders\BandSeeder;
use Database\Seeders\ModeSeeder;
use Database\Seeders\SectionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\ExternalLoggers\Fldigi;
use Tests\Support\ExternalLoggers\LoggerWire;
use Tests\Support\ExternalLoggers\WireHarness;
use Tests\Support\ExternalLoggers\WireRun;
use Tests\Support\ExternalLoggers\Wsjtx;

/*
 * WSJT-X (and JTDX, which speaks the same protocol) into the WSJT-X
 * listener, over a real socket, with the traffic WSJT-X really sends:
 * heartbeats, a stream of Status and Decode messages, and a QSOLogged plus
 * LoggedADIF pair for each logged QSO.
 */

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    Event::fake();
    $this->seed([BandSeeder::class, ModeSeeder::class, SectionSeeder::class]);

    $this->config = EventConfiguration::factory()->create(['is_active' => true, 'callsign' => 'W2XYZ']);
    $this->qsoTime = $this->config->event->start_time->copy()->addHours(2)->setTime(20, 2, 15);
});

/**
 * @param  list<string>  $datagrams
 */
function wsjtxSends(EventConfiguration $config, array $datagrams): WireRun
{
    return WireHarness::run('external-logger:wsjtx', $config, 'wsjtx', 'udp', function (LoggerWire $wire) use ($datagrams) {
        $wire->burst($datagrams);
    });
}

/**
 * The traffic around one logged QSO: a heartbeat, a cycle of decodes and
 * status updates, the QSOLogged and LoggedADIF pair, then more status.
 *
 * @param  array<string, mixed>  $qso
 * @return list<string>
 */
function wsjtxSession(array $qso, string $id = 'WSJT-X', int $schema = 2): array
{
    return [
        Wsjtx::heartbeat($id, $schema),
        Wsjtx::status($id, $schema),
        Wsjtx::clear($id, $schema),
        Wsjtx::decode('CQ FD W1AW FN31', $id, $schema),
        Wsjtx::decode("W2XYZ {$qso['call']} 2A CT", $id, $schema),
        Wsjtx::status($id, $schema, dxCall: $qso['call']),
        ...Wsjtx::logged($qso, $id, $schema),
        Wsjtx::status($id, $schema),
    ];
}

test('a Field Day FT8 QSO is logged once from the QSOLogged and LoggedADIF pair', function () {
    $run = wsjtxSends($this->config, wsjtxSession(Wsjtx::fieldDayQso('W1AW', $this->qsoTime)));

    $contact = Contact::sole();

    expect($run->exitCode)->toBe(0)
        ->and($contact->callsign)->toBe('W1AW')
        ->and($contact->qso_time->format('H:i:s'))->toBe('20:02:15')
        ->and($contact->band->name)->toBe('20m')
        ->and($contact->mode->name)->toBe('Digital')
        ->and($contact->submode)->toBe('FT8')
        ->and($contact->section->code)->toBe('CT')
        ->and($contact->exchange_class)->toBe('2A')
        ->and($contact->frequency_hz)->toBe(14_074_000)
        ->and($contact->rst_sent)->toBe('-10')
        ->and($contact->rst_received)->toBe('-12')
        ->and($contact->grid_square)->toBe('FN31')
        ->and($contact->external_source)->toBe('wsjtx');
});

test('the status and decode stream WSJT-X sends between QSOs logs nothing and warns about nothing', function () {
    Log::spy();
    $id = 'WSJT-X';
    $chatter = [Wsjtx::heartbeat()];

    foreach (range(1, 40) as $i) {
        $chatter[] = Wsjtx::decode("CQ K{$i}ABC FN42", $id);
        $chatter[] = Wsjtx::status($id);
    }

    $chatter[] = Wsjtx::close();

    wsjtxSends($this->config, $chatter);

    expect(Contact::count())->toBe(0);
    Log::shouldNotHaveReceived('warning');
});

test('FT4, which WSJT-X logs as MFSK with a submode, is a digital contact with submode FT4', function () {
    wsjtxSends($this->config, Wsjtx::logged(Wsjtx::fieldDayQso('W1AW', $this->qsoTime, ['mode' => 'FT4'])));

    $contact = Contact::sole();

    expect($contact->mode->name)->toBe('Digital')
        ->and($contact->submode)->toBe('FT4');
});

/**
 * An active Winter Field Day 2027 event, and a time inside it.
 *
 * @return array{EventConfiguration, Carbon}
 */
function wfd2027Event(): array
{
    EventConfiguration::query()->update(['is_active' => false]);

    $type = EventType::firstOrCreate(['code' => 'WFD'], ['name' => 'Winter Field Day']);
    $event = EventModel::factory()->create(['event_type_id' => $type->id, 'rules_version' => '2027']);
    $config = EventConfiguration::factory()->create(['event_id' => $event->id, 'is_active' => true, 'callsign' => 'W2XYZ']);

    return [$config, $event->start_time->copy()->addHours(2)->setTime(20, 2, 15)];
}

test('a JTTY contact at Winter Field Day 2027 is logged with its exchange and earns credit', function () {
    [$config, $qsoTime] = wfd2027Event();

    wsjtxSends($config, Wsjtx::logged(Wsjtx::fieldDayQso('W1AW', $qsoTime, ['mode' => 'JTTY', 'x_rcvd' => '2H EMA'])));

    $contact = Contact::sole();

    expect($contact->mode->name)->toBe('Digital')
        ->and($contact->submode)->toBe('JTTY')
        ->and($contact->exchange_class)->toBe('2H')
        ->and($contact->section->code)->toBe('EMA')
        ->and($contact->points)->toBe(2)
        ->and($config->contactNoCreditReason($contact))->toBeNull()
        ->and($config->creditableContacts()->count())->toBe(1);
});

test('without the Field Day activity selected, the section still comes from the received exchange', function () {
    [$config, $qsoTime] = wfd2027Event();

    wsjtxSends($config, Wsjtx::logged(Wsjtx::fieldDayQso('W1AW', $qsoTime, ['mode' => 'JTTY', 'x_rcvd' => '2H EMA', 'field_day' => false])));

    $contact = Contact::sole();

    expect($contact->exchange_class)->toBe('2H')
        ->and($contact->section->code)->toBe('EMA');
});

test('a WSJT-X that has negotiated schema 3 is understood', function () {
    wsjtxSends($this->config, wsjtxSession(Wsjtx::fieldDayQso('W1AW', $this->qsoTime), schema: 3));

    expect(Contact::sole()->callsign)->toBe('W1AW');
});

test('two WSJT-X instances on one PC, started with --rig-name, log to a station each', function () {
    wsjtxSends($this->config, [
        ...wsjtxSession(Wsjtx::fieldDayQso('W1AW', $this->qsoTime), 'WSJT-X - IC7300'),
        ...wsjtxSession(Wsjtx::fieldDayQso('K1ABC', $this->qsoTime, ['band' => '40m', 'dial_hz' => 7_074_000]), 'WSJT-X - FT991'),
    ]);

    expect(Contact::with('operatingSession.station')->get()->mapWithKeys(fn (Contact $c) => [$c->callsign => $c->operatingSession->station->name])->sortKeys()->all())
        ->toBe(['K1ABC' => 'WSJT-X - FT991', 'W1AW' => 'WSJT-X - IC7300']);
});

test('WSJT-X on two PCs, both with the default id and the club call, log to a station each', function () {
    WireHarness::run('external-logger:wsjtx', $this->config, 'wsjtx', 'udp', function (LoggerWire $wire) {
        $wire->burst(wsjtxSession(Wsjtx::fieldDayQso('W1AW', $this->qsoTime)), '127.0.0.2');
        $wire->burst(wsjtxSession(Wsjtx::fieldDayQso('K1ABC', $this->qsoTime, ['band' => '40m', 'dial_hz' => 7_074_000])), '127.0.0.3');
        $wire->burst(Wsjtx::logged(Wsjtx::fieldDayQso('N1XYZ', $this->qsoTime->copy()->addMinute())), '127.0.0.2');
    });

    expect(Contact::with('operatingSession.station')->get()->mapWithKeys(fn (Contact $c) => [$c->callsign => $c->operatingSession->station->name])->sortKeys()->all())
        ->toBe(['K1ABC' => 'WSJT-X @ 127.0.0.3', 'N1XYZ' => 'WSJT-X @ 127.0.0.2', 'W1AW' => 'WSJT-X @ 127.0.0.2'])
        ->and(Station::where('name', 'W2XYZ')->exists())->toBeFalse();
});

test('a station set up with a WSJT-X rig name as its hostname receives that instance\'s contacts', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Digital tent', 'hostname' => 'WSJT-X - IC7300']);

    wsjtxSends($this->config, Wsjtx::logged(Wsjtx::fieldDayQso('W1AW', $this->qsoTime), 'WSJT-X - IC7300'));

    expect(Contact::sole()->operatingSession->station_id)->toBe($station->id);
});

test('JTDX, which sends the same messages under its own id, logs', function () {
    wsjtxSends($this->config, wsjtxSession(Wsjtx::fieldDayQso('W1AW', $this->qsoTime), 'JTDX'));

    expect(Contact::sole()->callsign)->toBe('W1AW');
});

test('a name WSJT-X sends in Latin-1 (it encodes the ADIF with toLatin1) is stored as readable text', function () {
    wsjtxSends($this->config, Wsjtx::logged(Wsjtx::fieldDayQso('VE2ABC', $this->qsoTime, ['name' => 'José', 'comments' => 'Très bien', 'x_rcvd' => '1D QC'])));

    $contact = Contact::sole();

    expect($contact->contact_name)->toBe('José')
        ->and($contact->notes)->toBe('Très bien')
        ->and($contact->section->code)->toBe('QC');
});

test('a QSO logged outside Field Day mode, with no class or section, is still logged', function () {
    wsjtxSends($this->config, Wsjtx::logged(Wsjtx::fieldDayQso('W1AW', $this->qsoTime, ['field_day' => false, 'x_rcvd' => '', 'x_sent' => ''])));

    $contact = Contact::sole();

    expect($contact->section_id)->toBeNull()
        ->and($contact->mode->name)->toBe('Digital');
});

test('the DX section WSJT-X writes as ARRL_SECT is logged', function () {
    wsjtxSends($this->config, Wsjtx::logged(Wsjtx::fieldDayQso('DL1ABC', $this->qsoTime, ['x_rcvd' => '1A DX'])));

    expect(Contact::sole()->section->code)->toBe('DX');
});

test('logging the same QSO twice from the Log QSO dialog stores it once', function () {
    $qso = Wsjtx::fieldDayQso('W1AW', $this->qsoTime);

    wsjtxSends($this->config, [...Wsjtx::logged($qso), ...Wsjtx::logged($qso)]);

    expect(Contact::count())->toBe(1);
});

test('a LoggedADIF datagram cut short does not stop the listener', function () {
    $whole = Wsjtx::loggedAdif(Wsjtx::fieldDayQso('K1ABC', $this->qsoTime));

    $run = wsjtxSends($this->config, [
        substr($whole, 0, 14),
        substr($whole, 0, 30),
        substr($whole, 0, strlen($whole) - 40),
        ...Wsjtx::logged(Wsjtx::fieldDayQso('W1AW', $this->qsoTime)),
    ]);

    expect($run->exitCode)->toBe(0)
        ->and(Contact::where('callsign', 'W1AW')->exists())->toBeTrue();
});

test('a damaged datagram is dropped quietly, not logged as a processing error', function () {
    Log::spy();
    $whole = Wsjtx::loggedAdif(Wsjtx::fieldDayQso('K1ABC', $this->qsoTime));

    wsjtxSends($this->config, [substr($whole, 0, 14), substr($whole, 0, 30), pack('NNN', Wsjtx::MAGIC, 2, 99)]);

    Log::shouldNotHaveReceived('warning', [Mockery::on(fn (string $message): bool => str_contains($message, 'packet processing error')), Mockery::any()]);
});

test('an fldigi record sent to the WSJT-X port is ignored', function () {
    wsjtxSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime))]);

    expect(Contact::count())->toBe(0);
});
