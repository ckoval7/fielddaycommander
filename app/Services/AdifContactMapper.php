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
     */
    public function map(array $tags, string $source): ?ExternalContactDto
    {
        $call = trim($tags['CALL'] ?? '');
        $qsoDate = $tags['QSO_DATE'] ?? '';
        $timeOn = $tags['TIME_ON'] ?? '';

        if ($call === '' || $qsoDate === '' || $timeOn === '') {
            return null;
        }

        $callsign = strtoupper($call);
        $timestamp = Carbon::createFromFormat('Ymd His', $qsoDate.' '.str_pad($timeOn, 6, '0'), 'UTC');

        $freq = $tags['FREQ'] ?? '';
        $frequencyHz = $freq !== ''
            ? (int) round((float) $freq * 1_000_000)
            : null;

        $operator = trim($tags['OPERATOR'] ?? '');

        $receivedExchange = trim($tags['SRX_STRING'] ?? $tags['SRX'] ?? '');
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
            bandName: $tags['BAND'] ?? null,
            modeName: $tags['SUBMODE'] ?? $tags['MODE'] ?? null,
            operatorCallsign: $operator !== '' ? strtoupper($operator) : null,
            stationIdentifier: $tags['STATION_CALLSIGN'] ?? null,
            frequencyHz: $frequencyHz,
            sentReport: $tags['RST_SENT'] ?? null,
            sentExchange: $tags['STX_STRING'] ?? $tags['STX'] ?? null,
            receivedReport: $tags['RST_RCVD'] ?? null,
            receivedExchange: $receivedExchange !== '' ? $receivedExchange : null,
            sectionCode: $tags['ARRL_SECT'] ?? null,
            externalId: $externalId,
            isDelete: false,
            isReplace: false,
            parentModeName: $tags['MODE'] ?? null,
            advancedFields: $advancedFields,
            adifFields: $adifFields !== [] ? $adifFields : null,
        );
    }
}
