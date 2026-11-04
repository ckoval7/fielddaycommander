<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Support\ExternalLoggers;

/**
 * N1MM Logger+ UDP broadcasts, built the way N1MM builds them (decompiled
 * N1MMLogger.Net: Contact.BuildContactXml, Contact.BroadcastDeleteIfEnabled,
 * RadioInterface.XMLDescription, SharedCode.NewWriter, N1MMInterface.SendData):
 *
 * - .NET XmlWriter: '<?xml version="1.0" encoding="utf-8"?>', CRLF line
 *   ends, one TAB of indent per child, no BOM, no newline after the root
 * - element text escapes & < > (not quotes); an empty value is <x></x>;
 *   CR, LF or CRLF inside a value become CRLF
 * - StationName is HtmlEncode()d before the writer escapes it again
 * - timestamps "yyyy-MM-dd HH:mm:ss" in UTC with no zone; "no date" is
 *   1900-01-01 00:00:00
 * - band is a float in the PC's culture ("3.5", or "3,5"), rxfreq/txfreq
 *   and Freq/TXFreq in 10 Hz units
 * - IsOriginal and the RadioInfo flags are "True"/"False"
 * - ID is a GUID as 32 lower-case hex digits
 * - the datagram is the UTF-8 bytes, but only as many of them as the
 *   string has characters (Strings.Len), so each extra UTF-8 byte cuts one
 *   byte off the end of the packet
 */
final class N1mm
{
    /**
     * A Field Day contact as N1MM holds it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function fieldDayContact(string $call, \DateTimeInterface $time, array $overrides = []): array
    {
        return [
            'contestname' => 'FD',
            'dbname' => 'C:\\Users\\op\\Documents\\N1MM Logger+\\Databases\\FD2026.s3db',
            'contestnr' => 12,
            'timestamp' => $time,
            'mycall' => 'W2XYZ',
            'band' => 14.0,
            'freq_khz' => 14025.3,
            'txfreq_khz' => 14025.3,
            'operator' => 'K3CPK',
            'mode' => 'CW',
            'call' => $call,
            'countryprefix' => 'K',
            'wpxprefix' => 'W1',
            'stationprefix' => $call,
            'continent' => 'NA',
            'snt' => '599',
            'sntnr' => 0,
            'rcv' => '599',
            'rcvnr' => 0,
            'gridsquare' => '',
            'exchange1' => '2A',
            'section' => 'CT',
            'comment' => '',
            'qth' => '',
            'name' => '',
            'power' => '',
            'misctext' => '',
            'zone' => 0,
            'prec' => '',
            'ck' => 0,
            'points' => 2,
            'radionr' => 1,
            'run1run2' => 1,
            'IsRunQSO' => 1,
            'NetworkedCompNr' => 0,
            'IsOriginal' => true,
            'NetBiosName' => 'SHACK-PC',
            'StationName' => 'SHACK-PC',
            'ID' => self::id(),
            'oldtimestamp' => $time,
            'oldcall' => $call,
            'SentExchange' => '3A CT',
            'culture' => 'en-US',
            ...$overrides,
        ];
    }

    /**
     * A new GUID as N1MM writes it.
     */
    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The contactinfo sent when the operator logs a QSO. contactinfo always
     * carries the QSO's own call and time as oldcall and oldtimestamp.
     *
     * @param  array<string, mixed>  $contact
     */
    public static function contactInfo(array $contact): string
    {
        return self::datagram(self::contactXml('contactinfo', [...$contact, 'oldcall' => $contact['call'], 'oldtimestamp' => $contact['timestamp']]));
    }

    /**
     * The contactreplace sent for an edit (with the call and time from
     * before the edit) or by Tools > Rebroadcast contest QSOs.
     *
     * @param  array<string, mixed>  $contact
     */
    public static function contactReplace(array $contact, ?string $oldCall = null, ?\DateTimeInterface $oldTime = null): string
    {
        return self::datagram(self::contactXml('contactreplace', [
            ...$contact,
            'oldcall' => $oldCall ?? $contact['call'],
            'oldtimestamp' => $oldTime ?? $contact['timestamp'],
        ]));
    }

    /**
     * The contactinfo a networked N1MM PC relays for a QSO another PC
     * logged: IsOriginal False, NetBiosName the PC that logged it,
     * StationName the relaying PC.
     *
     * @param  array<string, mixed>  $contact
     */
    public static function relayedContactInfo(array $contact, string $relayingPc): string
    {
        return self::contactInfo([...$contact, 'IsOriginal' => false, 'StationName' => $relayingPc]);
    }

    /**
     * The lookupinfo sent when the operator tabs out of the call field.
     *
     * @param  array<string, mixed>  $contact
     */
    public static function lookupInfo(array $contact): string
    {
        return self::datagram(self::contactXml('lookupinfo', [...$contact, 'oldcall' => '', 'oldtimestamp' => null]));
    }

    /**
     * The contactdelete sent when a QSO is deleted.
     *
     * @param  array<string, mixed>  $contact
     */
    public static function contactDelete(array $contact): string
    {
        return self::datagram(self::document('contactdelete', [
            'app' => 'N1MM',
            'timestamp' => self::timestamp($contact['timestamp']),
            'call' => $contact['call'],
            'mycall' => $contact['mycall'],
            'band' => self::band($contact['band'], $contact['culture']),
            'contestnr' => (string) $contact['contestnr'],
            'StationName' => htmlspecialchars($contact['StationName'], ENT_QUOTES | ENT_HTML5),
            'ID' => $contact['ID'],
        ]));
    }

    /**
     * The RadioInfo sent on every change and every ten seconds per radio.
     */
    public static function radioInfo(string $station, float $freqKhz, string $mode, string $opCall = 'K3CPK', bool $transmitting = false, int $radioNr = 1): string
    {
        $tenHz = (string) (int) round($freqKhz * 100);

        return self::datagram(self::document('RadioInfo', [
            'app' => 'N1MM',
            'StationName' => htmlspecialchars($station, ENT_QUOTES | ENT_HTML5),
            'RadioNr' => (string) $radioNr,
            'Freq' => $tenHz,
            'TXFreq' => $tenHz,
            'Mode' => $mode,
            'mycall' => 'W2XYZ',
            'OpCall' => $opCall,
            'IsRunning' => 'True',
            'FocusEntry' => '1050212',
            'EntryWindowHwnd' => '1050212',
            'Antenna' => '1',
            'Rotors' => 'TH6',
            'FocusRadioNr' => '1',
            'IsStereo' => 'False',
            'IsSplit' => 'False',
            'ActiveRadioNr' => (string) $radioNr,
            'IsTransmitting' => $transmitting ? 'True' : 'False',
            'FunctionKeyCaption' => $transmitting ? 'F1 CQ' : '',
            'RadioName' => 'K3',
            'AuxAntSelected' => '-1',
            'AuxAntSelectedName' => '',
            'IsConnected' => 'True',
        ]));
    }

    /**
     * The AppInfo sent when the contest or database changes.
     */
    public static function appInfo(string $station = 'SHACK-PC'): string
    {
        return self::datagram(self::document('AppInfo', [
            'app' => 'N1MM',
            'dbname' => 'FD2026.s3db',
            'contestnr' => '12',
            'contestname' => 'FD',
            'StationName' => $station,
            'mycall' => 'W2XYZ',
        ]));
    }

    /**
     * The score broadcast, built by string concatenation with no escaping,
     * so a club name with "&" makes it invalid XML.
     */
    public static function dynamicResults(string $club = 'Hamden & District ARC'): string
    {
        return '<?xml version="1.0"?><dynamicresults><contest>FD</contest><call>W2XYZ</call><ops>K3CPK</ops>'
            ."<class power=\"LOW\" assisted=\"NONASSISTED\" transmitter=\"TWO\" ops=\"MULTI-OP\" bands=\"ALL\" mode=\"MIXED\" overlay=\"\"></class><club>{$club}</club>"
            .'<qth><cqzone>5</cqzone><arrlsection>CT</arrlsection></qth><breakdown><qso band="total" mode="ALL">42</qso></breakdown>'
            .'<score>168</score><timestamp>2026-06-27 18:00:00</timestamp></dynamicresults>';
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private static function contactXml(string $root, array $c): string
    {
        return self::document($root, [
            'app' => 'N1MM',
            'contestname' => $c['contestname'],
            'dbname' => $c['dbname'],
            'contestnr' => (string) $c['contestnr'],
            'timestamp' => self::timestamp($c['timestamp']),
            'mycall' => $c['mycall'],
            'band' => self::band($c['band'], $c['culture']),
            'rxfreq' => (string) (int) round($c['freq_khz'] * 100),
            'txfreq' => (string) (int) round($c['txfreq_khz'] * 100),
            'operator' => $c['operator'],
            'mode' => $c['mode'],
            'call' => $c['call'],
            'countryprefix' => $c['countryprefix'],
            'wpxprefix' => $c['wpxprefix'],
            'stationprefix' => $c['stationprefix'],
            'continent' => $c['continent'],
            'snt' => $c['snt'],
            'sntnr' => (string) $c['sntnr'],
            'rcv' => $c['rcv'],
            'rcvnr' => (string) $c['rcvnr'],
            'gridsquare' => $c['gridsquare'],
            'exchange1' => $c['exchange1'],
            'section' => $c['section'],
            'comment' => $c['comment'],
            'qth' => $c['qth'],
            'name' => $c['name'],
            'power' => $c['power'],
            'misctext' => $c['misctext'],
            'zone' => (string) $c['zone'],
            'prec' => $c['prec'],
            'ck' => (string) $c['ck'],
            'ismultiplier1' => '0',
            'ismultiplier2' => '0',
            'ismultiplier3' => '0',
            'points' => (string) $c['points'],
            'radionr' => (string) $c['radionr'],
            'run1run2' => (string) $c['run1run2'],
            'RoverLocation' => '',
            'RadioInterfaced' => '1',
            'NetworkedCompNr' => (string) $c['NetworkedCompNr'],
            'IsOriginal' => $c['IsOriginal'] ? 'True' : 'False',
            'NetBiosName' => $c['NetBiosName'],
            'IsRunQSO' => (string) $c['IsRunQSO'],
            'StationName' => htmlspecialchars($c['StationName'], ENT_QUOTES | ENT_HTML5),
            'ID' => $c['ID'],
            'IsClaimedQso' => '1',
            'oldtimestamp' => $c['oldtimestamp'] !== null ? self::timestamp($c['oldtimestamp']) : '1900-01-01 00:00:00',
            'oldcall' => $c['oldcall'],
            'SentExchange' => $c['SentExchange'],
        ]);
    }

    /**
     * An XmlWriter document with N1MM's settings.
     *
     * @param  array<string, string>  $elements
     */
    private static function document(string $root, array $elements): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'."\r\n<{$root}>";

        foreach ($elements as $name => $value) {
            $value = preg_replace('/\r\n|\r|\n/', "\r\n", $value);
            $escaped = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string) $value);
            $xml .= "\r\n\t<{$name}>{$escaped}</{$name}>";
        }

        return $xml."\r\n</{$root}>";
    }

    /**
     * What N1MMInterface.SendData puts on the wire: the UTF-8 bytes, cut to
     * the string's length in UTF-16 characters.
     */
    public static function datagram(string $xml): string
    {
        $utf16Length = intdiv(strlen(mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8')), 2);

        return substr($xml, 0, $utf16Length);
    }

    private static function timestamp(\DateTimeInterface $time): string
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Conversions.ToString(float) in the PC's culture.
     */
    private static function band(float $band, string $culture): string
    {
        $text = rtrim(rtrim(number_format($band, 3, '.', ''), '0'), '.');

        return $culture === 'de-DE' ? str_replace('.', ',', $text) : $text;
    }
}
