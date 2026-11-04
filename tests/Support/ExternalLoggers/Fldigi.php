<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Support\ExternalLoggers;

/**
 * fldigi's "Broadcast UDP ADIF record" feed, built the way fldigi builds it
 * (src/logger/logger.cxx, post_udp_log() and putadif()):
 *
 * - one datagram per QSO, sent as soon as it is logged; no header, no <EOH>
 * - every enabled field is written, even empty ones (<NAME:0>), in a fixed
 *   order; SUBMODE only when the mode has one
 * - lengths are strlen(), so UTF-8 bytes, not characters
 * - TIME_ON is cut to HHMM, and TIME_OFF is TIME_ON again (an fldigi quirk)
 * - FREQ is the log's MHz string with any comma turned into a period
 * - newlines in NOTES become ';'
 * - no separators between fields, and the record ends with "<EOR>"
 *
 * Every field is enabled out of the box (EXPORT_LOG_FIELDS() sets them all).
 */
final class Fldigi
{
    /**
     * fldigi modes as the log stores them (adif_name), with the MODE and
     * SUBMODE it exports for each (globals.cxx mode_info).
     *
     * @var array<string, array{string, string}>
     */
    public const MODES = [
        'CW' => ['CW', ''],
        'SSB' => ['SSB', ''],
        'PSK31' => ['PSK', 'PSK31'],
        'PSK63' => ['PSK', 'PSK63'],
        '8PSK500' => ['PSK', ''],
        'RTTY' => ['RTTY', ''],
        'MFSK16' => ['MFSK', 'MFSK16'],
        'OLIVIA 8/500' => ['OLIVIA', 'OLIVIA 8/500'],
        'CONTESTI8/250' => ['CONTESTI', ''],
        'THOR22' => ['THOR', ''],
        'FSQ' => ['FSQ', ''],
    ];

    /**
     * Every field fldigi can send, in the order it sends them. Keys are the
     * record's field names, values the ADIF tag written.
     *
     * @var array<string, string>
     */
    private const FIELD_ORDER = [
        'call' => 'CALL',
        'mode' => 'MODE',
        'freq' => 'FREQ',
        'band' => 'BAND',
        'date_on' => 'QSO_DATE',
        'time_on' => 'TIME_ON',
        'date_off' => 'QSO_DATE_OFF',
        'time_off' => 'TIME_OFF',
        'name' => 'NAME',
        'qth' => 'QTH',
        'state' => 'STATE',
        'province' => 'VE_PROV',
        'country' => 'COUNTRY',
        'rst_sent' => 'RST_SENT',
        'rst_rcvd' => 'RST_RCVD',
        'tx_pwr' => 'TX_PWR',
        'county' => 'CNTY',
        'dxcc' => 'DXCC',
        'cqz' => 'CQZ',
        'iota' => 'IOTA',
        'continent' => 'CONT',
        'ituz' => 'ITUZ',
        'gridsquare' => 'GRIDSQUARE',
        'qsl_rcvd' => 'QSLRDATE',
        'qsl_sent' => 'QSLSDATE',
        'eqsl_rcvd' => 'EQSLRDATE',
        'eqsl_sent' => 'EQSLSDATE',
        'lotw_rcvd' => 'LOTWRDATE',
        'lotw_sent' => 'LOTWSDATE',
        'qsl_via' => 'QSL_VIA',
        'notes' => 'NOTES',
        'srx' => 'SRX',
        'stx' => 'STX',
        'xchg1' => 'SRX_STRING',
        'myxchg' => 'STX_STRING',
        'arrl_class' => 'CLASS',
        'arrl_sect' => 'ARRL_SECT',
        'op_call' => 'OPERATOR',
        'sta_call' => 'STATION_CALLSIGN',
        'mygrid' => 'MY_GRIDSQUARE',
        'mycity' => 'MY_CITY',
        'check' => 'CHECK',
        'age' => 'AGE',
        'ten_ten' => 'TEN_TEN',
        'cwss_check' => 'CWSS_CHK',
        'cwss_serno' => 'CWSS_SERNO',
        'cwss_prec' => 'CWSS_PREC',
        'cwss_section' => 'CWSS_SECTION',
    ];

    /**
     * A Field Day QSO as fldigi's log holds it, before export.
     *
     * @param  array<string, string>  $overrides  Record fields to change (see FIELD_ORDER keys)
     * @return array<string, string>
     */
    public static function fieldDayQso(string $call, \DateTimeInterface $time, array $overrides = []): array
    {
        return [
            'call' => $call,
            'mode' => 'PSK31',
            'freq' => '14.070150',
            'band' => '20m',
            'date_on' => $time->format('Ymd'),
            'time_on' => $time->format('His'),
            'date_off' => $time->format('Ymd'),
            'name' => '',
            'qth' => '',
            'state' => '',
            'rst_sent' => '599',
            'rst_rcvd' => '599',
            'tx_pwr' => '100',
            'gridsquare' => '',
            'xchg1' => '',
            'myxchg' => '3A CT',
            'arrl_class' => '2A',
            'arrl_sect' => 'CT',
            'op_call' => '',
            'sta_call' => 'W2XYZ',
            ...$overrides,
        ];
    }

    /**
     * The datagram fldigi sends for a logged QSO.
     *
     * @param  array<string, string>  $qso  Record fields; missing ones are empty
     * @param  list<string>|null  $enabled  Fields ticked in Configure > Logging > UDP; null for fldigi's default (all)
     */
    public static function datagram(array $qso, ?array $enabled = null): string
    {
        $enabled ??= array_keys(self::FIELD_ORDER);
        $out = '';

        foreach (self::FIELD_ORDER as $field => $tag) {
            if (! in_array($field, $enabled, true)) {
                continue;
            }

            $value = $qso[$field] ?? '';

            if ($field === 'mode') {
                [$mode, $submode] = self::MODES[strtoupper($value)] ?? [strtoupper($value), ''];
                $out .= self::putadif('MODE', $mode);

                if ($submode !== '') {
                    $out .= self::putadif('SUBMODE', $submode);
                }

                continue;
            }

            $value = match ($field) {
                'freq' => str_replace(',', '.', $value),
                'time_on', 'time_off' => substr($qso['time_on'] ?? '', 0, 4),
                'notes' => str_replace("\n", ';', $value),
                default => $value,
            };

            $out .= self::putadif($tag, $value);
        }

        return $out.'<EOR>';
    }

    /**
     * The record "Test" in fldigi's UDP config sends (udp_test()).
     */
    public static function testDatagram(string $address, string $port): string
    {
        $payload = "Test UDP broadcast on {$address} : {$port}";

        return '<FLDIGI_TEST:'.strlen($payload).'>'.$payload.'<STATION_CALLSIGN:5>W1HKJ<EOR>';
    }

    private static function putadif(string $tag, string $value): string
    {
        return '<'.$tag.':'.strlen($value).'>'.$value;
    }
}
