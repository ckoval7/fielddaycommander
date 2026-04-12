<?php

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
            self::TYPE_HEARTBEAT => $this->parseHeartbeat($data, $offset, $schema),
            self::TYPE_LOGGED_ADIF => $this->parseLoggedAdif($data, $offset),
            default => null,
        };
    }

    /**
     * Parse a Heartbeat message (type 0).
     *
     * @return array<string, mixed>
     */
    private function parseHeartbeat(string $data, int $offset, int $schema): array
    {
        $id = $this->readUtf8($data, $offset);

        $maxSchema = $offset + 4 <= strlen($data)
            ? $this->readUint32($data, $offset)
            : $schema;

        $version = $offset < strlen($data) ? $this->readUtf8($data, $offset) : null;
        $revision = $offset < strlen($data) ? $this->readUtf8($data, $offset) : null;

        return [
            'id' => $id,
            'max_schema' => $maxSchema,
            'version' => $version,
            'revision' => $revision,
        ];
    }

    /**
     * Parse a Logged ADIF message (type 12).
     */
    private function parseLoggedAdif(string $data, int $offset): ?string
    {
        // Skip the Id field
        $this->readUtf8($data, $offset);

        return $this->readUtf8($data, $offset);
    }

    /**
     * Read a 4-byte big-endian unsigned integer.
     */
    private function readUint32(string $data, int &$offset): int
    {
        $value = unpack('N', $data, $offset);
        $offset += 4;

        return $value[1];
    }

    /**
     * Read a QDataStream utf8 string: 4-byte length prefix + N bytes.
     * A length of 0xffffffff indicates null.
     */
    private function readUtf8(string $data, int &$offset): ?string
    {
        $length = $this->readUint32($data, $offset);

        if ($length === 0xFFFFFFFF) {
            return null;
        }

        $value = substr($data, $offset, $length);
        $offset += $length;

        return $value;
    }
}
