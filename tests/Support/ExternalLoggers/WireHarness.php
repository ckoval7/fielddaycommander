<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Tests\Support\ExternalLoggers;

use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

/**
 * Runs a real external logger listener command, bound to a real loopback
 * socket, while a forked child process plays the external logger on the
 * other end of the wire.
 *
 * The listener runs in the test process, so it writes to the test's
 * database. The child only touches sockets: it waits for the listener to
 * bind, runs the logger script, waits for the listener to read everything
 * sent, then stops the listener with SIGTERM, the way the process manager
 * does in production.
 */
final class WireHarness
{
    /**
     * Run $command for $config while $logger plays the external logger.
     *
     * @param  string  $command  e.g. 'external-logger:n1mm'
     * @param  'udp'|'tcp'  $protocol
     * @param  callable(LoggerWire): void  $logger  Runs in the child process; only sockets, no Laravel
     */
    public static function run(string $command, EventConfiguration $config, string $listenerType, string $protocol, callable $logger): WireRun
    {
        if (! function_exists('pcntl_fork') || ! is_dir('/proc/net')) {
            throw new RuntimeException('Wire tests need pcntl and Linux /proc/net');
        }

        $port = $protocol === 'udp' ? self::freeUdpPort() : self::freeTcpPort();
        config(['services.external_loggers.bind_address' => '127.0.0.1']);

        ExternalLoggerSetting::updateOrCreate(
            ['event_configuration_id' => $config->id, 'listener_type' => $listenerType],
            ['is_enabled' => true, 'port' => $port],
        );

        $transcript = tempnam(sys_get_temp_dir(), 'fdc-wire-');

        // Until the listener installs its own handlers, a SIGTERM from the
        // child must not kill the test runner.
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static fn () => null);

        $parentPid = getmypid();
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            self::playLogger($parentPid, $port, $protocol, $logger, $transcript);
        }

        try {
            $exitCode = Artisan::call($command, ['--event' => $config->id]);
            $output = Artisan::output();
        } finally {
            pcntl_waitpid($pid, $status);
            pcntl_signal(SIGTERM, SIG_DFL);
        }

        /** @var array{reads: list<list<string>>, error: ?string, drops: int} $result */
        $result = json_decode((string) file_get_contents($transcript), true) ?? ['reads' => [], 'error' => 'The logger process left no transcript', 'drops' => 0];
        @unlink($transcript);

        if ($result['error'] !== null) {
            throw new RuntimeException("The emulated logger failed: {$result['error']}");
        }

        $reads = array_map(fn (array $chunks) => array_map('base64_decode', $chunks), $result['reads']);

        return new WireRun($exitCode, $output, $reads, $result['drops'], $port);
    }

    /**
     * The child process: wait for the listener, play the logger, stop the
     * listener, and exit without running any of the test runner's shutdown
     * code.
     *
     * @param  callable(LoggerWire): void  $logger
     */
    private static function playLogger(int $parentPid, int $port, string $protocol, callable $logger, string $transcript): never
    {
        $error = null;
        $wire = new LoggerWire($port, $protocol);
        $drops = 0;

        try {
            self::waitFor(fn () => self::isListening($port, $protocol), 15, "the listener to open {$protocol} port {$port}");
            $logger($wire);

            if ($protocol === 'udp') {
                $wire->drain();
                $drops = self::udpDrops($port);
            }

            $wire->disconnect();
        } catch (Throwable $exception) {
            $error = $exception::class.': '.$exception->getMessage();
        }

        file_put_contents($transcript, json_encode([
            'reads' => array_map(fn (array $chunks) => array_map('base64_encode', $chunks), $wire->reads()),
            'error' => $error,
            'drops' => $drops,
        ]));

        // Keep signalling until the listener has closed its socket: a signal
        // that lands before the listener installs its handler is swallowed.
        for ($attempt = 0; $attempt < 60 && self::isListening($port, $protocol); $attempt++) {
            posix_kill($parentPid, SIGTERM);
            usleep(250_000);
        }

        posix_kill(getmypid(), SIGKILL);

        exit(1);
    }

    private static function waitFor(callable $condition, float $seconds, string $what): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(10_000);
        }

        throw new RuntimeException("Timed out waiting for {$what}");
    }

    public static function freeUdpPort(): int
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $address, $port);
        socket_close($socket);

        return $port;
    }

    public static function freeTcpPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    /**
     * Whether something is bound to (UDP) or listening on (TCP) the port.
     */
    public static function isListening(int $port, string $protocol): bool
    {
        return self::procEntry($port, $protocol, $protocol === 'tcp' ? '0A' : null) !== null;
    }

    /**
     * Bytes waiting in the listener's UDP receive queue.
     */
    public static function udpQueueBytes(int $port): ?int
    {
        $entry = self::procEntry($port, 'udp');

        return $entry !== null ? hexdec(explode(':', $entry[4])[1]) : null;
    }

    /**
     * Datagrams the kernel dropped because the listener's queue was full.
     */
    public static function udpDrops(int $port): int
    {
        $entry = self::procEntry($port, 'udp');

        return $entry !== null ? (int) end($entry) : 0;
    }

    /**
     * The /proc/net row for a loopback or wildcard socket on the port.
     *
     * @return list<string>|null
     */
    private static function procEntry(int $port, string $protocol, ?string $state = null): ?array
    {
        $lines = @file("/proc/net/{$protocol}", FILE_IGNORE_NEW_LINES) ?: [];
        $suffix = sprintf(':%04X', $port);

        foreach (array_slice($lines, 1) as $line) {
            $columns = preg_split('/\s+/', trim($line));

            if (! str_ends_with($columns[1], $suffix)) {
                continue;
            }

            if ($state !== null && $columns[3] !== $state) {
                continue;
            }

            return $columns;
        }

        return null;
    }
}
