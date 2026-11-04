<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Support\ExternalLoggers;

/**
 * WSJT-X's UDP messages, built the way WSJT-X builds them
 * (Network/NetworkMessage.cpp, Network/MessageClient.cpp,
 * logbook/logbook.cpp LogBook::QSOToADIF):
 *
 * - QDataStream, big-endian: magic 0xADBCCBDA, schema, type, then the
 *   client id as a QByteArray (quint32 length + UTF-8 bytes)
 * - schema 2 before the server answers a heartbeat (MessageClient starts
 *   at 2), 3 after negotiation
 * - QDateTime as julian day (qint64), ms since midnight (quint32) and a
 *   timespec byte (1 = UTC); bool as one byte; float as a double, since
 *   QDataStream defaults to double precision
 * - a logged QSO is a QSOLogged (5) followed by a LoggedADIF (12); the
 *   ADIF is "\n<adif_ver:5>3.1.0\n<programid:6>WSJT-X\n<EOH>\n" + the record
 *   + " <EOR>", fields separated by single spaces, tags lower case except
 *   SRX_STRING, and the record encoded with QString::toLatin1()
 * - the client id is the application name: "WSJT-X", or
 *   "WSJT-X - <rig name>" when started with --rig-name
 */
final class Wsjtx
{
    public const MAGIC = 0xADBCCBDA;

    public const HEARTBEAT = 0;

    public const STATUS = 1;

    public const DECODE = 2;

    public const CLEAR = 3;

    public const QSO_LOGGED = 5;

    public const CLOSE = 6;

    public const WSPR_DECODE = 10;

    public const LOGGED_ADIF = 12;

    /**
     * A logged QSO as LogQSO hands it to QSOToADIF.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    public static function fieldDayQso(string $call, \DateTimeInterface $timeOn, array $overrides = []): array
    {
        return [
            'call' => $call,
            'grid' => 'FN31',
            'mode' => 'FT8',
            'rpt_sent' => '-10',
            'rpt_rcvd' => '-12',
            'time_on' => $timeOn,
            'time_off' => \DateTimeImmutable::createFromInterface($timeOn)->modify('+75 seconds'),
            'band' => '20m',
            'dial_hz' => 14_074_000,
            'my_call' => 'W2XYZ',
            'my_grid' => 'FN20',
            'tx_power' => '100',
            'comments' => '',
            'name' => '',
            'operator' => '',
            'x_sent' => '3A CT',
            'x_rcvd' => '2A CT',
            'field_day' => true,
            ...$overrides,
        ];
    }

    /**
     * QSOToADIF's record (the part between <EOH> and <EOR>).
     *
     * @param  array<string, mixed>  $qso
     */
    public static function adifRecord(array $qso): string
    {
        $field = fn (string $tag, string $value): string => "<{$tag}:".mb_strlen($value).'>'.$value;
        $dial = number_format($qso['dial_hz'] / 1e6, 6, '.', '');

        $t = $field('call', $qso['call']);
        $t .= ' '.$field('gridsquare', $qso['grid']);

        if (! in_array($qso['mode'], ['FT4', 'FST4', 'Q65'], true)) {
            $t .= ' '.$field('mode', $qso['mode']);
        } else {
            $t .= ' <mode:4>MFSK '.$field('submode', $qso['mode']);
        }

        $t .= ' '.$field('rst_sent', $qso['rpt_sent']);
        $t .= ' '.$field('rst_rcvd', $qso['rpt_rcvd']);
        $t .= ' <qso_date:8>'.$qso['time_on']->format('Ymd');
        $t .= ' <time_on:6>'.$qso['time_on']->format('His');
        $t .= ' <qso_date_off:8>'.$qso['time_off']->format('Ymd');
        $t .= ' <time_off:6>'.$qso['time_off']->format('His');
        $t .= ' '.$field('band', $qso['band']);
        $t .= ' '.$field('freq', $dial);
        $t .= ' '.$field('station_callsign', $qso['my_call']);

        foreach (['my_gridsquare' => 'my_grid', 'tx_pwr' => 'tx_power', 'comment' => 'comments', 'name' => 'name', 'operator' => 'operator'] as $tag => $key) {
            if (($qso[$key] ?? '') !== '') {
                $t .= ' '.$field($tag, $qso[$key]);
            }
        }

        $words = preg_split('/ /', (string) $qso['x_rcvd'], -1, PREG_SPLIT_NO_EMPTY);

        if (count($words) === 2 && ! ctype_digit($words[1]) && ! (ctype_digit($words[0]) && strlen($words[0]) > 3) && $qso['field_day']) {
            $t .= ' <contest_id:14>ARRL-FIELD-DAY '.$field('SRX_STRING', $qso['x_rcvd'])
                .' '.$field('class', $words[0]).' '.$field('arrl_sect', $words[1]);
        }

        return mb_convert_encoding($t, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * The LoggedADIF (12) datagram for a QSO.
     *
     * @param  array<string, mixed>  $qso
     */
    public static function loggedAdif(array $qso, string $id = 'WSJT-X', int $schema = 2): string
    {
        $adif = "\n<adif_ver:5>3.1.0\n<programid:6>WSJT-X\n<EOH>\n".self::adifRecord($qso).' <EOR>';

        return self::header(self::LOGGED_ADIF, $id, $schema).self::bytes($adif);
    }

    /**
     * The QSOLogged (5) datagram WSJT-X sends just before LoggedADIF.
     *
     * @param  array<string, mixed>  $qso
     */
    public static function qsoLogged(array $qso, string $id = 'WSJT-X', int $schema = 2): string
    {
        return self::header(self::QSO_LOGGED, $id, $schema)
            .self::dateTime($qso['time_off'])
            .self::bytes($qso['call']).self::bytes($qso['grid'])
            .pack('J', $qso['dial_hz'])
            .self::bytes($qso['mode']).self::bytes($qso['rpt_sent']).self::bytes($qso['rpt_rcvd'])
            .self::bytes($qso['tx_power']).self::bytes($qso['comments']).self::bytes($qso['name'])
            .self::dateTime($qso['time_on'])
            .self::bytes($qso['operator']).self::bytes($qso['my_call']).self::bytes($qso['my_grid'])
            .self::bytes($qso['x_sent']).self::bytes($qso['x_rcvd'])
            .self::bytes('').self::bytes('').self::bytes('').self::bytes('');
    }

    /**
     * The two datagrams WSJT-X sends when the operator clicks OK in Log QSO.
     *
     * @param  array<string, mixed>  $qso
     * @return list<string>
     */
    public static function logged(array $qso, string $id = 'WSJT-X', int $schema = 2): array
    {
        return [self::qsoLogged($qso, $id, $schema), self::loggedAdif($qso, $id, $schema)];
    }

    /**
     * The heartbeat sent every 15 seconds.
     */
    public static function heartbeat(string $id = 'WSJT-X', int $schema = 2, string $version = '2.7.0', string $revision = 'a2b0c7'): string
    {
        return self::header(self::HEARTBEAT, $id, $schema).pack('N', 3).self::bytes($version).self::bytes($revision);
    }

    /**
     * A Status message, sent on every change of state (several a second
     * while transmitting or tuning).
     */
    public static function status(string $id = 'WSJT-X', int $schema = 2, int $dialHz = 14_074_000, string $dxCall = 'W1AW'): string
    {
        return self::header(self::STATUS, $id, $schema)
            .pack('J', $dialHz).self::bytes('FT8').self::bytes($dxCall).self::bytes('-10').self::bytes('FT8')
            .chr(1).chr(0).chr(1).pack('N', 1500).pack('N', 1500).self::bytes('W2XYZ')
            .self::bytes('FN20').self::bytes('FN31').chr(0).self::bytes('').chr(0).chr(3)
            .pack('N', 0xFFFFFFFF).pack('N', 15).self::bytes('Default').self::bytes("{$dxCall} W2XYZ R-10");
    }

    /**
     * A Decode message: one per decoded line, dozens every 15 seconds.
     */
    public static function decode(string $text, string $id = 'WSJT-X', int $schema = 2): string
    {
        return self::header(self::DECODE, $id, $schema)
            .chr(1).pack('N', 43_200_000).pack('N', -12 & 0xFFFFFFFF).pack('E', 0.1).pack('N', 1234)
            .self::bytes('~').self::bytes($text).chr(0).chr(0);
    }

    public static function clear(string $id = 'WSJT-X', int $schema = 2): string
    {
        return self::header(self::CLEAR, $id, $schema);
    }

    public static function close(string $id = 'WSJT-X', int $schema = 2): string
    {
        return self::header(self::CLOSE, $id, $schema);
    }

    private static function header(int $type, string $id, int $schema): string
    {
        return pack('NNN', self::MAGIC, $schema, $type).self::bytes($id);
    }

    /**
     * A QByteArray as QDataStream writes it.
     */
    public static function bytes(?string $value): string
    {
        return $value === null ? pack('N', 0xFFFFFFFF) : pack('N', strlen($value)).$value;
    }

    /**
     * A UTC QDateTime as QDataStream (Qt 5.2+) writes it.
     */
    private static function dateTime(\DateTimeInterface $time): string
    {
        $utc = \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'));
        $julianDay = intdiv($utc->setTime(0, 0)->getTimestamp(), 86_400) + 2_440_588;
        $msOfDay = ((int) $utc->format('G') * 3600 + (int) $utc->format('i') * 60 + (int) $utc->format('s')) * 1000;

        return pack('J', $julianDay).pack('N', $msOfDay).chr(1);
    }
}
