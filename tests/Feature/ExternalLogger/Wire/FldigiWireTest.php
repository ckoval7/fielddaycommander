<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Station;
use Database\Seeders\BandSeeder;
use Database\Seeders\ModeSeeder;
use Database\Seeders\SectionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\ExternalLoggers\Fldigi;
use Tests\Support\ExternalLoggers\LoggerWire;
use Tests\Support\ExternalLoggers\WireHarness;
use Tests\Support\ExternalLoggers\WireRun;
use Tests\Support\ExternalLoggers\Wsjtx;

/*
 * fldigi's "Broadcast UDP ADIF record" feed into the UDP ADIF listener,
 * sent over a real socket exactly as fldigi's post_udp_log() writes it.
 */

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    Event::fake();
    $this->seed([BandSeeder::class, ModeSeeder::class, SectionSeeder::class]);

    $this->config = EventConfiguration::factory()->create(['is_active' => true, 'callsign' => 'W2XYZ']);
    $this->qsoTime = $this->config->event->start_time->copy()->addHours(2)->setTime(20, 2, 17);
});

/**
 * Run the UDP ADIF listener while fldigi sends $datagrams.
 *
 * @param  list<string>  $datagrams
 */
function fldigiSends(EventConfiguration $config, array $datagrams): WireRun
{
    return WireHarness::run('external-logger:udp-adif', $config, 'udp-adif', 'udp', function (LoggerWire $wire) use ($datagrams) {
        foreach ($datagrams as $datagram) {
            $wire->datagram($datagram);
        }
    });
}

test('a Field Day QSO with every field fldigi sends by default is logged in full', function () {
    $run = fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime, ['op_call' => 'K3CPK']))]);

    $contact = Contact::sole();

    expect($run->exitCode)->toBe(0)
        ->and($contact->callsign)->toBe('W1AW')
        ->and($contact->band->name)->toBe('20m')
        ->and($contact->mode->name)->toBe('Digital')
        ->and($contact->submode)->toBe('PSK31')
        ->and($contact->section->code)->toBe('CT')
        ->and($contact->exchange_class)->toBe('2A')
        ->and($contact->frequency_hz)->toBe(14_070_150)
        ->and($contact->power_watts)->toBe(100)
        ->and($contact->external_source)->toBe('udp-adif')
        ->and($contact->logger->call_sign)->toBe('K3CPK');
});

test('fldigi cuts TIME_ON to HHMM, so the contact is logged at the start of that minute', function () {
    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime))]);

    expect(Contact::sole()->qso_time->format('H:i:s'))->toBe('20:02:00');
});

test('every mode fldigi exports lands in the right mode group', function (string $fldigiMode, string $group) {
    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime, ['mode' => $fldigiMode]))]);

    expect(Contact::sole()->mode->name)->toBe($group);
})->with([
    ['CW', 'CW'],
    ['SSB', 'Phone'],
    ['PSK31', 'Digital'],
    ['8PSK500', 'Digital'],
    ['RTTY', 'Digital'],
    ['MFSK16', 'Digital'],
    ['OLIVIA 8/500', 'Digital'],
    ['CONTESTI8/250', 'Digital'],
    ['THOR22', 'Digital'],
    ['FSQ', 'Digital'],
]);

test('a QSO with no station callsign set in fldigi goes to a named station, not a blank one', function () {
    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime, ['sta_call' => '']))]);

    $station = Contact::sole()->operatingSession->station;

    expect(trim($station->name))->not->toBe('')
        ->and(Station::where('name', '')->exists())->toBeFalse();
});

test('a QSO sent with only some fields ticked in fldigi is still logged', function () {
    $qso = Fldigi::fieldDayQso('W1AW', $this->qsoTime);

    fldigiSends($this->config, [Fldigi::datagram($qso, ['call', 'mode', 'freq', 'date_on', 'time_on', 'arrl_class', 'arrl_sect'])]);

    $contact = Contact::sole();

    expect($contact->band->name)->toBe('20m')
        ->and($contact->section->code)->toBe('CT')
        ->and($contact->exchange_class)->toBe('2A');
});

test('a Winter Field Day class and lower-case section are logged', function () {
    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('w1aw', $this->qsoTime, ['arrl_class' => '1h', 'arrl_sect' => 'ct']))]);

    $contact = Contact::sole();

    expect($contact->callsign)->toBe('W1AW')
        ->and($contact->exchange_class)->toBe('1H')
        ->and($contact->section->code)->toBe('CT');
});

test('a name and QTH with accented letters, counted in UTF-8 bytes as fldigi does, are kept whole', function () {
    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('VE2ABC', $this->qsoTime, [
        'name' => 'José', 'qth' => 'Montréal', 'arrl_sect' => 'QC',
    ]))]);

    $contact = Contact::sole();

    expect($contact->contact_name)->toBe('José')
        ->and($contact->adif_fields['QTH'])->toBe('Montréal')
        ->and($contact->section->code)->toBe('QC');
});

test('a QTH typed on a Windows PC in a legacy code page (not UTF-8) does not lose the contact', function () {
    // fldigi passes the text box bytes through untouched; on a Windows PC
    // set to Windows-1252 "Montréal" is not valid UTF-8.
    $qth = mb_convert_encoding('Montréal', 'Windows-1252', 'UTF-8');

    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('VE2ABC', $this->qsoTime, ['qth' => $qth, 'arrl_sect' => 'QC']))]);

    $contact = Contact::sole();

    expect($contact->callsign)->toBe('VE2ABC')
        ->and(mb_check_encoding((string) json_encode($contact->adif_fields), 'UTF-8'))->toBeTrue();
});

test('multi-line notes arrive with fldigi\'s ";" separators and are stored as notes', function () {
    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime, ['notes' => "Good op\nweak signal"]))]);

    expect(Contact::sole()->notes)->toBe('Good op;weak signal');
});

test('notes that contain an ADIF end-of-record marker do not split the QSO', function () {
    // ADIF values are length-prefixed, so "<EOR>" inside a value is text.
    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime, ['notes' => 'typed <EOR> by mistake', 'op_call' => 'K3CPK']))]);

    $contact = Contact::sole();

    expect($contact->notes)->toBe('typed <EOR> by mistake')
        ->and($contact->section->code)->toBe('CT')
        ->and($contact->logger->call_sign)->toBe('K3CPK');
});

test('the test record from fldigi\'s UDP setup page logs nothing and raises no warning', function () {
    Log::spy();

    $run = fldigiSends($this->config, [Fldigi::testDatagram('192.168.1.20', '2238')]);

    expect($run->exitCode)->toBe(0)
        ->and(Contact::count())->toBe(0);
    Log::shouldNotHaveReceived('warning');
});

test('the same record sent twice is logged once', function () {
    $datagram = Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime));

    fldigiSends($this->config, [$datagram, $datagram]);

    expect(Contact::count())->toBe(1);
});

test('two QSOs logged in the same minute are both kept', function () {
    fldigiSends($this->config, [
        Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime)),
        Fldigi::datagram(Fldigi::fieldDayQso('K1ABC', $this->qsoTime->copy()->addSeconds(30))),
    ]);

    expect(Contact::pluck('callsign')->sort()->values()->all())->toBe(['K1ABC', 'W1AW']);
});

test('a QSO logged with the frequency box empty still gets its band from BAND', function () {
    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime, ['freq' => '', 'band' => '40m']))]);

    expect(Contact::sole()->band->name)->toBe('40m');
});

test('a WSJT-X packet sent to the fldigi port is skipped and the fldigi QSO after it still logs', function () {
    $wsjtx = Wsjtx::loggedAdif(Wsjtx::fieldDayQso('K1ABC', $this->qsoTime));

    fldigiSends($this->config, [Wsjtx::heartbeat(), $wsjtx, Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime))]);

    expect(Contact::pluck('callsign')->all())->toBe(['W1AW']);
});

test('a QSO outside the event window is rejected and the listener keeps going', function () {
    $tooEarly = $this->config->event->start_time->copy()->subDay();

    fldigiSends($this->config, [
        Fldigi::datagram(Fldigi::fieldDayQso('K1ABC', $tooEarly)),
        Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime)),
    ]);

    expect(Contact::pluck('callsign')->all())->toBe(['W1AW']);
});

test('a garbled callsign is dropped without stopping the listener', function () {
    fldigiSends($this->config, [
        Fldigi::datagram(Fldigi::fieldDayQso('<script>', $this->qsoTime)),
        Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime)),
    ]);

    expect(Contact::pluck('callsign')->all())->toBe(['W1AW']);
});

test('fldigi on two PCs, both sending the club call, logs to a station per PC', function () {
    WireHarness::run('external-logger:udp-adif', $this->config, 'udp-adif', 'udp', function (LoggerWire $wire) {
        $wire->datagram(Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime)), '127.0.0.2');
        $wire->datagram(Fldigi::datagram(Fldigi::fieldDayQso('K1ABC', $this->qsoTime)), '127.0.0.3');
        $wire->datagram(Fldigi::datagram(Fldigi::fieldDayQso('N1XYZ', $this->qsoTime->copy()->addMinute())), '127.0.0.2');
    });

    expect(Contact::with('operatingSession.station')->get()->mapWithKeys(fn (Contact $c) => [$c->callsign => $c->operatingSession->station->name])->sortKeys()->all())
        ->toBe(['K1ABC' => 'W2XYZ @ 127.0.0.3', 'N1XYZ' => 'W2XYZ @ 127.0.0.2', 'W1AW' => 'W2XYZ @ 127.0.0.2']);
});

test('a station already set up under the station callsign fldigi sends keeps receiving its contacts', function () {
    $station = Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => 'Digital', 'hostname' => 'w2xyz']);

    fldigiSends($this->config, [Fldigi::datagram(Fldigi::fieldDayQso('W1AW', $this->qsoTime))]);

    expect(Contact::sole()->operatingSession->station_id)->toBe($station->id);
});
