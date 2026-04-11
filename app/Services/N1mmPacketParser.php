<?php

namespace App\Services;

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use Illuminate\Support\Carbon;

class N1mmPacketParser
{
    /** @var array<string> */
    private const IGNORED_TYPES = ['lookupinfo', 'appinfo', 'spot', 'dynamicresults', 'spectrum'];

    /**
     * Parse a raw N1MM XML packet into a DTO.
     */
    public function parse(string $xml): ExternalContactDto|ExternalRadioInfoDto|null
    {
        try {
            $doc = @new \SimpleXMLElement($xml);
        } catch (\Exception) {
            return null;
        }

        $rootTag = strtolower($doc->getName());

        if (in_array($rootTag, self::IGNORED_TYPES, true)) {
            return null;
        }

        return match ($rootTag) {
            'contactinfo' => $this->parseContact($doc, isReplace: false, isDelete: false),
            'contactreplace' => $this->parseContact($doc, isReplace: true, isDelete: false),
            'contactdelete' => $this->parseContactDelete($doc),
            'radioinfo' => $this->parseRadioInfo($doc),
            default => null,
        };
    }

    private function parseContact(\SimpleXMLElement $doc, bool $isReplace, bool $isDelete): ExternalContactDto
    {
        $operator = $this->extractString($doc, 'operator');

        return new ExternalContactDto(
            callsign: strtoupper(trim((string) $doc->call)),
            timestamp: Carbon::parse((string) $doc->timestamp),
            source: 'n1mm',
            bandName: $this->extractString($doc, 'band'),
            modeName: $this->extractString($doc, 'mode'),
            operatorCallsign: $operator !== null ? strtoupper($operator) : null,
            stationIdentifier: $this->extractString($doc, 'StationName'),
            frequencyHz: $this->convertN1mmFrequency($this->extractString($doc, 'rxfreq')),
            sentReport: $this->extractString($doc, 'snt'),
            sentExchange: $this->extractString($doc, 'sntnr'),
            receivedReport: $this->extractString($doc, 'rcv'),
            receivedExchange: $this->extractString($doc, 'rcvnr'),
            sectionCode: $this->extractString($doc, 'section'),
            externalId: $this->extractString($doc, 'ID'),
            isDelete: $isDelete,
            isReplace: $isReplace,
            oldCallsign: $this->extractString($doc, 'oldcall'),
            oldTimestamp: $this->parseTimestamp($this->extractString($doc, 'oldtimestamp')),
        );
    }

    private function parseContactDelete(\SimpleXMLElement $doc): ExternalContactDto
    {
        return new ExternalContactDto(
            callsign: strtoupper(trim((string) $doc->call)),
            timestamp: Carbon::parse((string) $doc->timestamp),
            source: 'n1mm',
            stationIdentifier: $this->extractString($doc, 'StationName'),
            externalId: $this->extractString($doc, 'ID'),
            isDelete: true,
        );
    }

    private function parseRadioInfo(\SimpleXMLElement $doc): ExternalRadioInfoDto
    {
        $opCall = $this->extractString($doc, 'OpCall');

        return new ExternalRadioInfoDto(
            stationIdentifier: (string) $doc->StationName,
            source: 'n1mm',
            operatorCallsign: $opCall !== null ? strtoupper($opCall) : null,
            frequencyHz: $this->convertN1mmFrequency($this->extractString($doc, 'Freq')),
            modeName: $this->extractString($doc, 'Mode'),
            isTransmitting: strtolower((string) $doc->IsTransmitting) === 'true',
        );
    }

    /**
     * Convert N1MM frequency (in 10 Hz units) to Hz.
     */
    private function convertN1mmFrequency(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value * 10;
    }

    private function extractString(\SimpleXMLElement $doc, string $tag): ?string
    {
        if (! isset($doc->{$tag})) {
            return null;
        }

        $value = trim((string) $doc->{$tag});

        return $value === '' ? null : $value;
    }

    private function parseTimestamp(?string $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }
}
