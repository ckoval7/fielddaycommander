<?php

namespace App\Console\Commands;

use App\Contracts\ExternalLoggerListener;
use App\Events\ExternalLoggerStatusChanged;
use App\Exceptions\OutOfPeriodContactException;
use App\Models\EventConfiguration;
use App\Services\AdifContactMapper;
use App\Services\AdifParserService;
use App\Services\ExternalContactHandler;
use App\Services\ExternalLoggerManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Socket;

class UdpAdifListenCommand extends Command implements ExternalLoggerListener
{
    protected $signature = 'external-logger:udp-adif {--event= : Event configuration ID (auto-detects active event if omitted)}';

    protected $description = 'Listen for plain ADIF text over UDP and create contacts (fldigi, etc.)';

    private bool $running = false;

    private ?Socket $socket = null;

    /** WSJTX magic number used to detect and skip binary packets. */
    private const WSJTX_MAGIC = 0xADBCCBDA;

    public function handle(
        AdifParserService $adifParser,
        AdifContactMapper $mapper,
        ExternalContactHandler $handler,
        ExternalLoggerManager $manager,
    ): int {
        $config = $this->resolveEventConfiguration();
        if ($config === null) {
            $this->error('No active event configuration found.');

            return self::FAILURE;
        }

        $setting = $manager->getSetting($config->id, 'udp-adif');
        if ($setting === null || ! $setting->is_enabled) {
            $this->error('UDP ADIF listener is not enabled for this event.');

            return self::FAILURE;
        }

        $port = $setting->port;
        $this->info("Starting UDP ADIF listener on port {$port} for event: {$config->callsign}");

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

        $heartbeatKey = "external-logger:udp-adif:{$config->id}:heartbeat";
        $lastLogKey = "external-logger:udp-adif:{$config->id}:last-log";

        ExternalLoggerStatusChanged::dispatch('udp-adif', 'started', $config->id, $port);

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
                if (! $manager->isEnabled($config->id, 'udp-adif')) {
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

            // Skip WSJTX binary packets (magic number in first 4 bytes)
            if ($bytes >= 4 && unpack('N', substr($buffer, 0, 4))[1] === self::WSJTX_MAGIC) {
                Log::debug('UDP ADIF listener: skipping WSJTX binary packet', ['from' => $from]);

                continue;
            }

            try {
                $adifResult = $adifParser->parse($buffer);
                $records = $adifResult['records'] ?? [];

                if (empty($records)) {
                    continue;
                }

                foreach ($records as $tags) {
                    $dto = $mapper->map($tags, 'udp-adif');

                    if ($dto === null) {
                        continue;
                    }

                    try {
                        $handler->handleContact($dto, $config);
                        Cache::put($lastLogKey, [
                            'callsign' => $dto->callsign,
                            'band' => $dto->bandName,
                            'mode' => $dto->modeName,
                            'qso_time' => $dto->timestamp->toIso8601String(),
                            'section' => $dto->sectionCode,
                            'source' => $this->getType(),
                            'received_at' => now()->toIso8601String(),
                            'accepted' => true,
                            'rejection_reason' => null,
                        ], 60 * 60 * 24);
                    } catch (OutOfPeriodContactException) {
                        Cache::put($lastLogKey, [
                            'callsign' => $dto->callsign,
                            'band' => $dto->bandName,
                            'mode' => $dto->modeName,
                            'qso_time' => $dto->timestamp->toIso8601String(),
                            'section' => $dto->sectionCode,
                            'source' => $this->getType(),
                            'received_at' => now()->toIso8601String(),
                            'accepted' => false,
                            'rejection_reason' => 'outside event window',
                        ], 60 * 60 * 24);
                    }
                    $processedCount++;
                }
            } catch (\Throwable $e) {
                $errorCount++;
                Log::warning("UDP ADIF packet processing error: {$e->getMessage()}", [
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

        ExternalLoggerStatusChanged::dispatch('udp-adif', 'stopped', $config->id, $port);

        $this->info("Stopped. Processed {$processedCount} contacts with {$errorCount} errors.");

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
        return 'udp-adif';
    }

    private function resolveEventConfiguration(): ?EventConfiguration
    {
        if ($this->option('event')) {
            return EventConfiguration::find($this->option('event'));
        }

        return EventConfiguration::where('is_active', true)->first();
    }
}
