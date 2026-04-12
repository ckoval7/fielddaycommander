<?php

namespace App\Console\Commands;

use App\Exceptions\OutOfPeriodContactException;
use App\Models\EventConfiguration;
use App\Services\AdifContactMapper;
use App\Services\AdifParserService;
use App\Services\ExternalContactHandler;
use App\Services\ExternalLoggerManager;
use App\Services\WsjtxPacketParser;
use Illuminate\Support\Facades\Log;

class WsjtxListenCommand extends BaseUdpListenerCommand
{
    protected $signature = 'external-logger:wsjtx {--event= : Event configuration ID (auto-detects active event if omitted)}';

    protected $description = 'Listen for WSJTX UDP broadcasts and create contacts from ADIF data';

    private WsjtxPacketParser $wsjtxParser;

    private AdifParserService $adifParser;

    private AdifContactMapper $mapper;

    private ExternalContactHandler $handler;

    public function getType(): string
    {
        return 'wsjtx';
    }

    protected function listenerLabel(): string
    {
        return 'WSJTX';
    }

    public function handle(
        WsjtxPacketParser $wsjtxParser,
        AdifParserService $adifParser,
        AdifContactMapper $mapper,
        ExternalContactHandler $handler,
        ExternalLoggerManager $manager,
    ): int {
        $this->wsjtxParser = $wsjtxParser;
        $this->adifParser = $adifParser;
        $this->mapper = $mapper;
        $this->handler = $handler;

        return $this->runListener($manager);
    }

    protected function processPacket(string $buffer, string $from, EventConfiguration $config, string $lastLogKey): int
    {
        $parsed = $this->wsjtxParser->parse($buffer);

        // Skip non-QSO packets (heartbeats return array, unknown return null)
        if (! is_string($parsed)) {
            if (is_array($parsed)) {
                Log::debug('WSJTX heartbeat received', $parsed);
            }

            return 0;
        }

        $adifResult = $this->adifParser->parse($parsed);
        $tags = ($adifResult['records'] ?? [])[0] ?? null;
        $dto = $tags !== null ? $this->mapper->map($tags, 'wsjtx') : null;

        if ($dto === null) {
            return 0;
        }

        try {
            $this->handler->handleContact($dto, $config);
            $this->writeLastLogEntry($lastLogKey, $dto, true);
        } catch (OutOfPeriodContactException) {
            $this->writeLastLogEntry($lastLogKey, $dto, false, 'outside event window');
        }

        return 1;
    }
}
