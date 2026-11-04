<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\DTOs\ExternalContactDto;
use App\Support\AdvancedContactFields;
use Illuminate\Support\Carbon;

class AdifContactMapper
{
    /**
     * Map ADIF tag-value pairs to an ExternalContactDto.
     *
     * @param  array<string, string>  $tags  Uppercased ADIF tag names as keys
     * @param  string  $source  The source identifier (e.g. 'wsjtx', 'fldigi')
     * @param  string|null  $stationIdentifier  The station that logged it, when the sender knows better than STATION_CALLSIGN
     */
    public function map(array $tags, string $source, ?string $stationIdentifier = null): ?ExternalContactDto
    {
        $call = trim($tags['CALL'] ?? '');
        $qsoDate = trim($tags['QSO_DATE'] ?? '');
        $timeOn = trim($tags['TIME_ON'] ?? '');
        $timestamp = $this->qsoTime($qsoDate, $timeOn);

        if ($call === '' || $timestamp === null) {
            return null;
        }

        $callsign = strtoupper($call);

        $freq = $tags['FREQ'] ?? '';
        $frequencyHz = $freq !== ''
            ? (int) round((float) $freq * 1_000_000)
            : null;

        $operator = trim($tags['OPERATOR'] ?? '');

        $receivedExchange = $this->filled($tags, 'SRX_STRING') ?? $this->filled($tags, 'SRX') ?? '';
        if ($receivedExchange === '') {
            $class = trim($tags['CLASS'] ?? '');
            $sect = trim($tags['ARRL_SECT'] ?? '');
            if ($class !== '' && $sect !== '') {
                $receivedExchange = $class.' '.$sect;
            }
        }

        // NOSONAR php:S4790 - md5 here is a non-cryptographic dedup key, not a
        // security primitive. The digest is persisted to contacts.external_id
        // (string(32)); changing the algorithm would orphan every previously
        // imported contact and re-import them as duplicates.
        $externalId = md5($callsign.$qsoDate.$timeOn.$freq);

        ['fields' => $advancedFields, 'extra' => $adifFields] = AdvancedContactFields::fromAdif($tags);

        return new ExternalContactDto(
            callsign: $callsign,
            timestamp: $timestamp,
            source: $source,
            bandName: $this->filled($tags, 'BAND'),
            modeName: $this->filled($tags, 'SUBMODE') ?? $this->filled($tags, 'MODE'),
            operatorCallsign: $operator !== '' ? strtoupper($operator) : null,
            stationIdentifier: $stationIdentifier ?? $this->filled($tags, 'STATION_CALLSIGN'),
            frequencyHz: $frequencyHz,
            sentReport: $this->filled($tags, 'RST_SENT'),
            sentExchange: $this->filled($tags, 'STX_STRING') ?? $this->filled($tags, 'STX'),
            receivedReport: $this->filled($tags, 'RST_RCVD'),
            receivedExchange: $receivedExchange !== '' ? $receivedExchange : null,
            sectionCode: $this->filled($tags, 'ARRL_SECT'),
            externalId: $externalId,
            isDelete: false,
            isReplace: false,
            parentModeName: $this->filled($tags, 'MODE'),
            advancedFields: $advancedFields,
            adifFields: $adifFields !== [] ? $adifFields : null,
        );
    }

    /**
     * The QSO time from ADIF's QSO_DATE (YYYYMMDD) and TIME_ON (HHMM or
     * HHMMSS), or null when either is missing or not a real date and time,
     * so one bad record never takes the rest of a packet with it.
     */
    private function qsoTime(string $qsoDate, string $timeOn): ?Carbon
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $qsoDate, $date) !== 1
            || preg_match('/^([01]\d|2[0-3])([0-5]\d)([0-5]\d)?$/', $timeOn, $time) !== 1
            || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            return null;
        }

        return Carbon::createFromFormat('Ymd His', $qsoDate.' '.str_pad($timeOn, 6, '0'), 'UTC');
    }

    /**
     * A tag's trimmed value, or null when it is missing or blank. fldigi
     * sends every ticked field, so blank tags are common.
     *
     * @param  array<string, string>  $tags
     */
    private function filled(array $tags, string $tag): ?string
    {
        $value = trim($tags[$tag] ?? '');

        return $value !== '' ? $value : null;
    }
}
