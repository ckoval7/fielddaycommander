<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

use App\DTOs\ExternalContactDto;
use App\Models\Contact;
use App\Services\BandResolverService;
use App\Support\AdvancedContactFields;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Converts between N3FJP contact records and Field Day Commander contacts.
 *
 * An N3FJP record is a run of `<FLDNAME>value</FLDNAME>` tags (the columns of
 * N3FJP's tblContacts), with empty fields left out. ARRL Field Day and Winter
 * Field Day Contest Log fill the same fields.
 */
class N3fjpContactMapper
{
    /**
     * Source recorded on contacts that arrive from N3FJP clients.
     */
    public const SOURCE = 'n3fjp';

    /**
     * N3FJP band names that differ from Field Day Commander's. Other N3FJP
     * bands are a number of meters ("40") or already match ("6mm").
     *
     * @var array<string, string>
     */
    private const BAND_NAMES = [
        '70' => '70cm',
        '33' => '33cm',
        '23' => '23cm',
        '13' => '13cm',
        '9' => '9cm',
        '6CM' => '5cm',
        '3' => '3cm',
        'SAT' => 'Satellite',
    ];

    /**
     * Field Day Commander mode groups and N3FJP's names for them.
     *
     * @var array<string, string>
     */
    private const MODE_GROUPS = [
        'CW' => 'CW',
        'Phone' => 'PH',
        'Digital' => 'DIG',
    ];

    public function __construct(private BandResolverService $bandResolver) {}

    /**
     * The fields of a record, keyed by upper-case name without the FLD prefix.
     *
     * @return array<string, string>
     */
    public function parseRecord(string $record): array
    {
        preg_match_all('/<FLD([A-Z0-9]+)>(.*?)<\/FLD\1>/is', $record, $matches, PREG_SET_ORDER);

        $fields = [];
        foreach ($matches as [, $name, $value]) {
            $fields[strtoupper($name)] ??= trim($value);
        }

        return $fields;
    }

    /**
     * The contact a client sent, for the external contact handler.
     *
     * The mode group is worked out from the mode itself rather than taken
     * from N3FJP's FLDMODECONTEST, which files SSTV under phone.
     *
     * @param  array<string, string>  $fields
     */
    public function toDto(array $fields, string $sentFrom, ?string $externalId = null): ExternalContactDto
    {
        $bandName = $this->fdcBandName($fields['BAND'] ?? '');
        $frequencyHz = $this->frequencyHz($fields['FREQUENCY'] ?? '', $bandName);
        [$modeName, $parentModeName] = $this->modeNames($fields['MODE'] ?? '', $fields['MODECONTEST'] ?? '');
        $operator = strtoupper($fields['OPERATOR'] ?? '');
        $power = $fields['POWER'] ?? '';

        ['fields' => $advancedFields] = AdvancedContactFields::fromAdif([
            'FREQ' => $frequencyHz !== null ? (string) ($frequencyHz / 1_000_000) : null,
            'RST_SENT' => $fields['RSTS'] ?? null,
            'RST_RCVD' => $fields['RSTR'] ?? null,
            'GRIDSQUARE' => $fields['GRIDR'] ?? null,
            'NAME' => $fields['NAMER'] ?? null,
            'COMMENT' => $fields['COMMENTS'] ?? null,
        ]);

        return new ExternalContactDto(
            callsign: strtoupper($fields['CALL'] ?? ''),
            timestamp: $this->timestamp($fields['DATESTR'] ?? '', $fields['TIMEONSTR'] ?? ''),
            source: self::SOURCE,
            bandName: $bandName,
            modeName: $modeName,
            operatorCallsign: preg_match('/^[A-Z0-9\/]{3,20}$/', $operator) === 1 ? $operator : null,
            stationIdentifier: $this->stationIdentifier($fields, $sentFrom),
            frequencyHz: $frequencyHz,
            exchangeClass: ($fields['CLASS'] ?? '') !== '' ? strtoupper($fields['CLASS']) : null,
            sectionCode: ($fields['SECTION'] ?? '') !== '' ? strtoupper($fields['SECTION']) : null,
            externalId: $externalId,
            parentModeName: $parentModeName,
            advancedFields: $advancedFields,
            adifFields: is_numeric($power) && (float) $power > 0 ? ['TX_PWR' => $power] : null,
        );
    }

    /**
     * A contact as an N3FJP record, numbered $key.
     *
     * Expects band, mode, section and operatingSession.station/operator loaded.
     */
    public function toRecord(Contact $contact, int $key): string
    {
        $qsoTime = $contact->qso_time->copy()->utc();
        $group = $this->modeGroup($contact->mode?->name);
        $session = $contact->operatingSession;
        $station = $session?->station;

        $fields = [
            'CALL' => $contact->callsign,
            'BAND' => $this->n3fjpBandName($contact->band?->name),
            'MODE' => $contact->submode ?? $group,
            'MODECONTEST' => $group,
            'CLASS' => $contact->exchange_class,
            'SECTION' => $contact->section?->code,
            'DATESTR' => $qsoTime->format('Y/m/d'),
            'TIMEONSTR' => $qsoTime->format('H:i:s'),
            'TIMEOFFSTR' => $qsoTime->format('H:i:s'),
            'FREQUENCY' => $contact->frequency_hz !== null ? sprintf('%.5F', $contact->frequency_hz / 1_000_000) : null,
            'OPERATOR' => $session?->operator?->call_sign,
            'STATION' => $station?->name,
            'COMPUTERNAME' => $station?->hostname ?? $station?->name,
            'POINTS' => (string) (int) $contact->points,
            'POWER' => $contact->power_watts !== null ? (string) $contact->power_watts : null,
            'RSTS' => $contact->rst_sent,
            'RSTR' => $contact->rst_received,
            'GRIDR' => $contact->grid_square,
            'NAMER' => $contact->contact_name,
            'COMMENTS' => $contact->notes,
            'PRIMARYKEY' => (string) $key,
        ];

        $record = '';
        foreach ($fields as $name => $value) {
            $value = N3fjpMessage::clean($value);

            if ($value !== '') {
                $record .= "<FLD{$name}>{$value}</FLD{$name}>";
            }
        }

        return $record;
    }

    /**
     * N3FJP's name for a Field Day Commander mode group (CW, PH or DIG).
     */
    public function modeGroup(?string $mode): string
    {
        return self::MODE_GROUPS[$mode] ?? '';
    }

    /**
     * Field Day Commander's name for an N3FJP band, or null when blank.
     */
    public function fdcBandName(string $band): ?string
    {
        $band = strtoupper(trim($band));

        if ($band === '') {
            return null;
        }

        if (isset(self::BAND_NAMES[$band])) {
            return self::BAND_NAMES[$band];
        }

        return is_numeric($band) ? $band.'m' : strtolower($band);
    }

    /**
     * N3FJP's name for a Field Day Commander band.
     */
    public function n3fjpBandName(?string $band): ?string
    {
        if ($band === null) {
            return null;
        }

        $n3fjp = array_search($band, self::BAND_NAMES, true);

        if ($n3fjp !== false) {
            return $n3fjp === '6CM' ? '6cm' : $n3fjp;
        }

        return preg_match('/^([\d.]+)m$/', $band, $match) === 1 ? $match[1] : $band;
    }

    /**
     * An N3FJP frequency in Hz, or null when it is blank, unreadable or not
     * in the record's band.
     *
     * N3FJP writes MHz with the Windows locale's separators ("14.07400",
     * "1,296.10000", "14,07400"), so whichever of "." and "," comes last is
     * the decimal point. A lone "," is a decimal point unless reading it as
     * thousands is what puts the frequency in the record's band.
     */
    public function frequencyHz(string $frequency, ?string $bandName): ?int
    {
        $bandId = $bandName !== null ? $this->bandResolver->resolveByName($bandName) : null;

        foreach ($this->frequencyReadings($frequency) as $megahertz) {
            $hz = (int) round($megahertz * 1_000_000);
            $resolvedBand = $this->bandResolver->resolveByFrequencyHz($hz);

            if ($resolvedBand !== null && ($bandId === null || $resolvedBand === $bandId)) {
                return $hz;
            }
        }

        return null;
    }

    /**
     * The ways an N3FJP frequency can be read, in MHz, most likely first.
     *
     * @return list<float>
     */
    private function frequencyReadings(string $frequency): array
    {
        $frequency = (string) preg_replace('/[\s\']/u', '', $frequency);

        if (preg_match('/^[\d.,]+$/', $frequency) !== 1) {
            return [];
        }

        $lastDot = strrpos($frequency, '.');
        $lastComma = strrpos($frequency, ',');

        $candidates = match (true) {
            $lastDot !== false && $lastComma !== false => [$lastDot > $lastComma
                ? str_replace(',', '', $frequency)
                : str_replace(['.', ','], ['', '.'], $frequency)],
            $lastComma !== false => [str_replace(',', '.', $frequency), str_replace(',', '', $frequency)],
            default => [$frequency],
        };

        return array_values(array_map('floatval', array_filter($candidates, 'is_numeric')));
    }

    /**
     * The mode name and ADIF-style parent mode to resolve, from N3FJP's mode
     * and mode group. Without a rig N3FJP logs just the group (CW, PH, DIG);
     * PH is resolved as phone through its parent.
     *
     * @return array{?string, ?string}
     */
    private function modeNames(string $mode, string $group): array
    {
        $mode = strtoupper(trim($mode));
        $group = strtoupper(trim($group));
        $name = $mode !== '' ? $mode : $group;

        if ($name === '') {
            return [null, null];
        }

        return [$name, $name === 'PH' ? 'SSB' : null];
    }

    /**
     * The UTC QSO time from N3FJP's date (yyyy/MM/dd) and time (HH:mm:ss),
     * or now when they can't be read, as N3FJP itself does. .NET writes
     * the PC's own separators in place of "/" and ":", so a German PC sends
     * 2026.06.27 and some locales 18.42.07; a contact typed in post-contest
     * mode can carry a bare 1842.
     */
    private function timestamp(string $date, string $time): Carbon
    {
        $date = trim($date);
        $time = trim($time);

        if (preg_match('/^(\d{4})\D(\d{1,2})\D(\d{1,2})$/', $date, $d) === 1
            && preg_match('/^(\d{1,2})\D?(\d{2})(?:\D?(\d{2}))?$/', $time, $t) === 1
            && checkdate((int) $d[2], (int) $d[3], (int) $d[1])
            && (int) $t[1] < 24 && (int) $t[2] < 60 && (int) ($t[3] ?? 0) < 60) {
            return Carbon::create((int) $d[1], (int) $d[2], (int) $d[3], (int) $t[1], (int) $t[2], (int) ($t[3] ?? 0), 'UTC');
        }

        try {
            return Carbon::parse(str_replace('/', '-', $date).' '.$time, 'UTC');
        } catch (Throwable) {
            return Carbon::now('UTC');
        }
    }

    /**
     * Which station logged the contact: N3FJP's station name, else the
     * computer name, else the name the client sent the message as.
     *
     * @param  array<string, string>  $fields
     */
    private function stationIdentifier(array $fields, string $sentFrom): string
    {
        foreach ([$fields['STATION'] ?? '', $fields['COMPUTERNAME'] ?? '', $sentFrom] as $candidate) {
            $candidate = Str::limit(N3fjpMessage::clean($candidate), 50, '');

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'N3FJP';
    }
}
