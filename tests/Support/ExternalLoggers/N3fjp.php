<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Support\ExternalLoggers;

/**
 * An N3FJP Field Day / Winter Field Day client PC on the network, built the
 * way N3FJP builds its traffic (decompiled AuroraEngine/Client.cs,
 * DavisLibrary/NetworkBandAndChat.cs, NetworkLogging.cs, StringFunctions.cs,
 * Form1.LogTheContact):
 *
 * - UTF-16LE, no BOM; each message is one write of text + "\x03\x04\x07"
 * - on connect: BAMS, then <BOR><NTWK><OPEN> (no <EOR>), then <BOR><WHO><EOR>
 * - CHECK every 29 s, BAMS every 30 s; a reconnect sends neither OPEN nor WHO
 * - record fields in alphabetical order, values raw (no escaping), empty
 *   fields left out; FLDPOINTS and FLDPRIMARYKEY always present
 * - dates "yyyy/MM/dd" and times "HH:mm:ss", where .NET swaps "/" and ":"
 *   for the PC's own date and time separators
 * - frequency in MHz with "N5", so "14.07400", "1,296.10000", or
 *   "14,07400" on a comma-decimal PC
 * - the reader decodes each 8192-byte read on its own, so a message split
 *   at an odd byte corrupts every character after it
 */
final class N3fjp
{
    public const TERMINATOR = "\x03\x04\x07";

    /**
     * Encode one message the way Client.WriteLine does.
     */
    public static function frame(string $message): string
    {
        return mb_convert_encoding($message.self::TERMINATOR, 'UTF-16LE', 'UTF-8');
    }

    public static function bams(string $station, string $band = '20', string $mode = 'PH'): string
    {
        return "<BOR><BAMS><STATION>{$station}</STATION><BAND>{$band}</BAND><MODE>{$mode}</MODE><EOR>";
    }

    public static function open(): string
    {
        return '<BOR><NTWK><OPEN>';
    }

    public static function check(): string
    {
        return '<BOR><NTWK><CHECK>';
    }

    public static function who(): string
    {
        return '<BOR><WHO><EOR>';
    }

    /**
     * The three messages a client sends when it connects.
     *
     * @return list<string>
     */
    public static function hello(string $station): array
    {
        return [self::bams($station), self::open(), self::who()];
    }

    /**
     * A Field Day contact as LogTheContact fills it in.
     *
     * @param  array<string, string>  $overrides  FLD fields without the prefix (CALL, CLASS, ...)
     * @return array<string, string>
     */
    public static function fieldDayContact(string $call, \DateTimeInterface $time, string $computer = 'GOTA-PC', array $overrides = []): array
    {
        $utc = \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'));

        return [
            'BAND' => '20',
            'CALL' => $call,
            'CLASS' => '2A',
            'COMPUTERNAME' => $computer,
            'CONTESTID' => 'ARRL-FD',
            'CONTINENT' => 'NA',
            'COUNTRYDXCC' => '291',
            'COUNTRYWORKED' => 'USA',
            'CQZONE' => '5',
            'DATESTR' => $utc->format('Y/m/d'),
            'ITUZONE' => '8',
            'MODE' => 'PH',
            'MODECONTEST' => 'PH',
            'OPERATOR' => 'K3CPK',
            'POINTS' => '0',
            'PREFIX' => 'W1',
            'PRIMARYKEY' => '1',
            'SECTION' => 'CT',
            'SERIALNOS' => '001',
            'STATE' => 'CT',
            'STATION' => $computer,
            'TIMEONSTR' => $utc->format('H:i:s'),
            'TRANSMITTERID' => '0',
            ...$overrides,
        ];
    }

    /**
     * CreateStringRecord: fields in alphabetical order, raw, empty ones left out.
     *
     * @param  array<string, string>  $fields
     */
    public static function record(array $fields): string
    {
        ksort($fields, SORT_STRING);
        $out = '';

        foreach ($fields as $name => $value) {
            if ($value !== '') {
                $out .= "<FLD{$name}>{$value}</FLD{$name}>";
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $fields
     */
    public static function add(array $fields, ?string $from = null): string
    {
        return self::transaction('ADD', self::record($fields), $from ?? $fields['COMPUTERNAME']);
    }

    /**
     * An EDIT: the record the server last sent, with the operator's changes,
     * every value trimmed and FLDQSLR/FLDQSLS always present.
     *
     * @param  array<string, string>  $fields
     */
    public static function edit(array $fields, string $from): string
    {
        return self::transaction('EDIT', self::record(['QSLR' => 'N', 'QSLS' => 'N', ...array_map('trim', $fields)]), $from);
    }

    public static function delete(int $key, string $from): string
    {
        return self::transaction('DELETE', (string) $key, $from);
    }

    public static function chat(string $from, string $text, string $to = ''): string
    {
        return "<BOR><MESG><TO>{$to}</TO><FROM>{$from}</FROM><MSGTXT>{$text}</MSGTXT><EOR>";
    }

    public static function clockSync(\DateTimeInterface $time): string
    {
        $utc = \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'));

        return '<BOR><SCLK><YEAR>'.$utc->format('Y').'</YEAR><MONTH>'.$utc->format('n').'</MONTH><DAY>'.$utc->format('j')
            .'</DAY><HOUR>'.$utc->format('G').'</HOUR><MINUTE>'.(int) $utc->format('i').'</MINUTE><SECOND>'.(int) $utc->format('s')
            .'</SECOND><MILLISECOND>412</MILLISECOND><EOR>';
    }

    private static function transaction(string $type, string $data, string $from): string
    {
        return "<BOR><NTWK><FROM>{$from}</FROM><TRANSACTION>{$type}</TRANSACTION><XMLDATA>{$data}</XMLDATA><EOR>";
    }

    /**
     * What an N3FJP client makes of the bytes it read: each read decoded on
     * its own (Encoding.Unicode.GetString(buffer).Trim('\0')), joined, split
     * on the terminator. An odd-length read misaligns everything after it.
     *
     * @param  list<string>  $reads  The chunks each socket read returned
     * @return list<string>
     */
    public static function decodeLikeClient(array $reads): array
    {
        $text = '';

        foreach ($reads as $chunk) {
            $decoded = @mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE');
            $text .= trim($decoded, "\0");
        }

        return array_values(array_filter(explode(self::TERMINATOR, $text), fn (string $message) => $message !== ''));
    }

    /**
     * The messages in what the server sent, decoded correctly.
     *
     * @return list<string>
     */
    public static function messages(string $bytes): array
    {
        return self::decodeLikeClient([$bytes]);
    }

    /**
     * The TRANSACTION of each NTWK message, with its FLDCALL when it has one.
     *
     * @param  list<string>  $messages
     * @return list<string>
     */
    public static function transactions(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            if (preg_match('#<TRANSACTION>(\w+)</TRANSACTION>#', $message, $type) !== 1) {
                continue;
            }

            $call = preg_match('#<FLDCALL>([^<]*)</FLDCALL>#', $message, $match) === 1 ? ' '.$match[1] : '';
            $out[] = $type[1].$call;
        }

        return $out;
    }
}
