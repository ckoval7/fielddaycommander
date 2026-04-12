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
    private bool $running = false;

    private ?Socket $socket = null;

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
        if ($config === null) {
            $this->error('No active event configuration found.');

            return self::FAILURE;
        }

        $setting = $manager->getSetting($config->id, $this->getType());
        if ($setting === null || ! $setting->is_enabled) {
            $this->error("{$this->listenerLabel()} listener is not enabled for this event.");

            return self::FAILURE;
        }

        $port = $setting->port;
        $this->info("Starting {$this->listenerLabel()} UDP listener on port {$port} for event: {$config->callsign}");
        $setting->update(['pid' => getmypid()]);

        if (! $this->createAndBindSocket($port)) {
            $setting->update(['pid' => null]);

            return self::FAILURE;
        }

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

    private function createAndBindSocket(int $port): bool
    {
        $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->socket === false) {
            $this->error('Failed to create UDP socket: '.socket_strerror(socket_last_error()));

            return false;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (@socket_bind($this->socket, '0.0.0.0', $port) === false) {
            $this->error("Failed to bind to port {$port}: ".socket_strerror(socket_last_error($this->socket)));
            socket_close($this->socket);

            return false;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        return true;
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
