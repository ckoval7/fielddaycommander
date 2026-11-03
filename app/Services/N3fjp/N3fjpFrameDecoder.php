<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

/**
 * Splits one N3FJP network connection's byte stream into messages.
 *
 * N3FJP's network feature sends UTF-16LE text, each message ending in the
 * characters ETX, EOT and BEL. The decoder keeps raw bytes until a whole
 * terminator arrives at a character boundary, so a read that splits a
 * character or the terminator never garbles a message.
 */
class N3fjpFrameDecoder
{
    /**
     * The message terminator as characters.
     */
    public const TERMINATOR = "\x03\x04\x07";

    /**
     * The message terminator as UTF-16LE bytes.
     */
    private const TERMINATOR_BYTES = "\x03\x00\x04\x00\x07\x00";

    /**
     * Largest unterminated message kept, in bytes. A full N3FJP record is
     * well under 4 KB, so anything this long is not N3FJP traffic.
     */
    public const MAX_BUFFER_BYTES = 262_144;

    private string $buffer = '';

    /**
     * Encode one message for the wire.
     */
    public static function encode(string $message): string
    {
        return mb_convert_encoding($message.self::TERMINATOR, 'UTF-16LE', 'UTF-8');
    }

    /**
     * Add bytes read from the connection and return every message they complete.
     *
     * @return list<string>
     *
     * @throws N3fjpProtocolException When the peer sends more than MAX_BUFFER_BYTES without a terminator
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $messages = [];
        $offset = 0;

        while (($position = $this->findTerminator($offset)) !== null) {
            $frame = substr($this->buffer, 0, $position);
            $this->buffer = substr($this->buffer, $position + strlen(self::TERMINATOR_BYTES));
            $offset = 0;

            $message = str_replace("\0", '', mb_convert_encoding($frame, 'UTF-8', 'UTF-16LE'));

            if ($message !== '') {
                $messages[] = $message;
            }
        }

        if (strlen($this->buffer) > self::MAX_BUFFER_BYTES) {
            $this->buffer = '';

            throw new N3fjpProtocolException('Message exceeded '.self::MAX_BUFFER_BYTES.' bytes without a terminator');
        }

        return $messages;
    }

    /**
     * Byte offset of the first terminator that starts on a character
     * boundary, or null when none has arrived yet.
     */
    private function findTerminator(int $offset): ?int
    {
        while (($position = strpos($this->buffer, self::TERMINATOR_BYTES, $offset)) !== false) {
            if ($position % 2 === 0) {
                return $position;
            }

            $offset = $position + 1;
        }

        return null;
    }
}
