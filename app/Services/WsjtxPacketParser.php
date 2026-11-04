<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

class WsjtxPacketParser
{
    private const MAGIC = 0xADBCCBDA;

    private const TYPE_HEARTBEAT = 0;

    private const TYPE_LOGGED_ADIF = 12;

    /** Minimum valid packet: magic (4) + schema (4) + type (4) = 12 bytes. */
    private const MIN_PACKET_LENGTH = 12;

    /**
     * Parse a raw WSJT-X binary UDP packet.
     *
     * @return string|array<string, mixed>|null
     */
    public function parse(string $data): string|array|null
    {
        if (strlen($data) < self::MIN_PACKET_LENGTH) {
            return null;
        }

        $offset = 0;

        $magic = $this->readUint32($data, $offset);
        if ($magic !== self::MAGIC) {
            return null;
        }

        $schema = $this->readUint32($data, $offset);
        $type = $this->readUint32($data, $offset);

        return match ($type) {
            self::TYPE_HEARTBEAT => $this->parseHeartbeat($data, $offset, (int) $schema),
            self::TYPE_LOGGED_ADIF => $this->parseLoggedAdif($data, $offset),
            default => null,
        };
    }

    /**
     * The client id from a WSJT-X packet's header: "WSJT-X", "JTDX", or
     * "WSJT-X - <rig name>" for an instance started with --rig-name. Null
     * when the packet isn't a WSJT-X message or is cut off before the id.
     */
    public function clientId(string $data): ?string
    {
        if (strlen($data) < self::MIN_PACKET_LENGTH) {
            return null;
        }

        $offset = 0;

        if ($this->readUint32($data, $offset) !== self::MAGIC) {
            return null;
        }

        $offset = self::MIN_PACKET_LENGTH;
        $id = $this->readUtf8($data, $offset);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Parse a Heartbeat message (type 0).
     *
     * @return array<string, mixed>|null
     */
    private function parseHeartbeat(string $data, int $offset, int $schema): ?array
    {
        $id = $this->readUtf8($data, $offset);

        if ($id === false) {
            return null;
        }

        $maxSchema = $this->readUint32($data, $offset) ?? $schema;
        $version = $this->readUtf8($data, $offset);
        $revision = $this->readUtf8($data, $offset);

        return [
            'id' => $id,
            'max_schema' => $maxSchema,
            'version' => is_string($version) ? $version : null,
            'revision' => is_string($revision) ? $revision : null,
        ];
    }

    /**
     * Parse a Logged ADIF message (type 12). Null when the datagram was cut
     * short, so a damaged packet is dropped rather than half-read.
     */
    private function parseLoggedAdif(string $data, int $offset): ?string
    {
        if ($this->readUtf8($data, $offset) === false) {
            return null;
        }

        $adif = $this->readUtf8($data, $offset);

        return is_string($adif) ? $adif : null;
    }

    /**
     * Read a 4-byte big-endian unsigned integer, or null past the end.
     */
    private function readUint32(string $data, int &$offset): ?int
    {
        if ($offset + 4 > strlen($data)) {
            return null;
        }

        $value = unpack('N', $data, $offset)[1];
        $offset += 4;

        return $value;
    }

    /**
     * Read a QDataStream utf8 string: 4-byte length prefix + N bytes.
     * A length of 0xffffffff is a null string; false means the datagram
     * ends before the string does.
     */
    private function readUtf8(string $data, int &$offset): string|false|null
    {
        $length = $this->readUint32($data, $offset);

        if ($length === 0xFFFFFFFF) {
            return null;
        }

        if ($length === null || $offset + $length > strlen($data)) {
            return false;
        }

        $value = substr($data, $offset, $length);
        $offset += $length;

        return $value;
    }
}
