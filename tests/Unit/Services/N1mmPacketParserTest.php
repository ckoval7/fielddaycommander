<?php

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Services\N1mmPacketParser;

beforeEach(function () {
    $this->parser = new N1mmPacketParser;
});

test('parses contactinfo packet into ExternalContactDto', function () {
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

    $result = $this->parser->parse($xml);

    expect($result)->toBeInstanceOf(ExternalContactDto::class)
        ->and($result->callsign)->toBe('W1AW')
        ->and($result->timestamp->toDateTimeString())->toBe('2026-06-28 18:43:38')
        ->and($result->modeName)->toBe('CW')
        ->and($result->operatorCallsign)->toBe('K3CPK')
        ->and($result->stationIdentifier)->toBe('CONTEST-PC')
        ->and($result->frequencyHz)->toBe(3525190)
        ->and($result->sectionCode)->toBe('CT')
        ->and($result->externalId)->toBe('f9ffac4fcd3e479ca86e137df1338531')
        ->and($result->sentReport)->toBe('599')
        ->and($result->source)->toBe('n1mm')
        ->and($result->isDelete)->toBeFalse()
        ->and($result->isReplace)->toBeFalse();
});

test('parses contactreplace packet with isReplace flag', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <contactreplace>
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
        <StationName>CONTEST-PC</StationName>
        <ID>f9ffac4fcd3e479ca86e137df1338531</ID>
        <oldtimestamp>2026-06-28 18:40:00</oldtimestamp>
        <oldcall>W1AX</oldcall>
    </contactreplace>';

    $result = $this->parser->parse($xml);

    expect($result)->toBeInstanceOf(ExternalContactDto::class)
        ->and($result->isReplace)->toBeTrue()
        ->and($result->isDelete)->toBeFalse()
        ->and($result->oldCallsign)->toBe('W1AX')
        ->and($result->oldTimestamp->toDateTimeString())->toBe('2026-06-28 18:40:00');
});

test('parses contactdelete packet with isDelete flag', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <contactdelete>
        <app>N1MM</app>
        <timestamp>2026-06-28 18:43:38</timestamp>
        <mycall>W2XYZ</mycall>
        <band>3.5</band>
        <call>W1AW</call>
        <contestnr>73</contestnr>
        <StationName>CONTEST-PC</StationName>
        <ID>f9ffac4fcd3e479ca86e137df1338531</ID>
    </contactdelete>';

    $result = $this->parser->parse($xml);

    expect($result)->toBeInstanceOf(ExternalContactDto::class)
        ->and($result->isDelete)->toBeTrue()
        ->and($result->isReplace)->toBeFalse()
        ->and($result->callsign)->toBe('W1AW')
        ->and($result->externalId)->toBe('f9ffac4fcd3e479ca86e137df1338531');
});

test('parses RadioInfo packet into ExternalRadioInfoDto', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <RadioInfo>
        <app>N1MM</app>
        <StationName>CW-80m</StationName>
        <RadioNr>1</RadioNr>
        <Freq>352211</Freq>
        <TXFreq>352211</TXFreq>
        <Mode>CW</Mode>
        <mycall>W1ABC</mycall>
        <OpCall>K3CPK</OpCall>
        <IsRunning>False</IsRunning>
        <IsTransmitting>False</IsTransmitting>
    </RadioInfo>';

    $result = $this->parser->parse($xml);

    expect($result)->toBeInstanceOf(ExternalRadioInfoDto::class)
        ->and($result->stationIdentifier)->toBe('CW-80m')
        ->and($result->operatorCallsign)->toBe('K3CPK')
        ->and($result->frequencyHz)->toBe(3522110)
        ->and($result->modeName)->toBe('CW')
        ->and($result->isTransmitting)->toBeFalse()
        ->and($result->source)->toBe('n1mm');
});

test('converts N1MM frequency (10 Hz units) to Hz', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <contactinfo>
        <app>N1MM</app>
        <timestamp>2026-06-28 18:00:00</timestamp>
        <mycall>W2XYZ</mycall>
        <band>20</band>
        <rxfreq>1420000</rxfreq>
        <txfreq>1420000</txfreq>
        <operator></operator>
        <mode>SSB</mode>
        <call>W1AW</call>
        <snt>59</snt>
        <sntnr>0</sntnr>
        <rcv>59</rcv>
        <rcvnr>0</rcvnr>
        <section></section>
        <StationName>PC</StationName>
        <ID>abc123</ID>
        <oldtimestamp>2026-06-28 18:00:00</oldtimestamp>
        <oldcall>W1AW</oldcall>
    </contactinfo>';

    $result = $this->parser->parse($xml);

    // 1420000 * 10 = 14200000 Hz = 14.200 MHz
    expect($result->frequencyHz)->toBe(14200000);
});

test('returns null for lookupinfo packets', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <lookupinfo>
        <app>N1MM</app>
        <call>W1AW</call>
    </lookupinfo>';

    expect($this->parser->parse($xml))->toBeNull();
});

test('returns null for AppInfo packets', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <AppInfo>
        <app>N1MM</app>
        <dbname>test.s3db</dbname>
    </AppInfo>';

    expect($this->parser->parse($xml))->toBeNull();
});

test('returns null for malformed XML', function () {
    expect($this->parser->parse('not xml at all'))->toBeNull();
    expect($this->parser->parse('<broken><unclosed>'))->toBeNull();
});

test('handles empty operator field', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <contactinfo>
        <app>N1MM</app>
        <timestamp>2026-06-28 18:00:00</timestamp>
        <mycall>W2XYZ</mycall>
        <band>20</band>
        <rxfreq>1420000</rxfreq>
        <txfreq>1420000</txfreq>
        <operator></operator>
        <mode>SSB</mode>
        <call>W1AW</call>
        <snt>59</snt>
        <sntnr>0</sntnr>
        <rcv>59</rcv>
        <rcvnr>0</rcvnr>
        <section>CT</section>
        <StationName>PC</StationName>
        <ID>abc123</ID>
        <oldtimestamp>2026-06-28 18:00:00</oldtimestamp>
        <oldcall>W1AW</oldcall>
    </contactinfo>';

    $result = $this->parser->parse($xml);

    expect($result->operatorCallsign)->toBeNull();
});

test('handles localized band decimal separator', function () {
    $xml = '<?xml version="1.0" encoding="utf-8"?>
    <contactinfo>
        <app>N1MM</app>
        <timestamp>2026-06-28 18:00:00</timestamp>
        <mycall>W2XYZ</mycall>
        <band>3,5</band>
        <rxfreq>352519</rxfreq>
        <txfreq>352519</txfreq>
        <operator></operator>
        <mode>CW</mode>
        <call>W1AW</call>
        <snt>599</snt>
        <sntnr>5</sntnr>
        <rcv>599</rcv>
        <rcvnr>0</rcvnr>
        <section></section>
        <StationName>PC</StationName>
        <ID>abc123</ID>
        <oldtimestamp>2026-06-28 18:00:00</oldtimestamp>
        <oldcall>W1AW</oldcall>
    </contactinfo>';

    $result = $this->parser->parse($xml);

    // Frequency is the reliable source
    expect($result->frequencyHz)->toBe(3525190);
});
