<?php

use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Section;
use App\Models\Station;
use App\Models\User;
use App\Services\AdifContactMapper;
use App\Services\AdifParserService;
use App\Services\ExternalContactHandler;
use App\Services\WsjtxPacketParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('system_config')->insert(['key' => 'setup_completed', 'value' => 'true']);
    Event::fake();

    $this->config = EventConfiguration::factory()
        ->for(App\Models\Event::factory()->state(['start_time' => null, 'end_time' => null]))
        ->create(['callsign' => 'W2XYZ']);
    $this->station = Station::factory()->create([
        'event_configuration_id' => $this->config->id,
        'name' => 'K3CPK',
    ]);
    $this->user = User::factory()->create(['call_sign' => 'K3CPK']);

    Band::firstOrCreate(['name' => '20m'], ['meters' => 20, 'frequency_mhz' => 14.0, 'sort_order' => 4]);
    Mode::firstOrCreate(['name' => 'Digital'], ['category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
    Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);

    $this->wsjtxParser = new WsjtxPacketParser;
    $this->adifParser = new AdifParserService;
    $this->mapper = new AdifContactMapper;
    $this->handler = app(ExternalContactHandler::class);
});

function buildTestPacket(string $adifText): string
{
    $magic = pack('N', 0xADBCCBDA);
    $schema = pack('N', 3);
    $type = pack('N', 12);
    $id = pack('N', 6).'WSJT-X';
    $adif = pack('N', strlen($adifText)).$adifText;

    return $magic.$schema.$type.$id.$adif;
}

test('full pipeline: binary packet to contact creation', function () {
    $adifText = "<adif_ver:5>3.0.7\n<programid:6>WSJT-X\n<EOH>\n"
        .'<call:4>W1AW <gridsquare:4>FN31 <mode:4>MFSK <submode:3>FT8 <rst_sent:3>-10 <rst_rcvd:3>-12 '
        .'<qso_date:8>20260628 <time_on:6>184300 <qso_date_off:8>20260628 <time_off:6>184330 '
        .'<band:3>20m <freq:6>14.076 <station_callsign:5>K3CPK <operator:5>K3CPK '
        .'<contest_id:14>ARRL-FIELD-DAY <SRX_STRING:5>3A CT <arrl_sect:2>CT <EOR>';

    $packet = buildTestPacket($adifText);

    // Step 1: Parse binary WSJTX packet
    $rawAdif = $this->wsjtxParser->parse($packet);
    expect($rawAdif)->toBeString();

    // Step 2: Parse ADIF text
    $parsed = $this->adifParser->parse($rawAdif);
    expect($parsed['records'])->toHaveCount(1);

    // Step 3: Map ADIF tags to DTO
    $dto = $this->mapper->map($parsed['records'][0], 'wsjtx');
    expect($dto)->not->toBeNull();

    // Step 4: Create contact
    $contact = $this->handler->handleContact($dto, $this->config);

    expect($contact->callsign)->toBe('W1AW')
        ->and($contact->band->name)->toBe('20m')
        ->and($contact->mode->name)->toBe('Digital')
        ->and($contact->section->code)->toBe('CT')
        ->and($contact->external_source)->toBe('wsjtx')
        ->and($contact->external_id)->toHaveLength(32)
        ->and($contact->logger_user_id)->toBe($this->user->id)
        ->and($contact->operatingSession->station_id)->toBe($this->station->id);
});

test('full pipeline: duplicate WSJTX packet is idempotent', function () {
    $adifText = "<adif_ver:5>3.0.7\n<programid:6>WSJT-X\n<EOH>\n"
        .'<call:4>W1AW <gridsquare:4>FN31 <mode:4>MFSK <submode:3>FT8 <rst_sent:3>-10 <rst_rcvd:3>-12 '
        .'<qso_date:8>20260628 <time_on:6>184300 <qso_date_off:8>20260628 <time_off:6>184330 '
        .'<band:3>20m <freq:6>14.076 <station_callsign:5>K3CPK <operator:5>K3CPK '
        .'<contest_id:14>ARRL-FIELD-DAY <SRX_STRING:5>3A CT <arrl_sect:2>CT <EOR>';

    $packet = buildTestPacket($adifText);

    // First pass through the pipeline
    $rawAdif = $this->wsjtxParser->parse($packet);
    $parsed = $this->adifParser->parse($rawAdif);
    $dto = $this->mapper->map($parsed['records'][0], 'wsjtx');
    $this->handler->handleContact($dto, $this->config);

    // Second pass through the pipeline (same packet)
    $rawAdif2 = $this->wsjtxParser->parse($packet);
    $parsed2 = $this->adifParser->parse($rawAdif2);
    $dto2 = $this->mapper->map($parsed2['records'][0], 'wsjtx');
    $this->handler->handleContact($dto2, $this->config);

    expect(Contact::where('external_id', $dto->externalId)->count())->toBe(1);
});

test('full pipeline: non-ADIF WSJTX packets are ignored', function () {
    // Build a type 1 (Status) packet
    $magic = pack('N', 0xADBCCBDA);
    $schema = pack('N', 3);
    $type = pack('N', 1);
    $id = pack('N', 6).'WSJT-X';
    $packet = $magic.$schema.$type.$id;

    $result = $this->wsjtxParser->parse($packet);

    expect($result)->toBeNull();
});
