<?php

namespace App\Services;

use App\DTOs\ExternalContactDto;
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
        $timestamp = Carbon::createFromFormat('Ymd His', $qsoDate.' '.$timeOn, 'UTC');

        $freq = $tags['FREQ'] ?? '';
        $frequencyHz = $freq !== ''
            ? (int) round((float) $freq * 1_000_000)
            : null;

        $operator = trim($tags['OPERATOR'] ?? '');

        $externalId = md5($callsign.$qsoDate.$timeOn.$freq);

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
            receivedExchange: $tags['SRX_STRING'] ?? $tags['SRX'] ?? null,
            sectionCode: $tags['ARRL_SECT'] ?? null,
            externalId: $externalId,
            isDelete: false,
            isReplace: false,
        );
    }
}
