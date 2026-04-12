<?php

use App\Exceptions\OutOfPeriodContactException;
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
    Band::firstOrCreate(['name' => '40m'], ['meters' => 40, 'frequency_mhz' => 7.0, 'sort_order' => 6]);
    Mode::firstOrCreate(['name' => 'Digital'], ['category' => 'Digital', 'points_fd' => 2, 'points_wfd' => 2]);
    Mode::firstOrCreate(['name' => 'SSB'], ['category' => 'Phone', 'points_fd' => 1, 'points_wfd' => 1]);
    Section::firstOrCreate(['code' => 'CT'], ['name' => 'Connecticut', 'region' => 'W1', 'is_active' => true]);
    Section::firstOrCreate(['code' => 'NLI'], ['name' => 'New York City-Long Island', 'region' => 'W2', 'is_active' => true]);
    Section::firstOrCreate(['code' => 'NH'], ['name' => 'New Hampshire', 'region' => 'W1', 'is_active' => true]);

    $this->adifParser = new AdifParserService;
    $this->mapper = new AdifContactMapper;
    $this->handler = app(ExternalContactHandler::class);
});

test('full pipeline: plain ADIF text to contact creation', function () {
    $adifText = '<call:4>W1AW <gridsquare:4>FN31 <mode:4>MFSK <submode:3>FT8 <rst_sent:3>-10 <rst_rcvd:3>-12 '
        .'<qso_date:8>20260628 <time_on:6>184300 <qso_date_off:8>20260628 <time_off:6>184330 '
        .'<band:3>20m <freq:6>14.076 <station_callsign:5>K3CPK <operator:5>K3CPK '
        .'<contest_id:14>ARRL-FIELD-DAY <SRX_STRING:5>3A CT <arrl_sect:2>CT <EOR>';

    // Step 1: Parse plain ADIF text (no binary wrapper)
    $parsed = $this->adifParser->parse($adifText);
    expect($parsed['records'])->toHaveCount(1);

    // Step 2: Map ADIF tags to DTO with 'udp-adif' source
    $dto = $this->mapper->map($parsed['records'][0], 'udp-adif');
    expect($dto)->not->toBeNull()
        ->and($dto->source)->toBe('udp-adif');

    // Step 3: Create contact
    $contact = $this->handler->handleContact($dto, $this->config);

    expect($contact->callsign)->toBe('W1AW')
        ->and($contact->band->name)->toBe('20m')
        ->and($contact->mode->name)->toBe('Digital')
        ->and($contact->section->code)->toBe('CT')
        ->and($contact->external_source)->toBe('udp-adif')
        ->and($contact->external_id)->toHaveLength(32)
        ->and($contact->logger_user_id)->toBe($this->user->id)
        ->and($contact->operatingSession->station_id)->toBe($this->station->id);
});

test('full pipeline: multiple records in single packet', function () {
    $adifText = '<call:4>W1AW <mode:4>MFSK <submode:3>FT8 <qso_date:8>20260628 <time_on:6>184300 '
        .'<band:3>20m <freq:6>14.076 <station_callsign:5>K3CPK <operator:5>K3CPK '
        .'<SRX_STRING:5>3A CT <arrl_sect:2>CT <EOR>'
        .'<call:5>N2ABC <mode:3>SSB <qso_date:8>20260628 <time_on:6>190000 '
        .'<band:3>40m <freq:5>7.200 <station_callsign:5>K3CPK <operator:5>K3CPK '
        .'<SRX_STRING:6>2A NLI <arrl_sect:3>NLI <EOR>';

    $parsed = $this->adifParser->parse($adifText);
    expect($parsed['records'])->toHaveCount(2);

    $contacts = [];
    foreach ($parsed['records'] as $tags) {
        $dto = $this->mapper->map($tags, 'udp-adif');
        expect($dto)->not->toBeNull();
        $contacts[] = $this->handler->handleContact($dto, $this->config);
    }

    expect($contacts)->toHaveCount(2)
        ->and($contacts[0]->callsign)->toBe('W1AW')
        ->and($contacts[0]->band->name)->toBe('20m')
        ->and($contacts[1]->callsign)->toBe('N2ABC')
        ->and($contacts[1]->band->name)->toBe('40m')
        ->and($contacts[1]->section->code)->toBe('NLI');
});

test('full pipeline: ADIF with header is parsed correctly', function () {
    $adifText = "<adif_ver:5>3.0.7\n<programid:5>FLDGI\n<EOH>\n"
        .'<call:4>W1AW <mode:4>MFSK <submode:3>FT8 <qso_date:8>20260628 <time_on:6>184300 '
        .'<band:3>20m <freq:6>14.076 <station_callsign:5>K3CPK <operator:5>K3CPK '
        .'<SRX_STRING:5>3A CT <arrl_sect:2>CT <EOR>';

    $parsed = $this->adifParser->parse($adifText);
    expect($parsed['records'])->toHaveCount(1)
        ->and($parsed['header']['PROGRAMID'])->toBe('FLDGI');

    $dto = $this->mapper->map($parsed['records'][0], 'udp-adif');
    $contact = $this->handler->handleContact($dto, $this->config);

    expect($contact->callsign)->toBe('W1AW')
        ->and($contact->external_source)->toBe('udp-adif');
});

test('full pipeline: duplicate plain ADIF packet is idempotent', function () {
    $adifText = '<call:4>W1AW <mode:4>MFSK <submode:3>FT8 <qso_date:8>20260628 <time_on:6>184300 '
        .'<band:3>20m <freq:6>14.076 <station_callsign:5>K3CPK <operator:5>K3CPK '
        .'<SRX_STRING:5>3A CT <arrl_sect:2>CT <EOR>';

    // First pass
    $parsed = $this->adifParser->parse($adifText);
    $dto = $this->mapper->map($parsed['records'][0], 'udp-adif');
    $this->handler->handleContact($dto, $this->config);

    // Second pass (same data)
    $parsed2 = $this->adifParser->parse($adifText);
    $dto2 = $this->mapper->map($parsed2['records'][0], 'udp-adif');
    $this->handler->handleContact($dto2, $this->config);

    expect(Contact::where('external_id', $dto->externalId)->count())->toBe(1);
});

test('refreshing config picks up updated event window', function () {
    // Set a narrow event window that excludes the QSO time (18:43 UTC)
    $event = $this->config->event;
    $event->update([
        'start_time' => '2026-06-28 20:00:00',
        'end_time' => '2026-06-29 20:00:00',
    ]);

    // Load the event relationship so it's cached on the model
    $this->config->load('event');

    $adifText = '<call:4>W1AW <mode:4>MFSK <submode:3>FT8 <qso_date:8>20260628 <time_on:6>184300 '
        .'<band:3>20m <freq:6>14.076 <station_callsign:5>K3CPK <operator:5>K3CPK '
        .'<SRX_STRING:5>3A CT <arrl_sect:2>CT <EOR>';

    $parsed = $this->adifParser->parse($adifText);
    $dto = $this->mapper->map($parsed['records'][0], 'udp-adif');

    // QSO at 18:43 is outside the 20:00–20:00 window → rejected
    expect(fn () => $this->handler->handleContact($dto, $this->config))
        ->toThrow(OutOfPeriodContactException::class);

    // Simulate what happens when the user moves the start time earlier in the UI
    $event->update(['start_time' => '2026-06-28 18:00:00']);

    // Without refresh, the stale cached event still rejects
    expect(fn () => $this->handler->handleContact($dto, $this->config))
        ->toThrow(OutOfPeriodContactException::class);

    // After refresh (what the listener now does each heartbeat), the QSO is accepted
    $this->config->unsetRelation('event');
    $this->config->refresh();

    $contact = $this->handler->handleContact($dto, $this->config);
    expect($contact->callsign)->toBe('W1AW');
});

test('full pipeline: CLASS and ARRL_SECT compose received_exchange when SRX_STRING absent', function () {
    $adifText = '<CALL:4>W1AX<MODE:3>PSK<SUBMODE:5>PSK31<FREQ:9>14.071500<BAND:3>20m'
        .'<QSO_DATE:8>20260412<TIME_ON:4>0142<QSO_DATE_OFF:8>20260412<TIME_OFF:4>0142'
        .'<STX_STRING:0><CLASS:2>2B<ARRL_SECT:2>NH<OPERATOR:5>K3CPK<STATION_CALLSIGN:5>K3CPK<EOR>';

    $parsed = $this->adifParser->parse($adifText);
    expect($parsed['records'])->toHaveCount(1);

    $dto = $this->mapper->map($parsed['records'][0], 'udp-adif');
    expect($dto)->not->toBeNull()
        ->and($dto->receivedExchange)->toBe('2B NH');

    $contact = $this->handler->handleContact($dto, $this->config);
    expect($contact->received_exchange)->toBe('2B NH')
        ->and($contact->section->code)->toBe('NH');
});

test('WSJTX binary packet is detectable by magic number', function () {
    // Build a WSJTX binary packet
    $magic = pack('N', 0xADBCCBDA);
    $binaryPacket = $magic.pack('N', 3).pack('N', 12).pack('N', 6).'WSJT-X';

    // Check for WSJTX magic number in first 4 bytes
    $firstFourBytes = substr($binaryPacket, 0, 4);
    $magicValue = unpack('N', $firstFourBytes)[1];

    expect($magicValue)->toBe(0xADBCCBDA);

    // Plain ADIF text should NOT match
    $adifText = '<call:4>W1AW <mode:3>FT8 <qso_date:8>20260628 <time_on:6>184300 <band:3>20m <freq:6>14.076 <EOR>';
    $adifFirstFour = substr($adifText, 0, 4);

    // Plain text won't unpack to the magic number
    $adifMagic = unpack('N', $adifFirstFour)[1];
    expect($adifMagic)->not->toBe(0xADBCCBDA);
});
