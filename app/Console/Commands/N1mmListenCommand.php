<?php

namespace App\Console\Commands;

use App\Contracts\ExternalLoggerListener;
use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Events\ExternalLoggerStatusChanged;
use App\Models\EventConfiguration;
use App\Services\ExternalContactHandler;
use App\Services\ExternalLoggerManager;
use App\Services\N1mmPacketParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Socket;

class N1mmListenCommand extends Command implements ExternalLoggerListener
{
    protected $signature = 'external-logger:n1mm {--event= : Event configuration ID (auto-detects active event if omitted)}';

    protected $description = 'Listen for N1MM Logger+ UDP broadcasts and create contacts';

    private bool $running = false;

    private ?Socket $socket = null;

    public function handle(
        N1mmPacketParser $parser,
        ExternalContactHandler $handler,
        ExternalLoggerManager $manager,
    ): int {
        $config = $this->resolveEventConfiguration();
        if ($config === null) {
            $this->error('No active event configuration found.');

            return self::FAILURE;
        }

        $setting = $manager->getSetting($config->id, 'n1mm');
        if ($setting === null || ! $setting->is_enabled) {
            $this->error('N1MM listener is not enabled for this event.');

            return self::FAILURE;
        }

        $port = $setting->port;
        $this->info("Starting N1MM UDP listener on port {$port} for event: {$config->callsign}");

        // Store PID in database
        $setting->update(['pid' => getmypid()]);

        $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->socket === false) {
            $this->error('Failed to create UDP socket: '.socket_strerror(socket_last_error()));
            $setting->update(['pid' => null]);

            return self::FAILURE;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (@socket_bind($this->socket, '0.0.0.0', $port) === false) {
            $this->error("Failed to bind to port {$port}: ".socket_strerror(socket_last_error($this->socket)));
            socket_close($this->socket);
            $setting->update(['pid' => null]);

            return self::FAILURE;
        }

        // Set read timeout so we can check for shutdown signals
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

        $this->running = true;
        $packetCount = 0;
        $processedCount = 0;
        $errorCount = 0;
        $startedAt = now()->toIso8601String();
        $lastPacketAt = null;
        $lastHeartbeatTime = 0;

        // Handle graceful shutdown
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->running = false);
            pcntl_signal(SIGINT, fn () => $this->running = false);
        }

        $heartbeatKey = "external-logger:n1mm:{$config->id}:heartbeat";

        ExternalLoggerStatusChanged::dispatch('n1mm', 'started', $config->id, $port);

        while ($this->running) {
            // Write heartbeat every 5 seconds
            $now = time();
            if ($now - $lastHeartbeatTime >= 5) {
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

            // Check if still enabled periodically
            if ($packetCount % 50 === 0 && $packetCount > 0) {
                if (! $manager->isEnabled($config->id, 'n1mm')) {
                    $this->info('Listener disabled via settings. Stopping.');
                    break;
                }
            }

            $buffer = '';
            $from = '';
            $fromPort = 0;
            $bytes = @socket_recvfrom($this->socket, $buffer, 65535, 0, $from, $fromPort);

            if ($bytes === false) {
                // Timeout — just continue the loop
                continue;
            }

            $packetCount++;
            $lastPacketAt = now()->toIso8601String();

            try {
                $dto = $parser->parse($buffer);

                if ($dto === null) {
                    continue;
                }

                if ($dto instanceof ExternalContactDto) {
                    if ($dto->isDelete) {
                        $handler->handleDelete($dto, $config);
                    } elseif ($dto->isReplace) {
                        $handler->handleReplace($dto, $config);
                    } else {
                        $handler->handleContact($dto, $config);
                    }
                } elseif ($dto instanceof ExternalRadioInfoDto) {
                    $handler->handleRadioInfo($dto, $config);
                }

                $processedCount++;
            } catch (\Throwable $e) {
                $errorCount++;
                Log::warning("N1MM packet processing error: {$e->getMessage()}", [
                    'from' => $from,
                    'packet_number' => $packetCount,
                ]);
            }
        }

        socket_close($this->socket);
        $this->socket = null;

        // Clear PID and heartbeat on graceful shutdown
        $setting->update(['pid' => null]);
        Cache::forget($heartbeatKey);

        ExternalLoggerStatusChanged::dispatch('n1mm', 'stopped', $config->id, $port);

        $this->info("Stopped. Processed {$processedCount} packets with {$errorCount} errors.");

        return self::SUCCESS;
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

    public function getType(): string
    {
        return 'n1mm';
    }

    private function resolveEventConfiguration(): ?EventConfiguration
    {
        if ($this->option('event')) {
            return EventConfiguration::find($this->option('event'));
        }

        return EventConfiguration::where('is_active', true)->first();
    }
}
