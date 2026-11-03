<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Contracts\ExternalLoggerListener;
use App\Events\ExternalLoggerStatusChanged;
use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use App\Services\ExternalContactHandler;
use App\Services\ExternalLoggerManager;
use App\Services\N3fjp\N3fjpContactMapper;
use App\Services\N3fjp\N3fjpFrameDecoder;
use App\Services\N3fjp\N3fjpLogSync;
use App\Services\N3fjp\N3fjpProtocolException;
use App\Services\N3fjp\N3fjpServer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Serves N3FJP's TCP network feature, so N3FJP Field Day and Winter Field
 * Day Contest Log stations log straight into the event as network clients.
 *
 * This command only moves bytes: it accepts connections, splits their
 * traffic into messages for N3fjpServer and writes back what it queues.
 * Writes are buffered per client, so a full-log reload to one slow PC never
 * stalls the others.
 */
class N3fjpListenCommand extends Command implements ExternalLoggerListener
{
    protected $signature = 'external-logger:n3fjp {--event= : Event configuration ID (auto-detects active event if omitted)}';

    protected $description = 'Act as an N3FJP network server so N3FJP contest logs can log into the event';

    /**
     * Most clients served at once; more than any club runs, few enough that
     * a flood of connections can't exhaust the process.
     */
    private const MAX_CLIENTS = 64;

    /**
     * Bytes waiting for one client before it is dropped as stalled. A full
     * reload of a large Field Day log is a few megabytes.
     */
    private const MAX_PENDING_BYTES = 16_777_216;

    private const POLL_INTERVAL_SECONDS = 1;

    private const HEARTBEAT_INTERVAL_SECONDS = 5;

    private bool $running = false;

    /** @var resource|null */
    private $listener = null;

    /** @var array<int, array{stream: resource, decoder: N3fjpFrameDecoder, pending: string, peer: string}> */
    private array $clients = [];

    private int $nextClientId = 1;

    private ?N3fjpServer $server = null;

    private int $messagesReceived = 0;

    private int $errorCount = 0;

    public function getType(): string
    {
        return 'n3fjp';
    }

    public function start(): void
    {
        $this->running = true;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function handle(ExternalLoggerManager $manager, ExternalContactHandler $handler, N3fjpContactMapper $mapper): int
    {
        $config = $this->option('event')
            ? EventConfiguration::find($this->option('event'))
            : EventConfiguration::where('is_active', true)->first();
        $setting = $config ? $manager->getSetting($config->id, $this->getType()) : null;

        if (! $config || ! $setting?->is_enabled) {
            $this->error(! $config ? 'No active event configuration found.' : 'N3FJP server is not enabled for this event.');

            return self::FAILURE;
        }

        $port = $setting->port;
        $setting->update(['pid' => getmypid()]);

        $failure = $this->openListener($port);

        if ($failure !== null) {
            $this->error($failure);
            $setting->update(['pid' => null]);
            $manager->recordStartupFailure($config->id, $this->getType(), $failure);

            return self::FAILURE;
        }

        $manager->clearStartupFailure($config->id, $this->getType());
        $this->info("N3FJP server listening on TCP port {$port} for event: {$config->callsign}");

        $server = new N3fjpServer($config, new N3fjpLogSync($config, $mapper), $mapper, $handler);
        $server->boot();
        $this->server = $server;

        $this->registerSignalHandlers();
        ExternalLoggerStatusChanged::dispatch($this->getType(), 'started', $config->id, $port);

        $this->serve($server, $manager, $config, $port);

        $this->teardown($setting, $config, $port);

        return self::SUCCESS;
    }

    /**
     * @return string|null Why the port could not be opened, or null on success
     */
    private function openListener(int $port): ?string
    {
        $address = (string) config('services.external_loggers.bind_address', '0.0.0.0');
        $errorCode = 0;
        $errorMessage = '';

        $listener = @stream_socket_server("tcp://{$address}:{$port}", $errorCode, $errorMessage);

        if ($listener === false) {
            $hint = $port < 1024 ? ' Ports below 1024 need the CAP_NET_BIND_SERVICE capability.' : '';

            return "Failed to open TCP port {$port}: {$errorMessage}.{$hint}";
        }

        stream_set_blocking($listener, false);
        $this->listener = $listener;

        return null;
    }

    private function serve(N3fjpServer $server, ExternalLoggerManager $manager, EventConfiguration $config, int $port): void
    {
        $startedAt = now()->toIso8601String();
        $heartbeatKey = "external-logger:{$this->getType()}:{$config->id}:heartbeat";
        $lastPoll = 0;
        $lastHeartbeat = 0;

        while ($this->running) {
            $this->waitForTraffic($server);

            $now = time();

            if ($now - $lastPoll >= self::POLL_INTERVAL_SECONDS) {
                $this->guard(fn () => $server->tick());
                $lastPoll = $now;
            }

            $this->route($server->drainOutbox());

            if ($now - $lastHeartbeat >= self::HEARTBEAT_INTERVAL_SECONDS) {
                if (! $manager->isEnabled($config->id, $this->getType())) {
                    $this->info('N3FJP server disabled via settings. Stopping.');

                    break;
                }

                Cache::put($heartbeatKey, [
                    'pid' => getmypid(),
                    'started_at' => $startedAt,
                    'last_heartbeat_at' => now()->toIso8601String(),
                    'packets_received' => $this->messagesReceived,
                    'packets_processed' => $this->messagesReceived,
                    'errors' => $this->errorCount,
                    'clients' => count($this->clients),
                    'port' => $port,
                ], 15);
                $lastHeartbeat = $now;
            }
        }
    }

    /**
     * Wait up to half a second for a connection, data or room to write,
     * then accept, read and write whatever is ready.
     */
    private function waitForTraffic(N3fjpServer $server): void
    {
        $read = [$this->listener];
        $write = [];

        foreach ($this->clients as $client) {
            $read[] = $client['stream'];

            if ($client['pending'] !== '') {
                $write[] = $client['stream'];
            }
        }

        $except = null;

        if (@stream_select($read, $write, $except, 0, 500_000) === false) {
            return;
        }

        foreach ($read as $stream) {
            if ($stream === $this->listener) {
                $this->accept($server);

                continue;
            }

            $this->readFrom($this->clientIdFor($stream), $server);
        }

        foreach ($write as $stream) {
            $this->flush($this->clientIdFor($stream));
        }
    }

    private function accept(N3fjpServer $server): void
    {
        $stream = @stream_socket_accept($this->listener, 0, $peer);

        if ($stream === false) {
            return;
        }

        if (count($this->clients) >= self::MAX_CLIENTS) {
            fclose($stream);
            Log::warning('N3FJP server refused a connection: too many clients', ['peer' => $peer]);

            return;
        }

        stream_set_blocking($stream, false);

        $clientId = $this->nextClientId++;
        $this->clients[$clientId] = [
            'stream' => $stream,
            'decoder' => new N3fjpFrameDecoder,
            'pending' => '',
            'peer' => (string) $peer,
        ];
        $server->connect($clientId, (string) $peer);
    }

    private function readFrom(?int $clientId, N3fjpServer $server): void
    {
        if ($clientId === null) {
            return;
        }

        $bytes = @fread($this->clients[$clientId]['stream'], 65_536);

        if ($bytes === false || ($bytes === '' && feof($this->clients[$clientId]['stream']))) {
            $this->disconnect($clientId);

            return;
        }

        try {
            $messages = $this->clients[$clientId]['decoder']->feed($bytes);
        } catch (N3fjpProtocolException $exception) {
            Log::warning('N3FJP server dropped a client sending unrecognised traffic', [
                'peer' => $this->clients[$clientId]['peer'],
                'error' => $exception->getMessage(),
            ]);
            $this->disconnect($clientId);

            return;
        }

        foreach ($messages as $message) {
            $this->messagesReceived++;
            $this->guard(fn () => $server->receive($clientId, $message));
        }
    }

    /**
     * Queue messages on the clients they're for.
     *
     * @param  list<array{?int, string}>  $outbox
     */
    private function route(array $outbox): void
    {
        foreach ($outbox as [$clientId, $message]) {
            $bytes = N3fjpFrameDecoder::encode($message);
            $targets = $clientId === null ? array_keys($this->clients) : [$clientId];

            foreach ($targets as $target) {
                if (! isset($this->clients[$target])) {
                    continue;
                }

                $this->clients[$target]['pending'] .= $bytes;

                if (strlen($this->clients[$target]['pending']) > self::MAX_PENDING_BYTES) {
                    Log::warning('N3FJP server dropped a client that stopped reading', ['peer' => $this->clients[$target]['peer']]);
                    $this->disconnect($target);
                }
            }
        }

        foreach (array_keys($this->clients) as $clientId) {
            $this->flush($clientId);
        }
    }

    private function flush(?int $clientId): void
    {
        if ($clientId === null || ! isset($this->clients[$clientId]) || $this->clients[$clientId]['pending'] === '') {
            return;
        }

        $written = @fwrite($this->clients[$clientId]['stream'], $this->clients[$clientId]['pending']);

        if ($written === false) {
            $this->disconnect($clientId);

            return;
        }

        $this->clients[$clientId]['pending'] = (string) substr($this->clients[$clientId]['pending'], $written);
    }

    /**
     * @param  resource  $stream
     */
    private function clientIdFor($stream): ?int
    {
        foreach ($this->clients as $clientId => $client) {
            if ($client['stream'] === $stream) {
                return $clientId;
            }
        }

        return null;
    }

    private function disconnect(int $clientId): void
    {
        if (! isset($this->clients[$clientId])) {
            return;
        }

        @fclose($this->clients[$clientId]['stream']);
        unset($this->clients[$clientId]);
        $this->server?->disconnect($clientId);
    }

    /**
     * Run one step of message handling, logging a failure instead of
     * letting one bad message stop the server.
     */
    private function guard(callable $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            $this->errorCount++;
            Log::warning("N3FJP server error: {$exception->getMessage()}", ['exception' => $exception::class]);
        }
    }

    private function registerSignalHandlers(): void
    {
        $this->running = true;

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->running = false);
            pcntl_signal(SIGINT, fn () => $this->running = false);
        }
    }

    private function teardown(ExternalLoggerSetting $setting, EventConfiguration $config, int $port): void
    {
        foreach (array_keys($this->clients) as $clientId) {
            $this->disconnect($clientId);
        }

        if ($this->listener !== null) {
            fclose($this->listener);
            $this->listener = null;
        }

        $setting->update(['pid' => null]);
        Cache::forget("external-logger:{$this->getType()}:{$config->id}:heartbeat");
        ExternalLoggerStatusChanged::dispatch($this->getType(), 'stopped', $config->id, $port);

        $this->info("Stopped after {$this->messagesReceived} messages with {$this->errorCount} errors.");
    }
}
