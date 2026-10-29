<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Console\Commands;

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Exceptions\InvalidExternalContactException;
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
            if (! $this->isWellFormedXml($buffer)) {
                $this->throttledWarning('malformed-xml', 'N1MM listener dropped a packet that is not well-formed XML', [
                    'from' => $from,
                    'bytes' => strlen($buffer),
                    'preview' => mb_substr(preg_replace('/[^\x20-\x7E]/', '?', $buffer) ?? '', 0, 80),
                ]);
            }

            return 0;
        }

        if ($dto instanceof ExternalContactDto) {
            $this->handleContactPacket($dto, $config, $lastLogKey);
        } elseif ($dto instanceof ExternalRadioInfoDto) {
            $this->handler->handleRadioInfo($dto, $config);
        }

        return 1;
    }

    /**
     * Packets the parser ignores on purpose (spectrum, app info, ...) are
     * well-formed; anything else it returned nothing for is garbage.
     */
    private function isWellFormedXml(string $buffer): bool
    {
        $previous = libxml_use_internal_errors(true);

        try {
            return simplexml_load_string($buffer) !== false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function handleContactPacket(ExternalContactDto $dto, EventConfiguration $config, string $lastLogKey): void
    {
        if ($dto->isDelete) {
            $this->handler->handleDelete($dto, $config);

            return;
        }

        if ($dto->isReplace) {
            try {
                $this->handler->handleReplace($dto, $config);
            } catch (InvalidExternalContactException $exception) {
                $this->writeLastLogEntry($lastLogKey, $dto, false, $exception->rejectionReason);
            }

            return;
        }

        try {
            $this->handler->handleContact($dto, $config);
            $this->writeLastLogEntry($lastLogKey, $dto, true);
        } catch (OutOfPeriodContactException) {
            $this->rejectOutOfPeriod($lastLogKey, $dto);
        } catch (InvalidExternalContactException $exception) {
            $this->writeLastLogEntry($lastLogKey, $dto, false, $exception->rejectionReason);
        }
    }
}
