<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Support\ExternalLoggers;

use RuntimeException;

/**
 * One client PC's TCP connection to the listener, inside the forked logger
 * process. Reads at most $readSize bytes at a time and keeps each read
 * separately, so a test can decode them the way the real client does.
 */
final class TcpConnection
{
    /** @var resource|null */
    private $stream;

    /** @var list<string> */
    private array $reads = [];

    /**
     * @param  int|null  $receiveBuffer  SO_RCVBUF for the client socket; a small one makes the server's writes back up, as on a busy or slow PC
     */
    public function __construct(int $port, private readonly int $readSize = 8192, ?int $receiveBuffer = null)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($receiveBuffer !== null) {
            socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, $receiveBuffer);
        }

        if (@socket_connect($socket, '127.0.0.1', $port) === false) {
            throw new RuntimeException("Could not connect to port {$port}: ".socket_strerror(socket_last_error($socket)));
        }

        $stream = socket_export_stream($socket);

        stream_set_blocking($stream, false);
        $this->stream = $stream;
    }

    /**
     * Write bytes, all of them, as one blocking write on the client would.
     */
    public function write(string $bytes): void
    {
        $remaining = $bytes;
        $deadline = microtime(true) + 10;

        while ($remaining !== '' && microtime(true) < $deadline) {
            $written = @fwrite($this->stream, $remaining);

            if ($written === false) {
                throw new RuntimeException('The server closed the connection while the client was writing');
            }

            $remaining = (string) substr($remaining, $written);

            if ($remaining !== '') {
                $this->pump(0.01);
            }
        }
    }

    /**
     * Send messages, each framed and written separately.
     */
    public function send(string ...$messages): void
    {
        foreach ($messages as $message) {
            $this->write(N3fjp::frame($message));
        }
    }

    /**
     * Read until $until is true of the decoded messages so far, or until the
     * server has been quiet for $quietSeconds when there is no condition.
     *
     * @param  (callable(list<string>): bool)|null  $until
     */
    public function read(?callable $until = null, float $quietSeconds = 0.5, float $timeoutSeconds = 15.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $lastData = microtime(true);

        while (microtime(true) < $deadline) {
            if ($until !== null && $until(N3fjp::messages($this->received()))) {
                return;
            }

            if ($this->pump(0.05)) {
                $lastData = microtime(true);
            } elseif ($until === null && microtime(true) - $lastData >= $quietSeconds) {
                return;
            }

            if ($this->closedByServer()) {
                return;
            }
        }

        if ($until !== null) {
            throw new RuntimeException('The server never sent what the client waited for. Got: '.implode(' | ', N3fjp::transactions(N3fjp::messages($this->received()))));
        }
    }

    /**
     * Read until the server has sent $count messages containing $needle.
     */
    public function readUntilCount(string $needle, int $count, float $timeoutSeconds = 30.0): void
    {
        $this->read(fn (array $messages) => count(array_filter($messages, fn (string $m) => str_contains($m, $needle))) >= $count, timeoutSeconds: $timeoutSeconds);
    }

    /**
     * Stop reading for a while, like a client stuck behind a modal dialog.
     */
    public function stall(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }

    public function closedByServer(): bool
    {
        return $this->stream === null || feof($this->stream);
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public function received(): string
    {
        return implode('', $this->reads);
    }

    /**
     * @return list<string>
     */
    public function reads(): array
    {
        return $this->reads;
    }

    private function pump(float $waitSeconds): bool
    {
        if ($this->stream === null) {
            return false;
        }

        $read = [$this->stream];
        $write = null;
        $except = null;

        if (@stream_select($read, $write, $except, 0, (int) ($waitSeconds * 1_000_000)) < 1) {
            return false;
        }

        $bytes = @fread($this->stream, $this->readSize);

        if ($bytes === false || $bytes === '') {
            return false;
        }

        $this->reads[] = $bytes;

        return true;
    }
}
