<?php

namespace App\Console\Commands;

use App\Contracts\ExternalLoggerListener;
use App\DTOs\ExternalContactDto;
use App\Events\ExternalLoggerStatusChanged;
use App\Models\EventConfiguration;
use App\Models\ExternalLoggerSetting;
use App\Services\ExternalLoggerManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Socket;

abstract class BaseUdpListenerCommand extends Command implements ExternalLoggerListener
{
    private const WARNING_WINDOW_SECONDS = 60;

    private const WARNINGS_PER_WINDOW = 10;

    private bool $running = false;

    private ?Socket $socket = null;

    /** @var array<string, array{started_at: int, logged: int, suppressed: int}> Throttle state per warning key */
    private array $warningWindows = [];

    /**
     * Human-readable label for log messages (e.g. "N1MM", "WSJTX", "UDP ADIF").
     */
    abstract protected function listenerLabel(): string;

    /**
     * Process a single received UDP packet.
     *
     * @return int Number of items successfully processed from this packet
     */
    abstract protected function processPacket(
        string $buffer,
        string $from,
        EventConfiguration $config,
        string $lastLogKey,
    ): int;

    /**
     * Common UDP listener lifecycle: resolve config, open socket, run loop, teardown.
     */
    protected function runListener(ExternalLoggerManager $manager): int
    {
        $config = $this->resolveEventConfiguration();
        $setting = $config ? $manager->getSetting($config->id, $this->getType()) : null;

        if (! $config || ! $setting?->is_enabled) {
            $this->error(! $config
                ? 'No active event configuration found.'
                : "{$this->listenerLabel()} listener is not enabled for this event.");

            return self::FAILURE;
        }

        $port = $setting->port;
        $this->info("Starting {$this->listenerLabel()} UDP listener on port {$port} for event: {$config->callsign}");
        $setting->update(['pid' => getmypid()]);

        try {
            $failure = $this->createAndBindSocket($port);
        } catch (\Throwable $exception) {
            $failure = "Failed to open UDP socket: {$exception->getMessage()}";
        }

        if ($failure !== null) {
            $this->error($failure);
            $setting->update(['pid' => null]);
            $manager->recordStartupFailure($config->id, $this->getType(), $failure);

            return self::FAILURE;
        }

        $manager->clearStartupFailure($config->id, $this->getType());

        $this->registerSignalHandlers();

        $heartbeatKey = "external-logger:{$this->getType()}:{$config->id}:heartbeat";
        $lastLogKey = "external-logger:{$this->getType()}:{$config->id}:last-log";

        ExternalLoggerStatusChanged::dispatch($this->getType(), 'started', $config->id, $port);

        [$processedCount, $errorCount] = $this->listenLoop($manager, $config, $port, $heartbeatKey, $lastLogKey);

        $this->teardown($setting, $heartbeatKey, $config, $port, $processedCount, $errorCount);

        return self::SUCCESS;
    }

    /**
     * Write a last-log cache entry for a processed contact DTO.
     */
    protected function writeLastLogEntry(
        string $cacheKey,
        ExternalContactDto $dto,
        bool $accepted,
        ?string $rejectionReason = null,
    ): void {
        Cache::put($cacheKey, [
            'callsign' => $dto->callsign,
            'band' => $dto->bandName,
            'mode' => $dto->modeName,
            'qso_time' => $dto->timestamp->toIso8601String(),
            'section' => $dto->sectionCode,
            'source' => $this->getType(),
            'received_at' => now()->toIso8601String(),
            'accepted' => $accepted,
            'rejection_reason' => $rejectionReason,
        ], 60 * 60 * 24);
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

    private function resolveEventConfiguration(): ?EventConfiguration
    {
        if ($this->option('event')) {
            return EventConfiguration::find($this->option('event'));
        }

        return EventConfiguration::where('is_active', true)->first();
    }

    /**
     * Open and bind the UDP socket.
     *
     * @return string|null Why the socket could not be opened, or null on success
     */
    private function createAndBindSocket(int $port): ?string
    {
        if (! $this->socketsAvailable()) {
            return 'The PHP sockets extension is not installed, so UDP listeners cannot run.';
        }

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return 'Failed to create UDP socket: '.socket_strerror(socket_last_error());
        }

        $this->socket = $socket;

        return $this->bindSocket($port);
    }

    /**
     * Bind the created socket to the configured address and port.
     *
     * @return string|null Why the socket could not be bound, or null on success
     */
    private function bindSocket(int $port): ?string
    {
        socket_set_option($this->socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        $bindAddress = (string) config('services.external_loggers.bind_address', '0.0.0.0');

        if (@socket_bind($this->socket, $bindAddress, $port) === false) {
            $reason = "Failed to bind to port {$port}: ".socket_strerror(socket_last_error($this->socket));
            socket_close($this->socket);
            $this->socket = null;

            return $reason;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        return null;
    }

    /**
     * Whether the sockets extension is loaded. Overridable so tests can
     * simulate an image built without it.
     */
    protected function socketsAvailable(): bool
    {
        return function_exists('socket_create');
    }

    /**
     * Log a warning, but at most WARNINGS_PER_WINDOW times per minute per
     * key, so a noisy or hostile sender cannot flood the log. The first
     * warning of the next window reports how many were suppressed.
     *
     * @param  array<string, mixed>  $context
     */
    protected function throttledWarning(string $key, string $message, array $context = []): void
    {
        $now = time();
        $window = $this->warningWindows[$key] ?? ['started_at' => $now, 'logged' => 0, 'suppressed' => 0];

        if ($now - $window['started_at'] >= self::WARNING_WINDOW_SECONDS) {
            if ($window['suppressed'] > 0) {
                $context['suppressed_in_previous_minute'] = $window['suppressed'];
            }

            $window = ['started_at' => $now, 'logged' => 0, 'suppressed' => 0];
        }

        if ($window['logged'] >= self::WARNINGS_PER_WINDOW) {
            $window['suppressed']++;
            $this->warningWindows[$key] = $window;

            return;
        }

        Log::warning($message, $context);

        $window['logged']++;
        $this->warningWindows[$key] = $window;
    }

    /**
     * Record a contact rejected for falling outside the event window: shown
     * as the listener's last log entry and logged (throttled) with its call
     * and timestamp.
     */
    protected function rejectOutOfPeriod(string $lastLogKey, ExternalContactDto $dto): void
    {
        $this->writeLastLogEntry($lastLogKey, $dto, false, 'outside event window');

        $this->throttledWarning('out-of-window', "{$this->listenerLabel()} contact rejected: QSO time is outside the event window", [
            'callsign' => mb_substr($dto->callsign, 0, 40),
            'qso_time' => $dto->timestamp->toIso8601String(),
            'external_id' => $dto->externalId,
        ]);
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

    /**
     * @return array{int, int} [processedCount, errorCount]
     */
    private function listenLoop(
        ExternalLoggerManager $manager,
        EventConfiguration $config,
        int $port,
        string $heartbeatKey,
        string $lastLogKey,
    ): array {
        $packetCount = 0;
        $processedCount = 0;
        $errorCount = 0;
        $startedAt = now()->toIso8601String();
        $lastPacketAt = null;
        $lastHeartbeatTime = 0;

        while ($this->running) {
            $now = time();
            if ($now - $lastHeartbeatTime >= 5) {
                $config->unsetRelation('event');
                $config->refresh();

                Cache::put($heartbeatKey, [
                    'pid' => getmypid(),
                    'started_at' => $startedAt,
                    'last_heartbeat_at' => now()->toIso8601String(),
                    'packets_received' => $packetCount,
                    'packets_processed' => $processedCount,
                    'errors' => $errorCount,
                    'last_packet_at' => $lastPacketAt,
                    'port' => $port,
                ], 15);
                $lastHeartbeatTime = $now;
            }

            if ($packetCount % 50 === 0 && $packetCount > 0 && ! $manager->isEnabled($config->id, $this->getType())) {
                $this->info('Listener disabled via settings. Stopping.');
                break;
            }

            $buffer = '';
            $from = '';
            $fromPort = 0;
            $bytes = @socket_recvfrom($this->socket, $buffer, 65535, 0, $from, $fromPort);

            if ($bytes === false) {
                continue;
            }

            $packetCount++;
            $lastPacketAt = now()->toIso8601String();

            try {
                $processedCount += $this->processPacket($buffer, $from, $config, $lastLogKey);
            } catch (\Throwable $e) {
                $errorCount++;
                Log::warning("{$this->listenerLabel()} packet processing error: {$e->getMessage()}", [
                    'from' => $from,
                    'packet_number' => $packetCount,
                ]);
            }
        }

        return [$processedCount, $errorCount];
    }

    private function teardown(
        ExternalLoggerSetting $setting,
        string $heartbeatKey,
        EventConfiguration $config,
        int $port,
        int $processedCount,
        int $errorCount,
    ): void {
        socket_close($this->socket);
        $this->socket = null;

        $setting->update(['pid' => null]);
        Cache::forget($heartbeatKey);

        ExternalLoggerStatusChanged::dispatch($this->getType(), 'stopped', $config->id, $port);

        $this->info("Stopped. Processed {$processedCount} packets with {$errorCount} errors.");
    }
}
