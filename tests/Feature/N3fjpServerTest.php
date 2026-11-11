<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\Event as EventModel;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Services\ExternalContactHandler;
use App\Services\N3fjp\N3fjpContactMapper;
use App\Services\N3fjp\N3fjpLogSync;
use App\Services\N3fjp\N3fjpMessage;
use App\Services\N3fjp\N3fjpServer;
use Database\Seeders\BandSeeder;
use Database\Seeders\ModeSeeder;
use Database\Seeders\SectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    Event::fake();
    $this->seed([BandSeeder::class, ModeSeeder::class, SectionSeeder::class]);

    $this->config = EventConfiguration::factory()
        ->for(EventModel::factory()->state(['start_time' => null, 'end_time' => null]))
        ->create(['callsign' => 'W2XYZ']);
});

/**
 * A server for the test's event, booted.
 */
function n3fjpServer(EventConfiguration $config): N3fjpServer
{
    $mapper = app(N3fjpContactMapper::class);
    $server = new N3fjpServer($config, new N3fjpLogSync($config, $mapper), $mapper, app(ExternalContactHandler::class));
    $server->boot();

    return $server;
}

/**
 * An ADD a client sends for a contact entered at its keyboard.
 */
function n3fjpAdd(string $call, string $extra = ''): string
{
    return '<BOR><NTWK><FROM>LAPTOP-2</FROM><TRANSACTION>ADD</TRANSACTION><XMLDATA>'
        ."<FLDCALL>{$call}</FLDCALL><FLDBAND>20</FLDBAND><FLDMODE>USB</FLDMODE><FLDMODECONTEST>PH</FLDMODECONTEST>"
        .'<FLDCLASS>3A</FLDCLASS><FLDSECTION>CT</FLDSECTION><FLDDATESTR>2027/01/23</FLDDATESTR>'
        .'<FLDTIMEONSTR>18:00:00</FLDTIMEONSTR><FLDSTATION>LAPTOP-2</FLDSTATION><FLDPRIMARYKEY>99</FLDPRIMARYKEY>'
        .$extra.'</XMLDATA><EOR>';
}

/**
 * The transactions in an outbox, as [target, TRANSACTION, FLDCALL or data].
 *
 * @param  list<array{?int, string|list<string>}>  $outbox
 * @return list<array{?int, string, string}>
 */
function n3fjpTransactions(array $outbox): array
{
    $messages = [];

    foreach ($outbox as [$target, $message]) {
        foreach ((array) $message as $one) {
            $messages[] = [$target, $one];
        }
    }

    return array_map(function (array $item) {
        [$target, $message] = $item;
        $data = N3fjpMessage::extract($message, 'XMLDATA');
        $call = N3fjpMessage::extract($data, 'FLDCALL');

        return [$target, N3fjpMessage::extract($message, 'TRANSACTION'), $call !== '' ? $call : $data];
    }, $messages);
}

/**
 * A contact logged in Field Day Commander itself.
 */
function webContact(EventConfiguration $config, string $call): Contact
{
    $station = Station::factory()->create(['event_configuration_id' => $config->id, 'name' => 'Web Station']);

    return Contact::factory()->create([
        'event_configuration_id' => $config->id,
        'operating_session_id' => OperatingSession::factory()->create(['station_id' => $station->id, 'end_time' => now()])->id,
        'band_id' => Band::where('name', '40m')->value('id'),
        'mode_id' => Mode::where('name', 'CW')->value('id'),
        'callsign' => $call,
        'qso_time' => now()->subMinute(),
    ]);
}

test('saves a client contact and relays the saved record to every client', function () {
    $server = n3fjpServer($this->config);

    $server->receive(3, n3fjpAdd('W1AW'));
    $outbox = $server->drainOutbox();

    $contact = Contact::sole();
    expect($contact->callsign)->toBe('W1AW')
        ->and($contact->n3fjp_key)->toBe(1)
        ->and($contact->external_source)->toBe('n3fjp')
        ->and($contact->band->name)->toBe('20m')
        ->and($contact->mode->name)->toBe('Phone')
        ->and($contact->operatingSession->station->name)->toBe('LAPTOP-2')
        ->and(n3fjpTransactions($outbox))->toBe([[null, 'ADD', 'W1AW']])
        ->and($outbox[0][1])->toContain('<FLDPRIMARYKEY>1</FLDPRIMARYKEY>');
});

test('scores SSTV from an N3FJP client as digital', function () {
    $server = n3fjpServer($this->config);

    $server->receive(3, str_replace('<FLDMODE>USB</FLDMODE>', '<FLDMODE>SSTV</FLDMODE>', n3fjpAdd('W1AW')));
    $outbox = $server->drainOutbox();

    expect(Contact::sole()->mode->name)->toBe('Digital')
        ->and($outbox[0][1])->toContain('<FLDMODECONTEST>DIG</FLDMODECONTEST>');
});

test('numbers contacts in the order clients receive them', function () {
    $server = n3fjpServer($this->config);

    $server->receive(3, n3fjpAdd('W1AW'));
    webContact($this->config, 'K1ABC');
    $server->tick();
    $server->receive(4, n3fjpAdd('N1XYZ'));

    expect(Contact::orderBy('n3fjp_key')->pluck('callsign', 'n3fjp_key')->all())
        ->toBe([1 => 'W1AW', 2 => 'K1ABC', 3 => 'N1XYZ'])
        ->and(n3fjpTransactions($server->drainOutbox()))->toBe([
            [null, 'ADD', 'W1AW'],
            [null, 'ADD', 'K1ABC'],
            [null, 'ADD', 'N1XYZ'],
        ]);
});

test('numbers the existing log when it starts', function () {
    $older = webContact($this->config, 'K1ABC');
    $older->update(['qso_time' => now()->subHour()]);
    webContact($this->config, 'W1AW');

    $server = n3fjpServer($this->config);
    $server->receive(3, '<BOR><NTWK><OPEN>');

    expect(n3fjpTransactions($server->drainOutbox()))->toBe([
        [3, 'CLEAR', ''],
        [3, 'GET', 'K1ABC'],
        [3, 'GET', 'W1AW'],
        [3, 'LIST', ''],
    ]);
});

test('answers a log check with the record count and last number', function () {
    webContact($this->config, 'K1ABC');
    webContact($this->config, 'W1AW');
    $server = n3fjpServer($this->config);

    $server->receive(5, '<BOR><NTWK><CHECK>');

    expect($server->drainOutbox())->toBe([[5, N3fjpMessage::check(2, 2)]]);
});

test('applies an edit from a client and relays the saved record', function () {
    $server = n3fjpServer($this->config);
    $server->receive(3, n3fjpAdd('W1AW'));
    $server->drainOutbox();

    $server->connect(4, '192.168.1.24:50000');
    $server->receive(4, str_replace(
        ['ADD', 'W1AW', '<FROM>LAPTOP-2', '<FLDPRIMARYKEY>99'],
        ['EDIT', 'W1AX', '<FROM>LAPTOP-4', '<FLDPRIMARYKEY>1'],
        n3fjpAdd('W1AW'),
    ));

    expect(Contact::sole()->callsign)->toBe('W1AX')
        ->and(n3fjpTransactions($server->drainOutbox()))->toBe([[null, 'EDIT', 'W1AX']]);

    expect(AuditLog::where('action', 'contact.updated')->sole())
        ->ip_address->toBe('192.168.1.24')
        ->old_values->toBe(['callsign' => 'W1AW'])
        ->new_values->toBe([
            'callsign' => 'W1AX',
            'changed_by' => 'external_logger',
            'source' => 'n3fjp',
            'sender' => 'LAPTOP-4',
            'sender_ip' => '192.168.1.24',
        ]);

    $server->tick();
    expect($server->drainOutbox())->toBe([]);
});

test('deletes a contact a client deletes, and has clients recheck when it was the last', function () {
    $server = n3fjpServer($this->config);
    $server->receive(3, n3fjpAdd('W1AW'));
    $server->receive(3, n3fjpAdd('K1ABC'));
    $server->drainOutbox();

    $server->connect(4, '192.168.1.23:51234');
    $server->receive(4, '<BOR><NTWK><FROM>LAPTOP-3</FROM><TRANSACTION>DELETE</TRANSACTION><XMLDATA>2</XMLDATA><EOR>');

    expect(AuditLog::where('action', 'contact.deleted')->sole())
        ->ip_address->toBe('192.168.1.23')
        ->new_values->toMatchArray(['source' => 'n3fjp', 'sender' => 'LAPTOP-3', 'sender_ip' => '192.168.1.23']);

    expect(Contact::where('callsign', 'K1ABC')->withTrashed()->sole())
        ->trashed()->toBeTrue()
        ->n3fjp_key->toBeNull()
        ->and($server->drainOutbox())->toBe([
            [null, N3fjpMessage::transaction('DELETE', '2')],
            [null, N3fjpMessage::check(1, 1)],
        ]);

    $server->receive(3, n3fjpAdd('N1XYZ'));
    expect(Contact::where('callsign', 'N1XYZ')->value('n3fjp_key'))->toBe(2);
});

test('sends clients the edits and deletes made in Field Day Commander', function () {
    $edited = webContact($this->config, 'K1ABC');
    $deleted = webContact($this->config, 'W1AW');
    $server = n3fjpServer($this->config);

    $this->travel(2)->seconds();
    $edited->update(['callsign' => 'K1ABD']);
    $deleted->delete();
    $server->tick();

    expect(n3fjpTransactions($server->drainOutbox()))->toBe([
        [null, 'DELETE', '2'],
        [null, 'EDIT', 'K1ABD'],
        [null, 'CHECK', '<COUNT>1</COUNT><LAST>1</LAST>'],
    ]);
});

test('sends a full reload instead of a flood of edits', function () {
    foreach (range(1, N3fjpLogSync::RELOAD_THRESHOLD + 1) as $i) {
        webContact($this->config, "K{$i}AA");
    }
    $server = n3fjpServer($this->config);

    $this->travel(2)->seconds();
    Contact::query()->update(['points' => 5, 'updated_at' => now()]);
    $server->tick();
    $outbox = n3fjpTransactions($server->drainOutbox());

    expect($outbox[0])->toBe([null, 'CLEAR', ''])
        ->and(end($outbox))->toBe([null, 'LIST', ''])
        ->and(collect($outbox)->where(1, 'EDIT'))->toBeEmpty();
});

test('tells only the sender why a contact was rejected', function () {
    $this->config->event->update(['start_time' => '2027-01-24 19:00:00', 'end_time' => '2027-01-25 18:59:59']);
    $server = n3fjpServer($this->config->fresh());

    $server->receive(3, n3fjpAdd('W1AW'));
    $outbox = $server->drainOutbox();

    expect(Contact::count())->toBe(0)
        ->and($outbox)->toHaveCount(1)
        ->and($outbox[0][0])->toBe(3)
        ->and(N3fjpMessage::extract($outbox[0][1], 'TO'))->toBe('LAPTOP-2')
        ->and(N3fjpMessage::extract($outbox[0][1], 'MSGTXT'))->toContain('outside the event');
});

test('relays chat and band status, answers who, and drops clock sync', function () {
    $server = n3fjpServer($this->config);
    $bams = '<BOR><BAMS><STATION>LAPTOP-2</STATION><BAND>40</BAND><MODE>CW</MODE><EOR>';
    $chat = '<BOR><MESG><TO></TO><FROM>LAPTOP-2</FROM><MSGTXT>QRV 40 CW</MSGTXT><EOR>';

    $server->receive(3, $bams);
    $server->receive(3, $chat);
    $server->receive(3, '<BOR><SCLK><YEAR>1999</YEAR><MONTH>1</MONTH><DAY>1</DAY><HOUR>0</HOUR><MINUTE>0</MINUTE><SECOND>0</SECOND><MILLISECOND>0</MILLISECOND><EOR>');
    $server->receive(4, '<BOR><WHO><EOR>');

    expect($server->drainOutbox())->toBe([
        [null, $bams],
        [null, $chat],
        [4, $bams],
    ]);
});

test('shows Field Day Commander stations in the N3FJP band status display', function () {
    $session = fn (string $name, string $band, string $mode, array $extra = []) => OperatingSession::factory()->create([
        'station_id' => Station::factory()->create(['event_configuration_id' => $this->config->id, 'name' => $name])->id,
        'band_id' => Band::where('name', $band)->value('id'),
        'mode_id' => Mode::where('name', $mode)->value('id'),
        'end_time' => null,
        ...$extra,
    ]);
    $session('Station 1', '20m', 'Phone');
    $session('VHF Station', '70cm', 'Digital');
    $session('Old Station', '40m', 'CW', ['end_time' => now()]);
    $session('Paper Log', '15m', 'CW', ['is_transcription' => true]);
    $session('LAPTOP-2', '10m', 'CW', ['external_source' => 'n3fjp']);

    $server = n3fjpServer($this->config);
    $server->tick();

    expect($server->drainOutbox())->toEqualCanonicalizing([
        [null, N3fjpMessage::bandAndMode('Station 1', '20', 'PH')],
        [null, N3fjpMessage::bandAndMode('VHF Station', '70', 'DIG')],
    ]);

    $server->tick();
    expect($server->drainOutbox())->toBe([]);

    $server->receive(4, '<BOR><WHO><EOR>');
    expect($server->drainOutbox())->toHaveCount(2);
});
