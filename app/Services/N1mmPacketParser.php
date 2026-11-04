<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Support\AdvancedContactFields;
use Illuminate\Support\Carbon;

class N1mmPacketParser
{
    public function __construct(private BandResolverService $bands = new BandResolverService) {}

    /**
     * Packets N1MM broadcasts that carry nothing to log. Matched on the root
     * element before parsing, because some are not valid XML: dynamicresults
     * is built by hand without escaping, so a club name with "&" breaks it.
     *
     * @var array<string>
     */
    private const IGNORED_TYPES = [
        'lookupinfo', 'appinfo', 'spot', 'dynamicresults', 'rtc', 'spectrum',
        'n1mmrotor', 'applicationcolors', 'radiocmd', 'spectrumcmd', 'deletelog',
    ];

    /**
     * Packets worth recovering when they arrive damaged. N1MM sizes each
     * datagram by characters rather than bytes, so a name or comment with
     * accented letters can cut the end off the XML.
     *
     * @var array<string>
     */
    private const RECOVERABLE_TYPES = ['contactinfo', 'contactreplace', 'contactdelete', 'radioinfo'];

    /**
     * N1MM's "no date" (DateTime 1900-01-01), sent as oldtimestamp on some packets.
     */
    private const NO_DATE = '1900-01-01 00:00:00';

    /**
     * Parse a raw N1MM XML packet into a DTO.
     */
    public function parse(string $xml): ExternalContactDto|ExternalRadioInfoDto|null
    {
        if ($this->isIgnoredPacket($xml)) {
            return null;
        }

        $doc = $this->load($xml);

        if ($doc === null) {
            return null;
        }

        $rootTag = strtolower($doc->getName());

        return match ($rootTag) {
            'contactinfo' => $this->parseContact($doc, isReplace: false, isDelete: false),
            'contactreplace' => $this->parseContact($doc, isReplace: true, isDelete: false),
            'contactdelete' => $this->parseContactDelete($doc),
            'radioinfo' => $this->parseRadioInfo($doc),
            default => null,
        };
    }

    /**
     * Whether a packet is one N1MM sends that carries nothing to log, so a
     * listener need not warn when it doesn't parse.
     */
    public function isIgnoredPacket(string $xml): bool
    {
        return in_array($this->rootName($xml), self::IGNORED_TYPES, true);
    }

    /**
     * The lower-case name of a packet's root element, read without parsing.
     */
    private function rootName(string $xml): ?string
    {
        $body = preg_replace('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml[^>]*\?>)?/', '', $xml) ?? '';

        return preg_match('/^\s*<([A-Za-z]\w*)/', $body, $match) === 1 ? strtolower($match[1]) : null;
    }

    /**
     * Parse a packet, recovering what libxml can from a contact or radio
     * packet whose end was cut off.
     */
    private function load(string $xml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $doc = simplexml_load_string($xml);

            if ($doc !== false) {
                return $doc;
            }

            if (! in_array($this->rootName($xml), self::RECOVERABLE_TYPES, true)) {
                return null;
            }

            $dom = new \DOMDocument;
            $dom->recover = true;

            if (! @$dom->loadXML($xml, LIBXML_NONET) || $dom->documentElement === null) {
                return null;
            }

            $recovered = simplexml_import_dom($dom);

            return $recovered !== null && $this->survivedTruncation($recovered) ? $recovered : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Whether a recovered packet kept what it needs. N1MM cuts packets from
     * the end, by one byte per extra UTF-8 byte in the text, so a contact
     * keeps its call and time (near the start) unless almost all of it is
     * gone. A long non-ASCII comment can take the ID (near the end) with
     * it; such a contact is still kept, and found by its call and time.
     */
    private function survivedTruncation(\SimpleXMLElement $doc): bool
    {
        if (strtolower($doc->getName()) === 'radioinfo') {
            return $this->extractString($doc, 'Freq') !== null;
        }

        return $this->extractString($doc, 'call') !== null && $this->extractString($doc, 'timestamp') !== null;
    }

    /**
     * The station that logged the contact. A PC relaying a QSO it received
     * over N1MM's network sends its own name as StationName; the PC that
     * logged it is in NetBiosName, with IsOriginal false.
     */
    private function loggingStation(\SimpleXMLElement $doc): ?string
    {
        $station = $this->decodedString($doc, 'StationName');
        $origin = $this->decodedString($doc, 'NetBiosName');

        if ($origin !== null && strtolower((string) $this->extractString($doc, 'IsOriginal')) === 'false') {
            return $origin;
        }

        return $station;
    }

    /**
     * A text field N1MM also HTML-encodes (StationName), decoded.
     */
    private function decodedString(\SimpleXMLElement $doc, string $tag): ?string
    {
        $value = $this->extractString($doc, $tag);

        return $value !== null ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
    }

    private function parseContact(\SimpleXMLElement $doc, bool $isReplace, bool $isDelete): ExternalContactDto
    {
        $operator = $this->extractString($doc, 'operator');
        $frequencyHz = $this->convertN1mmFrequency($this->extractString($doc, 'rxfreq'));

        // N1MM's own fields, read through the same checks as an ADIF record.
        ['fields' => $advancedFields] = AdvancedContactFields::fromAdif([
            'FREQ' => $frequencyHz !== null ? (string) ($frequencyHz / 1_000_000) : null,
            'RST_SENT' => $this->extractString($doc, 'snt'),
            'RST_RCVD' => $this->extractString($doc, 'rcv'),
            'GRIDSQUARE' => $this->extractString($doc, 'gridsquare'),
            'NAME' => $this->extractString($doc, 'name'),
            'COMMENT' => $this->extractString($doc, 'comment'),
        ]);

        return new ExternalContactDto(
            callsign: strtoupper(trim((string) $doc->call)),
            timestamp: $this->qsoTime($doc),
            source: 'n1mm',
            bandName: $this->bandName($this->extractString($doc, 'band')),
            modeName: $this->extractString($doc, 'mode'),
            operatorCallsign: $operator !== null ? strtoupper($operator) : null,
            stationIdentifier: $this->loggingStation($doc),
            frequencyHz: $frequencyHz,
            sentReport: $this->extractString($doc, 'snt'),
            sentExchange: $this->extractString($doc, 'sntnr'),
            receivedReport: $this->extractString($doc, 'rcv'),
            receivedExchange: $this->extractString($doc, 'rcvnr'),
            exchangeClass: $this->extractString($doc, 'exchange1'),
            sectionCode: $this->extractString($doc, 'section'),
            externalId: $this->extractString($doc, 'ID'),
            isDelete: $isDelete,
            isReplace: $isReplace,
            oldCallsign: $this->extractString($doc, 'oldcall'),
            oldTimestamp: $this->parseTimestamp($this->extractString($doc, 'oldtimestamp')),
            advancedFields: $advancedFields,
        );
    }

    private function parseContactDelete(\SimpleXMLElement $doc): ExternalContactDto
    {
        return new ExternalContactDto(
            callsign: strtoupper(trim((string) $doc->call)),
            timestamp: $this->qsoTime($doc),
            source: 'n1mm',
            stationIdentifier: $this->decodedString($doc, 'StationName'),
            externalId: $this->extractString($doc, 'ID'),
            isDelete: true,
        );
    }

    private function parseRadioInfo(\SimpleXMLElement $doc): ExternalRadioInfoDto
    {
        $opCall = $this->extractString($doc, 'OpCall');

        return new ExternalRadioInfoDto(
            stationIdentifier: (string) $this->decodedString($doc, 'StationName'),
            source: 'n1mm',
            operatorCallsign: $opCall !== null ? strtoupper($opCall) : null,
            frequencyHz: $this->convertN1mmFrequency($this->extractString($doc, 'Freq')),
            modeName: $this->extractString($doc, 'Mode'),
            isTransmitting: strtolower((string) $doc->IsTransmitting) === 'true',
        );
    }

    /**
     * Convert N1MM frequency (in 10 Hz units) to Hz. N1MM sends 0 when it
     * has no frequency, which is no frequency rather than 0 Hz.
     */
    private function convertN1mmFrequency(?string $value): ?int
    {
        if ($value === null || ! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value * 10;
    }

    /**
     * Field Day Commander's name for N1MM's band. N1MM sends the band as its
     * lower edge in MHz ("1.8", "14", "144", "1296"), written with the PC's
     * decimal separator ("3,5"). A band already named in meters ("20m") is
     * kept. Null when the band is blank or in no band Field Day Commander
     * knows, so the frequency decides.
     */
    private function bandName(?string $band): ?string
    {
        if ($band === null) {
            return null;
        }

        $band = str_replace(' ', '', $band);

        if (preg_match('/^\d+(?:[.,]\d+)?$/', $band) === 1) {
            return $this->bands->nameForFrequencyHz((int) round((float) str_replace(',', '.', $band) * 1_000_000));
        }

        return preg_match('/^[\d.]+(?:m|cm|mm)$/i', $band) === 1 ? strtolower($band) : null;
    }

    private function extractString(\SimpleXMLElement $doc, string $tag): ?string
    {
        if (! isset($doc->{$tag})) {
            return null;
        }

        $value = trim((string) $doc->{$tag});

        return $value === '' ? null : $value;
    }

    /**
     * The QSO time. N1MM sends UTC as "yyyy-MM-dd HH:mm:ss" with no zone.
     */
    private function qsoTime(\SimpleXMLElement $doc): Carbon
    {
        return $this->parseTimestamp($this->extractString($doc, 'timestamp')) ?? Carbon::now('UTC');
    }

    private function parseTimestamp(?string $value): ?Carbon
    {
        if ($value === null || $value === self::NO_DATE) {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC');
        } catch (\Exception) {
            return null;
        }
    }
}
