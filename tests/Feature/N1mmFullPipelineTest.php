<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\OperatingSession;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\ExternalContactHandler;
use App\Services\N1mmPacketParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(
        ['key' => 'setup_completed', 'value' => 'true'],
    );

    Event::fake();

    $this->config = EventConfiguration::factory()->create(['callsign' => 'W2XYZ']);
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'CONTEST-PC',
    ]);
    $this->user = User::factory()->create(['call_sign' => 'K3CPK']);

    Band::firstOrCreate(['name' => '80m'], ['meters' => 80, 'frequency_mhz' => 3.5, 'sort_order' => 2]);
    Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    Mode::firstOrCreate(['name' => 'CW'], ['category' => 'CW', 'points_fd' => 2, 'points_wfd' => 2]);
    Mode::firstOrCreate(['name' => 'Phone'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->parser = new N1mmPacketParser;
    $this->handler = app(ExternalContactHandler::class);
});

test('full pipeline: raw XML to contact creation', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <contactinfo>
        <app>N1MM</app>
        <contestname>ARRL-FIELD-DAY</contestname>
        <timestamp>2026-06-28 18:43:38</timestamp>
        <mycall>W2XYZ</mycall>
        <band>3.5</band>
        <rxfreq>352519</rxfreq>
        <txfreq>352519</txfreq>
        <operator>K3CPK</operator>
        <mode>CW</mode>
        <call>W1AW</call>
        <snt>599</snt>
        <sntnr>5</sntnr>
        <rcv>599</rcv>
        <rcvnr>0</rcvnr>
        <section>CT</section>
        <StationName>CONTEST-PC</StationName>
        <ID>f9ffac4fcd3e479ca86e137df1338531</ID>
        <oldtimestamp>2026-06-28 18:43:38</oldtimestamp>
        <oldcall>W1AW</oldcall>
    </contactinfo>';

    $dto = $this->parser->parse($xml);
    $contact = $this->handler->handleContact($dto, $this->config);

    expect($contact->callsign)->toBe('W1AW')
        ->and($contact->band->name)->toBe('80m')
        ->and($contact->mode->name)->toBe('CW')
        ->and($contact->section->code)->toBe('CT')
        ->and($contact->n1mm_id)->toBe('f9ffac4fcd3e479ca86e137df1338531')
        ->and($contact->external_source)->toBe('n1mm')
        ->and($contact->logger_user_id)->toBe($this->user->id)
        ->and($contact->operatingSession->station_id)->toBe($this->station->id);
});

test('full pipeline: contact then replace updates callsign', function () {
    $xmlCreate = '<?xml version="1.0" encoding="utf-8"?>
    <contactinfo>
        <app>N1MM</app>
        <timestamp>2026-06-28 18:43:38</timestamp>
        <mycall>W2XYZ</mycall>
        <band>3.5</band>
        <rxfreq>352519</rxfreq>
        <txfreq>352519</txfreq>
        <operator>K3CPK</operator>
        <mode>CW</mode>
        <call>W1AX</call>
        <snt>599</snt>
        <sntnr>5</sntnr>
        <rcv>599</rcv>
        <rcvnr>0</rcvnr>
        <section>CT</section>
        <StationName>CONTEST-PC</StationName>
        <ID>replace_test_id</ID>
        <oldtimestamp>2026-06-28 18:43:38</oldtimestamp>
        <oldcall>W1AX</oldcall>
    </contactinfo>';

    $xmlReplace = '<?xml version="1.0" encoding="utf-8"?>
    <contactreplace>
        <app>N1MM</app>
        <timestamp>2026-06-28 18:43:38</timestamp>
        <mycall>W2XYZ</mycall>
        <band>3.5</band>
        <rxfreq>352519</rxfreq>
        <txfreq>352519</txfreq>
        <operator>K3CPK</operator>
        <mode>CW</mode>
        <call>W1AW</call>
        <snt>599</snt>
        <sntnr>5</sntnr>
        <rcv>599</rcv>
        <rcvnr>0</rcvnr>
        <section>CT</section>
        <StationName>CONTEST-PC</StationName>
        <ID>replace_test_id</ID>
        <oldtimestamp>2026-06-28 18:43:38</oldtimestamp>
        <oldcall>W1AX</oldcall>
    </contactreplace>';

    $createDto = $this->parser->parse($xmlCreate);
    $this->handler->handleContact($createDto, $this->config);

    $replaceDto = $this->parser->parse($xmlReplace);
    $this->handler->handleReplace($replaceDto, $this->config);

    $contact = Contact::where('n1mm_id', 'replace_test_id')->first();
    expect($contact->callsign)->toBe('W1AW')
        ->and(Contact::where('n1mm_id', 'replace_test_id')->count())->toBe(1);
});

test('full pipeline: contact then delete soft-deletes', function () {
    $xmlCreate = '<?xml version="1.0" encoding="utf-8"?>
    <contactinfo>
        <app>N1MM</app>
        <timestamp>2026-06-28 18:43:38</timestamp>
        <mycall>W2XYZ</mycall>
        <band>3.5</band>
        <rxfreq>352519</rxfreq>
        <txfreq>352519</txfreq>
        <operator>K3CPK</operator>
        <mode>CW</mode>
        <call>W1AW</call>
        <snt>599</snt>
        <sntnr>5</sntnr>
        <rcv>599</rcv>
        <rcvnr>0</rcvnr>
        <section>CT</section>
        <StationName>CONTEST-PC</StationName>
        <ID>delete_test_id</ID>
        <oldtimestamp>2026-06-28 18:43:38</oldtimestamp>
        <oldcall>W1AW</oldcall>
    </contactinfo>';

    $xmlDelete = '<?xml version="1.0" encoding="utf-8"?>
    <contactdelete>
        <app>N1MM</app>
        <timestamp>2026-06-28 18:43:38</timestamp>
        <mycall>W2XYZ</mycall>
        <band>3.5</band>
        <call>W1AW</call>
        <contestnr>1</contestnr>
        <StationName>CONTEST-PC</StationName>
        <ID>delete_test_id</ID>
    </contactdelete>';

    $createDto = $this->parser->parse($xmlCreate);
    $this->handler->handleContact($createDto, $this->config);

    $deleteDto = $this->parser->parse($xmlDelete);
    $this->handler->handleDelete($deleteDto, $this->config);

    expect(Contact::where('n1mm_id', 'delete_test_id')->first())->toBeNull()
        ->and(Contact::withTrashed()->where('n1mm_id', 'delete_test_id')->first())->not->toBeNull();
});

test('full pipeline: RadioInfo creates session before contacts', function () {
    $xmlRadio = '<?xml version="1.0" encoding="utf-8"?>
    <RadioInfo>
        <app>N1MM</app>
        <StationName>CONTEST-PC</StationName>
        <RadioNr>1</RadioNr>
        <Freq>1420000</Freq>
        <TXFreq>1420000</TXFreq>
        <Mode>USB</Mode>
        <mycall>W2XYZ</mycall>
        <OpCall>K3CPK</OpCall>
        <IsRunning>True</IsRunning>
        <IsTransmitting>False</IsTransmitting>
    </RadioInfo>';

    $dto = $this->parser->parse($xmlRadio);
    $this->handler->handleRadioInfo($dto, $this->config);

    $session = OperatingSession::where('station_id', $this->station->id)
        ->whereNull('end_time')
        ->first();

    expect($session)->not->toBeNull()
        ->and($session->band->name)->toBe('20m')
        ->and($session->mode->name)->toBe('Phone')
        ->and($session->operator_user_id)->toBe($this->user->id);
});

test('full pipeline: auto-creates station for unknown StationName', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <contactinfo>
        <app>N1MM</app>
        <timestamp>2026-06-28 18:43:38</timestamp>
        <mycall>W2XYZ</mycall>
        <band>3.5</band>
        <rxfreq>352519</rxfreq>
        <txfreq>352519</txfreq>
        <operator></operator>
        <mode>CW</mode>
        <call>W1AW</call>
        <snt>599</snt>
        <sntnr>5</sntnr>
        <rcv>599</rcv>
        <rcvnr>0</rcvnr>
        <section>CT</section>
        <StationName>NEW-LAPTOP</StationName>
        <ID>newstation123</ID>
        <oldtimestamp>2026-06-28 18:43:38</oldtimestamp>
        <oldcall>W1AW</oldcall>
    </contactinfo>';

    $dto = $this->parser->parse($xml);
    $this->handler->handleContact($dto, $this->config);

    $station = Station::where('name', 'NEW-LAPTOP')->first();
    expect($station)->not->toBeNull()
        ->and($station->hostname)->toBe('NEW-LAPTOP')
        ->and($station->event_configuration_id)->toBe($this->config->id);
});
