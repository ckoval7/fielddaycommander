<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use Database\Seeders\BandSeeder;
use Database\Seeders\ModeSeeder;
use Database\Seeders\SectionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ExternalLoggers\LoggerWire;
use Tests\Support\ExternalLoggers\N3fjp;
use Tests\Support\ExternalLoggers\WireHarness;
use Tests\Support\ExternalLoggers\WireRun;

/*
 * N3FJP Field Day and Winter Field Day client PCs talking to Field Day
 * Commander's N3FJP server over real TCP, with the traffic and quirks of
 * N3FJP's own network client.
 */

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    Event::fake();
    $this->seed([BandSeeder::class, ModeSeeder::class, SectionSeeder::class]);

    $this->config = EventConfiguration::factory()->create(['is_active' => true, 'callsign' => 'W2XYZ']);
    $this->qsoTime = $this->config->event->start_time->copy()->addHours(2)->setTime(20, 42, 7);
});

/**
 * @param  callable(LoggerWire): void  $script
 */
function n3fjpSession(EventConfiguration $config, callable $script): WireRun
{
    return WireHarness::run('external-logger:n3fjp', $config, 'n3fjp', 'tcp', $script);
}

/**
 * Contacts logged in Field Day Commander, for a client to load.
 */
function fdcContacts(EventConfiguration $config, int $count, DateTimeInterface $from): void
{
    $station = Station::factory()->create(['event_configuration_id' => $config->id, 'name' => 'Web Station']);
    $session = OperatingSession::factory()->create(['station_id' => $station->id, 'end_time' => now()]);
    $bandId = Band::where('name', '40m')->value('id');
    $modeId = Mode::where('name', 'CW')->value('id');

    foreach (range(1, $count) as $i) {
        Contact::factory()->create([
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'band_id' => $bandId,
            'mode_id' => $modeId,
            'callsign' => "K{$i}FDC",
            'qso_time' => Carbon::instance($from)->addSeconds($i),
        ]);
    }
}

test('a client connecting to an empty log gets CLEAR then LIST', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $pc->send(...N3fjp::hello('GOTA-PC'));
        $pc->read(fn (array $messages) => in_array('LIST', N3fjp::transactions($messages), true));
    });

    expect(N3fjp::transactions($run->messages()))->toBe(['CLEAR', 'LIST']);
});

test('a contact logged at the client is saved and echoed back with the exact call the operator typed', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $pc->send(...N3fjp::hello('GOTA-PC'));
        $pc->read(fn (array $m) => in_array('LIST', N3fjp::transactions($m), true));
        $pc->send(N3fjp::add(N3fjp::fieldDayContact('W1AW/3', $this->qsoTime)));
        $pc->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 1);
    });

    $contact = Contact::sole();

    expect(N3fjp::transactions($run->messages()))->toContain('ADD W1AW/3')
        ->and($contact->callsign)->toBe('W1AW/3')
        ->and($contact->exchange_class)->toBe('2A')
        ->and($contact->section->code)->toBe('CT')
        ->and($contact->band->name)->toBe('20m')
        ->and($contact->mode->name)->toBe('Phone')
        ->and($contact->qso_time->format('Y-m-d H:i:s'))->toBe($this->qsoTime->format('Y-m-d H:i:s'))
        ->and($contact->logger->call_sign)->toBe('K3CPK');
});

test('dates and times written with the PC\'s own separators are read as the right moment', function (string $date, string $time) {
    n3fjpSession($this->config, function (LoggerWire $wire) use ($date, $time) {
        $pc = $wire->connect();
        $pc->send(N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime, overrides: [
            'DATESTR' => str_replace(['Y', 'm', 'd'], [$this->qsoTime->format('Y'), $this->qsoTime->format('m'), $this->qsoTime->format('d')], $date),
            'TIMEONSTR' => $time,
        ])));
        $pc->read();
    });

    expect(Contact::sole()->qso_time->format('Y-m-d H:i'))->toBe($this->qsoTime->format('Y-m-d').' 20:42');
})->with([
    'en-US' => ['Y/m/d', '20:42:07'],
    'de-DE' => ['Y.m.d', '20:42:07'],
    'sv-SE' => ['Y-m-d', '20:42:07'],
    'fi-FI time' => ['Y/m/d', '20.42.07'],
    'post-contest entry' => ['Y/m/d', '2042'],
]);

test('a rig frequency written in the PC\'s number format lands on the right band', function (string $band, string $frequency, string $expectedBand, int $expectedHz) {
    n3fjpSession($this->config, function (LoggerWire $wire) use ($band, $frequency) {
        $pc = $wire->connect();
        $pc->send(N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime, overrides: ['BAND' => $band, 'FREQUENCY' => $frequency, 'MODE' => 'USB'])));
        $pc->read();
    });

    $contact = Contact::sole();

    expect($contact->band->name)->toBe($expectedBand)
        ->and($contact->frequency_hz)->toBe($expectedHz);
})->with([
    'en-US 20m' => ['20', '14.25000', '20m', 14_250_000],
    'de-DE 20m' => ['20', '14,25000', '20m', 14_250_000],
    'en-US 23cm with a group separator' => ['23', '1,296.10000', '23cm', 1_296_100_000],
    'de-DE 23cm' => ['23', '1.296,10000', '23cm', 1_296_100_000],
]);

test('a comment with raw &, < and > (N3FJP does not escape) is saved and the record still parses', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $pc->send(N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime, overrides: ['COMMENTS' => 'QRP & <5W> tnx 😀'])));
        $pc->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 1);
    });

    expect(Contact::sole()->notes)->toBe('QRP & <5W> tnx 😀')
        ->and(N3fjp::transactions($run->messages()))->toBe(['ADD W1AW']);
});

test('a client whose messages arrive split at odd byte boundaries is understood', function () {
    n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $bytes = implode('', array_map(N3fjp::frame(...), [...N3fjp::hello('GOTA-PC'), N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime))]));

        foreach (str_split($bytes, 7) as $piece) {
            $pc->write($piece);
            usleep(2000);
        }

        $pc->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 1);
    });

    expect(Contact::sole()->callsign)->toBe('W1AW');
});

test('a contact logged at one PC is relayed to the others', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $gota = $wire->connect();
        $cw = $wire->connect();
        $gota->send(...N3fjp::hello('GOTA-PC'));
        $cw->send(...N3fjp::hello('CW-PC'));
        $cw->read(fn (array $m) => in_array('LIST', N3fjp::transactions($m), true));
        $gota->send(N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime)));
        $cw->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 1);
    });

    expect(N3fjp::transactions($run->messages(1)))->toContain('ADD W1AW');
});

test('an edit and a delete from the client change and remove the contact', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $contact = N3fjp::fieldDayContact('W1AW', $this->qsoTime);
        $pc->send(N3fjp::add($contact));
        $pc->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 1);
        $pc->send(N3fjp::edit([...$contact, 'CLASS' => '3A ', 'PRIMARYKEY' => '1', 'POINTS' => '1'], 'GOTA-PC'));
        $pc->readUntilCount('<TRANSACTION>EDIT</TRANSACTION>', 1);
        $pc->send(N3fjp::delete(1, 'GOTA-PC'));
        $pc->readUntilCount('<TRANSACTION>DELETE</TRANSACTION>', 1);
    });

    expect(Contact::count())->toBe(0)
        ->and(Contact::withTrashed()->sole()->exchange_class)->toBe('3A')
        ->and(N3fjp::transactions($run->messages()))->toContain('EDIT W1AW', 'DELETE');
});

test('an operator who re-enters a contact after the five-second timeout gets both echoed, so key numbering stays in step', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $contact = N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime));
        $pc->send($contact, $contact);
        $pc->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 2);
    });

    expect(N3fjp::transactions($run->messages()))->toBe(['ADD W1AW', 'ADD W1AW'])
        ->and(Contact::count())->toBe(2);
});

test('a Winter Field Day contact keeps its WFD class', function () {
    n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $pc->send(N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime, overrides: ['CONTESTID' => 'WFD', 'CLASS' => '1H', 'CHECK' => 'W2XYZ'])));
        $pc->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 1);
    });

    expect(Contact::sole()->exchange_class)->toBe('1H');
});

test('a contact with a callsign Field Day Commander refuses is answered with a chat, not an echo', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $pc->send(N3fjp::add(N3fjp::fieldDayContact('W1AW!', $this->qsoTime)));
        $pc->read(fn (array $m) => array_filter($m, fn (string $x) => str_contains($x, '<MESG>')) !== []);
    });

    expect(Contact::count())->toBe(0)
        ->and(N3fjp::transactions($run->messages()))->not->toContain('ADD W1AW!');
});

test('a reconnecting client, which sends only BAMS and CHECK, is told the log count', function () {
    fdcContacts($this->config, 3, $this->qsoTime);

    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $pc->send(N3fjp::bams('GOTA-PC'), N3fjp::check());
        $pc->read(fn (array $m) => in_array('CHECK', N3fjp::transactions($m), true));
    });

    $check = collect($run->messages())->first(fn (string $m) => str_contains($m, '<TRANSACTION>CHECK</TRANSACTION>'));

    expect($check)->toContain('<COUNT>3</COUNT>')->toContain('<LAST>3</LAST>');
});

test('a clock sync from one client is not passed on to the others', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $a = $wire->connect();
        $b = $wire->connect();
        $a->send(N3fjp::clockSync($this->qsoTime), N3fjp::chat('GOTA-PC', 'hello'));
        $b->read(fn (array $m) => array_filter($m, fn (string $x) => str_contains($x, '<MESG>')) !== []);
    });

    expect(collect($run->messages(1))->contains(fn (string $m) => str_contains($m, '<SCLK>')))->toBeFalse();
});

test('a large log loads intact into a client that decodes each 8 KB read on its own', function () {
    fdcContacts($this->config, 600, $this->qsoTime);

    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        // A small receive window makes the server's writes back up and
        // land part-way through a message, as they do for a busy PC.
        $pc = $wire->connect(8192, receiveBuffer: 4096);
        $pc->send(...N3fjp::hello('GOTA-PC'));
        $pc->read(function (array $m) use ($pc) {
            $pc->stall(1);

            return in_array('LIST', N3fjp::transactions($m), true);
        }, timeoutSeconds: 60);
    });

    $gets = array_values(array_filter(N3fjp::transactions($run->messagesAsClientSees()), fn (string $t) => str_starts_with($t, 'GET')));

    expect($gets)->toHaveCount(600)
        ->and($gets[0])->toBe('GET K1FDC')
        ->and($gets[599])->toBe('GET K600FDC');
});

test('the server never sends an empty message, which would make an N3FJP client hang up', function () {
    fdcContacts($this->config, 2, $this->qsoTime);

    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $pc = $wire->connect();
        $pc->send(...N3fjp::hello('GOTA-PC'));
        $pc->send(N3fjp::check(), N3fjp::chat('GOTA-PC', ''), N3fjp::who());
        $pc->read();
    });

    $bytes = implode('', $run->reads[0]);
    $terminator = mb_convert_encoding(N3fjp::TERMINATOR, 'UTF-16LE', 'UTF-8');

    expect(str_starts_with($bytes, $terminator))->toBeFalse()
        ->and(str_contains($bytes, $terminator.$terminator))->toBeFalse();
});

test('a client stuck behind a dialog does not hold up the others', function () {
    fdcContacts($this->config, 300, $this->qsoTime);

    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $stuck = $wire->connect();
        $stuck->send(...[...N3fjp::hello('STUCK-PC'), N3fjp::open(), N3fjp::open(), N3fjp::open()]);

        $cw = $wire->connect();
        $cw->send(N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime, 'CW-PC')));
        $cw->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 1, timeoutSeconds: 10);
    });

    expect(N3fjp::transactions($run->messages(1)))->toContain('ADD W1AW');
});

test('a stray non-N3FJP connection is dropped without disturbing a real client', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $stray = $wire->connect();
        $stray->write("GET / HTTP/1.1\r\nHost: fdc.local\r\n\r\n");
        $stray->write(str_repeat('A', 300_000));

        $pc = $wire->connect();
        $pc->send(N3fjp::add(N3fjp::fieldDayContact('W1AW', $this->qsoTime)));
        $pc->readUntilCount('<TRANSACTION>ADD</TRANSACTION>', 1);
        $stray->read(quietSeconds: 0.3);
    });

    expect(Contact::sole()->callsign)->toBe('W1AW')
        ->and($run->exitCode)->toBe(0);
});

test('a chat with tags or <EOR> in its text reaches the other clients as one clean message', function () {
    $run = n3fjpSession($this->config, function (LoggerWire $wire) {
        $a = $wire->connect();
        $b = $wire->connect();
        $a->read();
        $a->send(N3fjp::chat('GOTA-PC', 'need <NTWK> help on 40 <EOR> now'));
        $b->read(fn (array $m) => array_filter($m, fn (string $x) => str_contains($x, '<MESG>')) !== []);
    });

    $chats = array_values(array_filter($run->messages(1), fn (string $m) => str_contains($m, '<MESG>')));

    expect($chats)->toHaveCount(1)
        ->and(substr_count($chats[0], '<EOR>'))->toBe(1)
        ->and($chats[0])->toContain('<FROM>GOTA-PC</FROM>')
        ->and($chats[0])->not->toContain('<NTWK>')
        ->and($chats[0])->toContain('help on 40');
});
