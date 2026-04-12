<?php

namespace App\Console\Commands;

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Exceptions\OutOfPeriodContactException;
use App\Models\EventConfiguration;
use App\Services\ExternalContactHandler;
use App\Services\ExternalLoggerManager;
use App\Services\N1mmPacketParser;

class N1mmListenCommand extends BaseUdpListenerCommand
{
    protected $signature = 'external-logger:n1mm {--event= : Event configuration ID (auto-detects active event if omitted)}';

    protected $description = 'Listen for N1MM Logger+ UDP broadcasts and create contacts';

    private N1mmPacketParser $parser;

    private ExternalContactHandler $handler;

    public function getType(): string
    {
        return 'n1mm';
    }

    protected function listenerLabel(): string
    {
        return 'N1MM';
    }

    public function handle(
        N1mmPacketParser $parser,
        ExternalContactHandler $handler,
        ExternalLoggerManager $manager,
    ): int {
        $this->parser = $parser;
        $this->handler = $handler;

        return $this->runListener($manager);
    }

    protected function processPacket(string $buffer, string $from, EventConfiguration $config, string $lastLogKey): int
    {
        $dto = $this->parser->parse($buffer);

        if ($dto === null) {
            return 0;
        }

        if ($dto instanceof ExternalContactDto) {
            $this->handleContactPacket($dto, $config, $lastLogKey);
        } elseif ($dto instanceof ExternalRadioInfoDto) {
            $this->handler->handleRadioInfo($dto, $config);
        }

        return 1;
    }

    private function handleContactPacket(ExternalContactDto $dto, EventConfiguration $config, string $lastLogKey): void
    {
        if ($dto->isDelete) {
            $this->handler->handleDelete($dto, $config);

            return;
        }

        if ($dto->isReplace) {
            $this->handler->handleReplace($dto, $config);

            return;
        }

        try {
            $this->handler->handleContact($dto, $config);
            $this->writeLastLogEntry($lastLogKey, $dto, true);
        } catch (OutOfPeriodContactException) {
            $this->writeLastLogEntry($lastLogKey, $dto, false, 'outside event window');
        }
    }
}
