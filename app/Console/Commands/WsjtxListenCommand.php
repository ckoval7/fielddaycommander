<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Exceptions\InvalidExternalContactException;
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

    /**
     * Ids WSJT-X and JTDX use when started without --rig-name.
     *
     * @var list<string>
     */
    private const DEFAULT_CLIENT_IDS = ['WSJT-X', 'JTDX'];

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
        $dto = $tags !== null ? $this->mapper->map($tags, 'wsjtx', $this->stationFor($buffer, $from)) : null;

        if ($dto === null) {
            return 0;
        }

        try {
            $this->handler->handleContact($dto, $config);
            $this->writeLastLogEntry($lastLogKey, $dto, true);
        } catch (OutOfPeriodContactException) {
            $this->rejectOutOfPeriod($lastLogKey, $dto);
        } catch (InvalidExternalContactException $exception) {
            $this->writeLastLogEntry($lastLogKey, $dto, false, $exception->rejectionReason);
        }

        return 1;
    }

    /**
     * The station a WSJT-X instance logs as. STATION_CALLSIGN is the club
     * call on every PC at Field Day, so it can't tell them apart. An
     * instance started with --rig-name has a unique id ("WSJT-X - IC7300");
     * a default one ("WSJT-X", "JTDX") is told apart by the PC it runs on.
     */
    private function stationFor(string $buffer, string $from): ?string
    {
        $id = $this->wsjtxParser->clientId($buffer);

        if ($id === null) {
            return null;
        }

        $id = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $id)), 0, 50);

        if (in_array(strtoupper($id), self::DEFAULT_CLIENT_IDS, true) && $from !== '') {
            return "{$id} @ {$from}";
        }

        return $id !== '' ? $id : null;
    }
}
