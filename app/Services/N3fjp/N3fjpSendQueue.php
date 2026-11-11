<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

/**
 * What is waiting to be written to one N3FJP client.
 *
 * Messages wait unencoded and are encoded a piece at a time as the client
 * reads, so a full log of any size reaches a client that keeps reading,
 * and a reload sent to every client shares one copy of the records.
 *
 * The oldest full log still being sent doesn't count against the limit;
 * everything queued behind or beside it does. A client that stops reading
 * is still found out once other traffic piles up, while a late joiner to a
 * big log isn't mistaken for one.
 */
final class N3fjpSendQueue
{
    /** Encoded bytes kept ready to write; topped up as the client reads. */
    public const READY_BYTES = 262_144;

    private string $ready = '';

    /**
     * Runs of messages in send order: a full log, or a single message.
     *
     * @var array<int, array{messages: list<string>, next: int, bytes: int, fullLog: bool}>
     */
    private array $runs = [];

    private int $head = 0;

    private int $tail = 0;

    /** Estimated encoded bytes of every run still waiting. */
    private int $waitingBytes = 0;

    /** The oldest full log still waiting, which doesn't count against the limit. */
    private ?int $exemptRun = null;

    public function __construct(private int $limitBytes) {}

    public function push(string $message): void
    {
        $this->append([$message], false);
    }

    /**
     * @param  list<string>  $messages
     */
    public function pushFullLog(array $messages): void
    {
        if ($messages !== []) {
            $this->append($messages, true);
        }
    }

    public function isEmpty(): bool
    {
        return $this->ready === '' && $this->head === $this->tail;
    }

    /**
     * Whether more is waiting than a client that is reading would leave.
     */
    public function isOverLimit(): bool
    {
        $exempt = $this->exemptRun === null ? 0 : $this->runs[$this->exemptRun]['bytes'];

        return $this->waitingBytes - $exempt > $this->limitBytes;
    }

    /**
     * The encoded bytes to write next.
     */
    public function nextBytes(): string
    {
        while (strlen($this->ready) < self::READY_BYTES && $this->head !== $this->tail) {
            $this->ready .= N3fjpFrameDecoder::encode($this->takeMessage());
        }

        return $this->ready;
    }

    /**
     * Drop bytes the client has been sent.
     */
    public function sent(int $bytes): void
    {
        $this->ready = (string) substr($this->ready, $bytes);
    }

    /**
     * @param  list<string>  $messages
     */
    private function append(array $messages, bool $fullLog): void
    {
        $bytes = 0;

        foreach ($messages as $message) {
            $bytes += self::size($message);
        }

        if ($fullLog && $this->exemptRun === null) {
            $this->exemptRun = $this->tail;
        }

        $this->runs[$this->tail++] = ['messages' => $messages, 'next' => 0, 'bytes' => $bytes, 'fullLog' => $fullLog];
        $this->waitingBytes += $bytes;
    }

    private function takeMessage(): string
    {
        $run = &$this->runs[$this->head];
        $message = $run['messages'][$run['next']++];
        $size = self::size($message);
        $run['bytes'] -= $size;
        $this->waitingBytes -= $size;

        if ($run['next'] === count($run['messages'])) {
            unset($run, $this->runs[$this->head]);

            if ($this->exemptRun === $this->head) {
                $this->exemptRun = $this->nextFullLog($this->head + 1);
            }

            $this->head++;
        }

        return $message;
    }

    private function nextFullLog(int $from): ?int
    {
        for ($index = $from; $index < $this->tail; $index++) {
            if ($this->runs[$index]['fullLog']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A message's size on the wire, counting each character as one UTF-16
     * unit: exact for the ASCII nearly every record is.
     */
    private static function size(string $message): int
    {
        return 2 * (strlen($message) + strlen(N3fjpFrameDecoder::TERMINATOR));
    }
}
