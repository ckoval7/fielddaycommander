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
use Tests\Support\ExternalLoggers\LoggerWire;
use Tests\Support\ExternalLoggers\N1mm;
use Tests\Support\ExternalLoggers\WireHarness;
use Tests\Support\ExternalLoggers\WireRun;

/*
 * N1MM Logger+ broadcasts into the N1MM listener over a real socket, built
 * and cut the way N1MM's own send code does it.
 */

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    Event::fake();
    $this->seed([BandSeeder::class, ModeSeeder::class, SectionSeeder::class]);

    $this->config = EventConfiguration::factory()->create(['is_active' => true, 'callsign' => 'W2XYZ']);
    $this->qsoTime = $this->config->event->start_time->copy()->addHours(2)->setTime(20, 4, 31);
});

/**
 * @param  (callable(LoggerWire): void)|list<string>  $script  Datagrams to send back to back, or a script
 */
function n1mmSends(EventConfiguration $config, callable|array $script): WireRun
{
    $logger = is_array($script) ? fn (LoggerWire $wire) => $wire->burst($script) : $script;

    return WireHarness::run('external-logger:n1mm', $config, 'n1mm', 'udp', $logger);
}

test('a Field Day CW contact is logged with everything N1MM sends', function () {
    $contact = N1mm::fieldDayContact('W1AW', $this->qsoTime);

    $run = n1mmSends($this->config, [N1mm::contactInfo($contact)]);

    $stored = Contact::sole();

    expect($run->exitCode)->toBe(0)
        ->and($stored->callsign)->toBe('W1AW')
        ->and($stored->qso_time->format('Y-m-d H:i:s'))->toBe($this->qsoTime->format('Y-m-d H:i:s'))
        ->and($stored->band->name)->toBe('20m')
        ->and($stored->mode->name)->toBe('CW')
        ->and($stored->frequency_hz)->toBe(14_025_300)
        ->and($stored->exchange_class)->toBe('2A')
        ->and($stored->section->code)->toBe('CT')
        ->and($stored->external_id)->toBe($contact['ID'])
        ->and($stored->logger->call_sign)->toBe('K3CPK')
        ->and($stored->operatingSession->station->name)->toBe('SHACK-PC');
});

test('a comment with accented letters, which makes N1MM cut the end off the packet, still logs the contact', function () {
    $contact = N1mm::fieldDayContact('VE2ABC', $this->qsoTime, ['section' => 'QC', 'comment' => 'Très bon opérateur à Montréal']);
    $datagram = N1mm::contactInfo($contact);

    expect($datagram)->not->toEndWith('</contactinfo>');

    n1mmSends($this->config, [$datagram]);

    $stored = Contact::sole();

    expect($stored->external_id)->toBe($contact['ID'])
        ->and($stored->notes)->toBe('Très bon opérateur à Montréal')
        ->and($stored->section->code)->toBe('QC');
});

test('a contact whose packet lost its trailing fields to truncation can still be edited and deleted', function () {
    $contact = N1mm::fieldDayContact('JA1ABC', $this->qsoTime, ['section' => 'DX', 'comment' => '日本からこんにちは、良いフィールドデー']);

    n1mmSends($this->config, [
        N1mm::contactInfo($contact),
        N1mm::contactReplace([...$contact, 'call' => 'JA1ABD', 'comment' => '']),
    ]);

    expect(Contact::sole()->callsign)->toBe('JA1ABD');

    n1mmSends($this->config, [N1mm::contactDelete([...$contact, 'call' => 'JA1ABD'])]);

    expect(Contact::count())->toBe(0)
        ->and(Contact::withTrashed()->count())->toBe(1);
});

test('a comment with &, < and > arrives escaped and is stored as typed', function () {
    n1mmSends($this->config, [N1mm::contactInfo(N1mm::fieldDayContact('W1AW', $this->qsoTime, ['comment' => 'QRP & <5W> "portable"']))]);

    expect(Contact::sole()->notes)->toBe('QRP & <5W> "portable"');
});

test('a band written with a comma on a German-locale PC lands on the right band', function () {
    n1mmSends($this->config, [N1mm::contactInfo(N1mm::fieldDayContact('DL1ABC', $this->qsoTime, [
        'band' => 3.5, 'freq_khz' => 3525.0, 'txfreq_khz' => 3525.0, 'culture' => 'de-DE', 'section' => 'DX',
    ]))]);

    expect(Contact::sole()->band->name)->toBe('80m');
});

test('an edit, sent as one contactreplace with the old call, updates the contact in place', function () {
    $contact = N1mm::fieldDayContact('K5ZB', $this->qsoTime);

    n1mmSends($this->config, function (LoggerWire $wire) use ($contact) {
        $wire->datagram(N1mm::contactInfo($contact));
        $wire->pause(200);
        $wire->datagram(N1mm::contactReplace([...$contact, 'call' => 'K5ZD', 'exchange1' => '3A'], 'K5ZB', $contact['timestamp']));
    });

    $stored = Contact::sole();

    expect($stored->callsign)->toBe('K5ZD')
        ->and($stored->exchange_class)->toBe('3A');
});

test('a contact logged on one networked PC and relayed by another is stored once, at the PC that logged it', function () {
    $contact = N1mm::fieldDayContact('W1AW', $this->qsoTime, ['NetBiosName' => 'CW-PC', 'StationName' => 'CW-PC']);

    n1mmSends($this->config, [
        N1mm::contactInfo($contact),
        N1mm::relayedContactInfo($contact, 'PHONE-PC'),
        N1mm::relayedContactInfo($contact, 'GOTA-PC'),
    ]);

    $stored = Contact::sole();

    expect($stored->operatingSession->station->name)->toBe('CW-PC')
        ->and(Station::where('name', 'PHONE-PC')->exists())->toBeFalse();
});

test('a relayed re-edit, with an empty old call and the 1900 "no date", does not duplicate or blank the contact', function () {
    $contact = N1mm::fieldDayContact('W1AW', $this->qsoTime);
    $edited = [...$contact, 'exchange1' => '5A'];

    n1mmSends($this->config, [
        N1mm::contactInfo($contact),
        N1mm::contactReplace($edited),
        N1mm::datagram(str_replace(
            ['<oldcall>W1AW</oldcall>', '<oldtimestamp>'.$this->qsoTime->format('Y-m-d H:i:s').'</oldtimestamp>'],
            ['<oldcall></oldcall>', '<oldtimestamp>1900-01-01 00:00:00</oldtimestamp>'],
            N1mm::contactReplace($edited),
        )),
    ]);

    $stored = Contact::sole();

    expect($stored->callsign)->toBe('W1AW')
        ->and($stored->exchange_class)->toBe('5A')
        ->and($stored->qso_time->format('H:i:s'))->toBe('20:04:31');
});

test('a delete relayed by another networked PC under a new id still deletes the contact', function () {
    $contact = N1mm::fieldDayContact('W1AW', $this->qsoTime);

    n1mmSends($this->config, [
        N1mm::contactInfo($contact),
        N1mm::contactDelete([...$contact, 'ID' => N1mm::id(), 'StationName' => 'PHONE-PC']),
    ]);

    expect(Contact::count())->toBe(0);
});

test('the radio, lookup, app and score traffic around a contact logs nothing and warns about nothing', function () {
    Log::spy();
    $contact = N1mm::fieldDayContact('W1AW', $this->qsoTime);

    n1mmSends($this->config, [
        N1mm::appInfo(),
        N1mm::radioInfo('SHACK-PC', 14025.3, 'CW'),
        N1mm::radioInfo('SHACK-PC', 14025.3, 'CW', transmitting: true),
        N1mm::lookupInfo($contact),
        N1mm::radioInfo('SHACK-PC', 14025.3, 'CW'),
        N1mm::dynamicResults(),
    ]);

    expect(Contact::count())->toBe(0);
    Log::shouldNotHaveReceived('warning');
});

test('Tools > Rebroadcast contest QSOs, paced 50 ms apart as N1MM sends it, delivers every QSO', function () {
    $contacts = [];

    foreach (range(1, 60) as $i) {
        $contacts[] = N1mm::fieldDayContact("K{$i}AA", $this->qsoTime->copy()->addMinutes($i));
    }

    $run = n1mmSends($this->config, function (LoggerWire $wire) use ($contacts) {
        foreach ($contacts as $contact) {
            $wire->pause(25);
            $wire->datagram(N1mm::contactReplace($contact));
            $wire->pause(25);
        }
    });

    expect($run->drops)->toBe(0)
        ->and(Contact::count())->toBe(60);
});

test('three networked PCs rebroadcasting at once to a Pi-speed server lose no QSOs', function () {
    // Each PC paces its rebroadcast 50 ms apart, as N1MM does; together
    // that is a packet every ~17 ms. Saving takes ~40 ms on a loaded Pi,
    // so packets pile up far past the kernel's socket buffer.
    Event::fakeExcept(['eloquent.creating: '.Contact::class]);
    Contact::creating(fn () => usleep(40_000));

    $pcs = ['CW-PC', 'PHONE-PC', 'DIGI-PC'];
    $rounds = 50;

    $run = n1mmSends($this->config, function (LoggerWire $wire) use ($pcs, $rounds) {
        foreach (range(1, $rounds) as $round) {
            foreach ($pcs as $index => $pc) {
                $wire->datagram(N1mm::contactReplace(N1mm::fieldDayContact("K{$round}{$pc[0]}A", $this->qsoTime->copy()->addSeconds($round * 30 + $index), [
                    'NetBiosName' => $pc,
                    'StationName' => $pc,
                    'comment' => str_repeat('Field Day contact comment ', 30),
                ])));
                $wire->pause(17);
            }
        }
    });

    expect($run->drops)->toBe(0)
        ->and(Contact::count())->toBe($rounds * count($pcs));
});

test('an operator who keeps the radio info running but logs with a blank mode gets the mode from the radio', function () {
    $contact = N1mm::fieldDayContact('W1AW', $this->qsoTime, ['mode' => '', 'freq_khz' => 0.0, 'txfreq_khz' => 0.0, 'band' => 0.0]);

    n1mmSends($this->config, [
        N1mm::radioInfo('SHACK-PC', 7040.0, 'USB'),
        N1mm::contactInfo($contact),
    ]);

    $stored = Contact::sole();

    expect($stored->mode->name)->toBe('Phone')
        ->and($stored->band->name)->toBe('40m');
});

test('a multi-line comment keeps its lines', function () {
    n1mmSends($this->config, [N1mm::contactInfo(N1mm::fieldDayContact('W1AW', $this->qsoTime, ['comment' => "line one\nline two"]))]);

    expect(Contact::sole()->notes)->toBe("line one\nline two");
});

test('a delete that overtakes the contactinfo it deletes keeps the QSO deleted', function () {
    $contact = N1mm::fieldDayContact('W1AW', $this->qsoTime);

    n1mmSends($this->config, [N1mm::contactDelete($contact), N1mm::contactInfo($contact)]);

    expect(Contact::withTrashed()->count())->toBe(0);
});

test('a contactinfo that arrives after the edit of its QSO does not undo the edit', function () {
    $contact = N1mm::fieldDayContact('K5ZB', $this->qsoTime);

    n1mmSends($this->config, [
        N1mm::contactReplace([...$contact, 'call' => 'K5ZD'], 'K5ZB', $contact['timestamp']),
        N1mm::contactInfo($contact),
        N1mm::relayedContactInfo($contact, 'PHONE-PC'),
    ]);

    expect(Contact::sole()->callsign)->toBe('K5ZD');
});

test('a contact whose long comment cost it its ID is kept once, then edited and deleted by its ID', function () {
    $comment = str_repeat('日本からこんにちは、良いフィールドデーを', 8);
    $contact = N1mm::fieldDayContact('JA1ABC', $this->qsoTime, ['section' => 'DX', 'comment' => $comment]);

    expect(N1mm::contactInfo($contact))->not->toContain('<ID>');

    n1mmSends($this->config, [
        N1mm::contactInfo($contact),
        N1mm::relayedContactInfo($contact, 'PHONE-PC'),
    ]);

    $stored = Contact::sole();

    expect($stored->external_id)->toBeNull()
        ->and($stored->section->code)->toBe('DX');

    n1mmSends($this->config, [N1mm::contactReplace([...$contact, 'call' => 'JA1ABD', 'comment' => 'short'], 'JA1ABC', $contact['timestamp'])]);

    expect(Contact::sole()->callsign)->toBe('JA1ABD')
        ->and(Contact::sole()->external_id)->toBe($contact['ID']);

    n1mmSends($this->config, [N1mm::contactDelete([...$contact, 'call' => 'JA1ABD'])]);

    expect(Contact::count())->toBe(0);
});
