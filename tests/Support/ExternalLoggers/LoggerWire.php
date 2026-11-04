<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Support\ExternalLoggers;

use RuntimeException;
use Socket;

/**
 * The external logger's end of the wire, used inside the forked child that
 * plays the logger. It sends real datagrams or a real TCP stream to the
 * listener on loopback, the way the logger's own socket code does.
 */
final class LoggerWire
{
    /** @var array<string, Socket> One UDP socket per sending address */
    private array $udp = [];

    /** @var list<TcpConnection> */
    private array $connections = [];

    public function __construct(public readonly int $port, public readonly string $protocol) {}

    /**
     * Send one datagram, as the logger's sendto() would.
     *
     * @param  string  $from  Loopback address to send from (127.0.0.0/8), to play a different PC
     */
    public function datagram(string $bytes, string $from = '127.0.0.1'): void
    {
        if (! isset($this->udp[$from])) {
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            if ($socket === false || ! socket_bind($socket, $from, 0)) {
                throw new RuntimeException("Could not create a logger UDP socket on {$from}");
            }

            $this->udp[$from] = $socket;
        }

        $sent = socket_sendto($this->udp[$from], $bytes, strlen($bytes), 0, '127.0.0.1', $this->port);

        if ($sent !== strlen($bytes)) {
            throw new RuntimeException('Short UDP send: '.var_export($sent, true).' of '.strlen($bytes));
        }
    }

    /**
     * Send several datagrams back to back, with no pause, like a logger
     * rebroadcasting its log.
     *
     * @param  iterable<string>  $datagrams
     */
    public function burst(iterable $datagrams, string $from = '127.0.0.1'): void
    {
        foreach ($datagrams as $datagram) {
            $this->datagram($datagram, $from);
        }
    }

    /**
     * Wait until the listener has read every datagram queued for it. The
     * listener only checks for a stop between packets, so once its socket
     * queue is empty, everything sent so far has been handled or is being
     * handled.
     */
    public function drain(float $timeoutSeconds = 20.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (WireHarness::udpQueueBytes($this->port) === 0) {
                return;
            }

            usleep(10_000);
        }

        throw new RuntimeException("The listener did not drain its UDP queue within {$timeoutSeconds}s");
    }

    /**
     * Open a TCP connection, as an N3FJP client PC does to its server.
     */
    public function connect(int $readSize = 8192, ?int $receiveBuffer = null): TcpConnection
    {
        return $this->connections[] = new TcpConnection($this->port, $readSize, $receiveBuffer);
    }

    /**
     * Close every TCP connection still open.
     */
    public function disconnect(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
    }

    /**
     * What each connection read, in order: a list of read() chunks per connection.
     *
     * @return list<list<string>>
     */
    public function reads(): array
    {
        return array_map(fn (TcpConnection $connection) => $connection->reads(), $this->connections);
    }

    public function pause(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
