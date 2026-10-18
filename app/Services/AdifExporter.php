<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Models\Contact;
use App\Models\EventConfiguration;

/**
 * Builds an ADIF 3.1 log of an event's creditable contacts.
 *
 * Winter Field Day accepts an ADIF upload in place of a Cabrillo file, but the
 * export is useful for any event type. Only the fields WFDA requires (CALL,
 * QSO_DATE, TIME_ON, BAND, MODE, CLASS, ARRL_SECT) are essential; the rest
 * identify the station and operator for other loggers.
 */
class AdifExporter
{
    public const ADIF_VERSION = '3.1.4';

    /**
     * Band names that differ from their ADIF enumeration value. Every other
     * band is its name upper-cased ("20m" -> "20M", "70cm" -> "70CM").
     *
     * 5cm is ADIF's "6CM" band; 2200m is "2190M".
     *
     * @var array<string, string>
     */
    protected const BAND_OVERRIDES = [
        '2200M' => '2190M',
        '5CM' => '6CM',
    ];

    /**
     * Sub-mode to [ADIF MODE, ADIF SUBMODE|null]. Modes that ADIF defines as
     * sub-modes of a parent are split; anything else not listed here is
     * written as MODE only (the sub-mode text becomes MODE as-is).
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    protected const MODE_MAP = [
        'CW' => ['CW', null],
        'SSB' => ['SSB', null],
        'USB' => ['SSB', 'USB'],
        'LSB' => ['SSB', 'LSB'],
        'FM' => ['FM', null],
        'AM' => ['AM', null],
        'RTTY' => ['RTTY', null],
        'SSTV' => ['SSTV', null],
        'FT8' => ['FT8', null],
        'FT4' => ['MFSK', 'FT4'],
        'JS8' => ['MFSK', 'JS8'],
        'FST4' => ['MFSK', 'FST4'],
        'FST4W' => ['MFSK', 'FST4W'],
        'Q65' => ['MFSK', 'Q65'],
        'PSK' => ['PSK', null],
        'PSK31' => ['PSK', 'PSK31'],
        'PSK63' => ['PSK', 'PSK63'],
        'PSK125' => ['PSK', 'PSK125'],
        'FREEDV' => ['DIGITALVOICE', 'FREEDV'],
    ];

    /**
     * Build the ADIF text for the event.
     */
    public function export(EventConfiguration $config): string
    {
        $config->loadMissing('event');

        $header = $this->field('ADIF_VER', self::ADIF_VERSION)."\n"
            .$this->field('PROGRAMID', 'FD Commander')."\n"
            .$this->field('PROGRAMVERSION', (string) config('app.version'))."\n"
            ."<EOH>\n\n";

        $contacts = $config->creditableContacts()
            ->with(['band', 'mode', 'section', 'logger'])
            ->orderBy('qso_time')
            ->orderBy('id')
            ->get();

        $records = $contacts
            ->map(fn (Contact $contact) => $this->record($config, $contact))
            ->implode('');

        return $header.$records;
    }

    /**
     * Download filename: the event callsign, upper-cased, with "/" made safe.
     */
    public function filename(EventConfiguration $config): string
    {
        $callsign = str_replace('/', '_', strtoupper($config->callsign));

        return "{$callsign}.adi";
    }

    /**
     * The ADIF band enumeration value for a band name, or null when ADIF has
     * none (satellite).
     */
    public function adifBand(?string $bandName): ?string
    {
        if ($bandName === null || strcasecmp($bandName, 'Satellite') === 0) {
            return null;
        }

        $upper = strtoupper($bandName);

        return self::BAND_OVERRIDES[$upper] ?? $upper;
    }

    /**
     * ADIF [MODE, SUBMODE] for a contact.
     *
     * With no sub-mode the mode group decides: CW is CW, Phone is SSB and
     * Digital is RTTY (the generic "digital" mode used in WFDA's own example).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function adifMode(?string $submode, ?string $category): array
    {
        $submode = $submode !== null ? strtoupper(trim($submode)) : '';

        if ($submode !== '') {
            return self::MODE_MAP[$submode] ?? [$submode, null];
        }

        return match ($category) {
            'CW' => ['CW', null],
            'Phone' => ['SSB', null],
            'Digital' => ['RTTY', null],
            default => [null, null],
        };
    }

    protected function record(EventConfiguration $config, Contact $contact): string
    {
        $isGota = $contact->is_gota_contact && $config->gota_callsign;
        [$mode, $submode] = $this->adifMode($contact->submode, $contact->mode?->category);
        $operator = $isGota ? $contact->gota_operator_callsign : $contact->logger?->call_sign;
        $time = $contact->qso_time->copy()->utc();

        $fields = [
            'CALL' => $contact->callsign,
            'QSO_DATE' => $time->format('Ymd'),
            'TIME_ON' => $time->format('His'),
            'BAND' => $this->adifBand($contact->band?->name),
            'MODE' => $mode,
            'SUBMODE' => $submode,
            'CLASS' => $contact->exchange_class,
            'ARRL_SECT' => $contact->section?->code,
            'STATION_CALLSIGN' => $isGota ? $config->gota_callsign : $config->callsign,
            'OPERATOR' => $operator ? strtoupper($operator) : null,
        ];

        $line = '';
        foreach ($fields as $name => $value) {
            $line .= $this->field($name, $value);
        }

        return $line."<EOR>\n";
    }

    /**
     * One ADIF data specifier. The length is in bytes, as ADIF requires, and
     * empty values are skipped.
     */
    protected function field(string $name, ?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return '<'.$name.':'.strlen($value).'>'.$value.' ';
    }
}
