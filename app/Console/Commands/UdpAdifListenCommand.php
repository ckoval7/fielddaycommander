<?php

namespace App\Console\Commands;

use App\Exceptions\OutOfPeriodContactException;
use App\Models\EventConfiguration;
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
            $dto = $this->mapper->map($tags, 'udp-adif');

            if ($dto === null) {
                continue;
            }

            try {
                $this->handler->handleContact($dto, $config);
                $this->writeLastLogEntry($lastLogKey, $dto, true);
            } catch (OutOfPeriodContactException) {
                $this->writeLastLogEntry($lastLogKey, $dto, false, 'outside event window');
            }
            $count++;
        }

        return $count;
    }
}
