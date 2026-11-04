<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

/**
 * One message from an N3FJP network client, and the builders for the
 * messages the server sends back.
 *
 * Messages are `<BOR>…<EOR>` strings of XML-like tags that N3FJP neither
 * escapes nor nests consistently, so fields are found the way N3FJP finds
 * them: the text between the first opening and first closing tag, matched
 * without regard to case.
 */
readonly class N3fjpMessage
{
    /**
     * What the server calls itself in the FROM of messages it sends.
     */
    public const SERVER_NAME = 'FDCOMMANDER';

    public function __construct(
        public N3fjpMessageKind $kind,
        public string $raw,
    ) {}

    public static function parse(string $raw): self
    {
        $upper = strtoupper($raw);

        // A chat says what it is right after <BOR>; its text may hold
        // anything, <NTWK> included, so it is not searched for other kinds.
        if (preg_match('/^\s*<BOR>\s*<MESG>/', $upper) === 1) {
            return new self(N3fjpMessageKind::Chat, $raw);
        }

        $kind = match (true) {
            str_contains($upper, '<BOR><NTWK><OPEN>') => N3fjpMessageKind::Open,
            str_contains($upper, '<BOR><NTWK><CHECK>') => N3fjpMessageKind::Check,
            str_contains($upper, '<NTWK>') => N3fjpMessageKind::Transaction,
            str_contains($upper, '<BAMS>') => N3fjpMessageKind::BandAndMode,
            str_contains($upper, '<MESG>') => N3fjpMessageKind::Chat,
            str_contains($upper, '<SCLK>') => N3fjpMessageKind::ClockSync,
            str_contains($upper, '<DXSP>') => N3fjpMessageKind::DxSpot,
            str_contains($upper, '<WHO>') => N3fjpMessageKind::Who,
            default => N3fjpMessageKind::Unknown,
        };

        return new self($kind, $raw);
    }

    /**
     * The text of a field, or an empty string when the message lacks it.
     */
    public function field(string $tag): string
    {
        return self::extract($this->raw, $tag);
    }

    /**
     * The text between the first `<TAG>` and the first `</TAG>` in a string.
     */
    public static function extract(string $haystack, string $tag): string
    {
        $open = '<'.strtoupper($tag).'>';
        $close = '</'.strtoupper($tag).'>';
        $upper = strtoupper($haystack);

        $start = strpos($upper, $open);
        $end = strpos($upper, $close);

        if ($start === false || $end === false || $end < $start + strlen($open)) {
            return '';
        }

        return substr($haystack, $start + strlen($open), $end - $start - strlen($open));
    }

    /**
     * A contact transaction: ADD, EDIT, GET or DELETE.
     */
    public static function transaction(string $transaction, string $data): string
    {
        return '<BOR><NTWK><FROM>'.self::SERVER_NAME.'</FROM><TRANSACTION>'.$transaction
            .'</TRANSACTION><XMLDATA>'.$data.'</XMLDATA><EOR>';
    }

    /**
     * Tells a client to empty its log before a full reload.
     */
    public static function clear(): string
    {
        return '<BOR><NTWK><TRANSACTION>CLEAR</TRANSACTION><EOR>';
    }

    /**
     * Tells a client a full reload is complete.
     */
    public static function listComplete(): string
    {
        return '<BOR><NTWK><TRANSACTION>LIST</TRANSACTION><EOR>';
    }

    /**
     * The record count and last record number a client compares its log
     * against; a client that differs asks for a full reload.
     */
    public static function check(int $count, int $lastKey): string
    {
        return self::transaction('CHECK', '<COUNT>'.$count.'</COUNT><LAST>'.$lastKey.'</LAST>');
    }

    /**
     * A chat message. An empty $to is a broadcast.
     */
    public static function chat(string $to, string $text): string
    {
        return '<BOR><MESG><TO>'.self::clean($to).'</TO><FROM>'.self::SERVER_NAME.'</FROM><MSGTXT>'
            .self::clean($text).'</MSGTXT><EOR>';
    }

    /**
     * A client's chat, rebuilt for relaying with its text cleaned. N3FJP
     * clients parse by the first closing tag and by <EOR>, so text with
     * tags or "<EOR>" in it would break the message for every client.
     */
    public static function relayedChat(string $raw): string
    {
        $upper = strtoupper($raw);
        $start = strpos($upper, '<MSGTXT>');
        $end = strrpos($upper, '</MSGTXT>');
        $text = $start !== false && $end !== false && $end > $start ? substr($raw, $start + 8, $end - $start - 8) : '';

        return '<BOR><MESG><TO>'.self::clean(self::extract($raw, 'TO')).'</TO><FROM>'.self::clean(self::extract($raw, 'FROM'))
            .'</FROM><MSGTXT>'.self::clean($text).'</MSGTXT><EOR>';
    }

    /**
     * A station's band and mode for the clients' band status display.
     */
    public static function bandAndMode(string $station, string $band, string $mode): string
    {
        return '<BOR><BAMS><STATION>'.self::clean($station).'</STATION><BAND>'.self::clean($band)
            .'</BAND><MODE>'.self::clean($mode).'</MODE><EOR>';
    }

    /**
     * A value made safe to place between tags. N3FJP does not escape, so
     * angle brackets and the terminator characters are removed.
     */
    public static function clean(?string $value): string
    {
        return trim((string) preg_replace('/[<>\x00-\x1F\x7F]/u', ' ', (string) $value));
    }
}
