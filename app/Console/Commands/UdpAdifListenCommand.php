<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\Exceptions\InvalidExternalContactException;
use App\Exceptions\OutOfPeriodContactException;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Services\AdifContactMapper;
use App\Services\AdifParserService;
use App\Services\ExternalContactHandler;
use App\Services\ExternalLoggerManager;
use Illuminate\Support\Facades\Log;

class UdpAdifListenCommand extends BaseUdpListenerCommand
{
    protected $signature = 'external-logger:udp-adif {--event= : Event configuration ID (auto-detects active event if omitted)}';

    protected $description = 'Listen for plain ADIF text over UDP and create contacts (fldigi, etc.)';

    /** WSJTX magic number used to detect and skip binary packets. */
    private const WSJTX_MAGIC = 0xADBCCBDA;

    private AdifParserService $adifParser;

    private AdifContactMapper $mapper;

    private ExternalContactHandler $handler;

    public function getType(): string
    {
        return 'udp-adif';
    }

    protected function listenerLabel(): string
    {
        return 'UDP ADIF';
    }

    public function handle(
        AdifParserService $adifParser,
        AdifContactMapper $mapper,
        ExternalContactHandler $handler,
        ExternalLoggerManager $manager,
    ): int {
        $this->adifParser = $adifParser;
        $this->mapper = $mapper;
        $this->handler = $handler;

        return $this->runListener($manager);
    }

    protected function processPacket(string $buffer, string $from, EventConfiguration $config, string $lastLogKey): int
    {
        if (strlen($buffer) >= 4 && unpack('N', substr($buffer, 0, 4))[1] === self::WSJTX_MAGIC) {
            Log::debug('UDP ADIF listener: skipping WSJTX binary packet', ['from' => $from]);

            return 0;
        }

        $adifResult = $this->adifParser->parse($buffer);
        $records = $adifResult['records'] ?? [];

        if (empty($records)) {
            return 0;
        }

        $count = 0;
        foreach ($records as $tags) {
            $dto = $this->mapper->map($tags, 'udp-adif', $this->stationFor($tags, $from, $config));

            if ($dto === null) {
                continue;
            }

            try {
                $this->handler->handleContact($dto, $config);
                $this->writeLastLogEntry($lastLogKey, $dto, true);
            } catch (OutOfPeriodContactException) {
                $this->rejectOutOfPeriod($lastLogKey, $dto);
            } catch (InvalidExternalContactException $exception) {
                $this->writeLastLogEntry($lastLogKey, $dto, false, $exception->rejectionReason);
            }
            $count++;
        }

        return $count;
    }

    /**
     * The station a record was logged at. fldigi and most ADIF senders put
     * the club call in STATION_CALLSIGN on every PC, so that alone would
     * merge them all into one station; each sending PC gets its own
     * ("W2XYZ @ 192.168.1.23") unless a station is already set up with
     * STATION_CALLSIGN as its name or hostname.
     *
     * @param  array<string, string>  $tags
     */
    private function stationFor(array $tags, string $from, EventConfiguration $config): ?string
    {
        $stationCallsign = trim($tags['STATION_CALLSIGN'] ?? '');

        if ($stationCallsign !== '' && $this->stationExists($stationCallsign, $config)) {
            return null;
        }

        if ($from === '') {
            return null;
        }

        $label = $stationCallsign !== '' ? mb_substr($stationCallsign, 0, 30) : 'ADIF';

        return "{$label} @ {$from}";
    }

    private function stationExists(string $identifier, EventConfiguration $config): bool
    {
        $identifier = strtolower(mb_substr($identifier, 0, 50));

        return Station::query()
            ->where('event_configuration_id', $config->id)
            ->where(fn ($query) => $query->whereRaw('LOWER(name) = ?', [$identifier])->orWhereRaw('LOWER(hostname) = ?', [$identifier]))
            ->exists();
    }
}
