<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services\N3fjp;

use App\DTOs\ExternalContactDto;
use App\Exceptions\InvalidExternalContactException;
use App\Exceptions\OutOfPeriodContactException;
use App\Models\EventConfiguration;
use App\Services\ExternalContactHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The N3FJP network server's protocol, without the sockets.
 *
 * N3FJP's own server relays every message to every client, the sender
 * included, and saves contacts because it is also a client of itself. This
 * server saves each contact a client sends into the event's log, then relays
 * the saved record, so all clients see what Field Day Commander stored. A
 * client only lists its own contact once that relay arrives.
 *
 * Messages to send collect in an outbox; the listener command drains it.
 * Clock sync requests are dropped: relaying one would set the Windows clock
 * of every connected PC on the say of any one of them.
 */
class N3fjpServer
{
    private N3fjpBandStatus $bandStatus;

    /** @var array<int, string> IP address of each connected client */
    private array $clientAddresses = [];

    /** @var list<array{?int, string}> Messages to send: [client id or null for everyone, message] */
    private array $outbox = [];

    public function __construct(
        private EventConfiguration $config,
        private N3fjpLogSync $sync,
        private N3fjpContactMapper $mapper,
        private ExternalContactHandler $handler,
    ) {
        $this->bandStatus = new N3fjpBandStatus($config, $mapper);
    }

    public function boot(): void
    {
        $this->sync->boot();
    }

    /**
     * A client connected from $peer ("address:port").
     */
    public function connect(int $clientId, string $peer): void
    {
        $this->clientAddresses[$clientId] = trim((string) preg_replace('/:\d+$/', '', $peer), '[]');
    }

    public function disconnect(int $clientId): void
    {
        unset($this->clientAddresses[$clientId]);
    }

    /**
     * Handle one message from a client.
     */
    public function receive(int $clientId, string $raw): void
    {
        $message = N3fjpMessage::parse($raw);

        match ($message->kind) {
            N3fjpMessageKind::Open => $this->sendFullLog($clientId),
            N3fjpMessageKind::Check => $this->sendCheck($clientId),
            N3fjpMessageKind::Transaction => $this->handleTransaction($clientId, $message),
            N3fjpMessageKind::BandAndMode => $this->relayBandAndMode($message),
            N3fjpMessageKind::Who => $this->queueAll($clientId, $this->bandStatus->all()),
            N3fjpMessageKind::Chat, N3fjpMessageKind::DxSpot => $this->broadcast($raw),
            N3fjpMessageKind::ClockSync, N3fjpMessageKind::Unknown => null,
        };
    }

    /**
     * Send clients the changes made in Field Day Commander since the last tick.
     */
    public function tick(): void
    {
        foreach ($this->bandStatus->fdcStationsDue() as $message) {
            $this->broadcast($message);
        }

        $changes = $this->sync->poll();

        if ($changes->isEmpty()) {
            return;
        }

        foreach ($changes->deletes as $key) {
            $this->broadcast(N3fjpMessage::transaction('DELETE', (string) $key));
        }

        foreach ($changes->adds as $record) {
            $this->broadcast(N3fjpMessage::transaction('ADD', $record));
        }

        if ($changes->reloadAll) {
            $this->sendFullLog(null);

            return;
        }

        foreach ($changes->edits as $record) {
            $this->broadcast(N3fjpMessage::transaction('EDIT', $record));
        }

        if ($changes->checkAfter) {
            $this->broadcastCheck();
        }
    }

    /**
     * Take the messages waiting to be sent.
     *
     * @return list<array{?int, string}> [client id or null for everyone, message]
     */
    public function drainOutbox(): array
    {
        $outbox = $this->outbox;
        $this->outbox = [];

        return $outbox;
    }

    private function handleTransaction(int $clientId, N3fjpMessage $message): void
    {
        $from = N3fjpMessage::clean($message->field('FROM'));
        $data = $message->field('XMLDATA');

        match (strtoupper(trim($message->field('TRANSACTION')))) {
            'ADD' => $this->addContact($clientId, $from, $data),
            'EDIT' => $this->editContact($clientId, $from, $data),
            'DELETE' => $this->deleteContact($clientId, $from, (int) trim($data)),
            default => null,
        };
    }

    private function addContact(int $clientId, string $from, string $data): void
    {
        $dto = $this->mapper->toDto($this->mapper->parseRecord($data), $from, 'n3fjp:'.Str::uuid()->toString());

        try {
            $contact = $this->handler->handleContact($dto, $this->config);
        } catch (OutOfPeriodContactException) {
            $this->recordLastContact($dto, 'outside event window');
            $this->reject($clientId, $from, "{$dto->callsign} was not saved: its date and time are outside the event. Check this PC's clock.");

            return;
        } catch (InvalidExternalContactException $exception) {
            $this->recordLastContact($dto, $exception->rejectionReason);
            $this->reject($clientId, $from, "{$dto->callsign} was not saved: {$exception->rejectionReason}.");

            return;
        }

        $this->recordLastContact($dto);
        $this->broadcast(N3fjpMessage::transaction('ADD', $this->sync->accept($contact)));
    }

    private function editContact(int $clientId, string $from, string $data): void
    {
        $fields = $this->mapper->parseRecord($data);
        $contact = $this->sync->contactForKey((int) ($fields['PRIMARYKEY'] ?? 0));

        if ($contact === null) {
            $this->sendCheck($clientId);

            return;
        }

        try {
            $this->handler->handleEditOf(
                $contact,
                $this->mapper->toDto($fields, $from),
                $this->config,
                $from !== '' ? $from : null,
                $this->clientAddresses[$clientId] ?? null,
            );
        } catch (InvalidExternalContactException $exception) {
            $this->reject($clientId, $from, "Your edit to {$contact->callsign} was not saved: {$exception->rejectionReason}.");
        }

        // Sent either way: on a rejected edit it puts the sender's copy back.
        $this->broadcast(N3fjpMessage::transaction('EDIT', $this->sync->recordFor($contact->id)));
    }

    private function deleteContact(int $clientId, string $from, int $key): void
    {
        $contact = $key > 0 ? $this->sync->contactForKey($key) : null;

        if ($contact === null) {
            $this->sendCheck($clientId);

            return;
        }

        $this->handler->handleDeleteOf(
            $contact,
            $this->config,
            N3fjpContactMapper::SOURCE,
            $from !== '' ? $from : null,
            $this->clientAddresses[$clientId] ?? null,
        );
        $wasLast = $this->sync->release($key);

        $this->broadcast(N3fjpMessage::transaction('DELETE', (string) $key));

        if ($wasLast) {
            $this->broadcastCheck();
        }
    }

    private function relayBandAndMode(N3fjpMessage $message): void
    {
        $relay = $this->bandStatus->record($message);

        if ($relay !== null) {
            $this->broadcast($relay);
        }
    }

    /**
     * Send one client, or every client when $clientId is null, the whole log.
     */
    private function sendFullLog(?int $clientId): void
    {
        $this->queue($clientId, N3fjpMessage::clear());

        foreach ($this->sync->fullLog() as $record) {
            $this->queue($clientId, N3fjpMessage::transaction('GET', $record));
        }

        $this->queue($clientId, N3fjpMessage::listComplete());
    }

    private function sendCheck(int $clientId): void
    {
        $this->queue($clientId, N3fjpMessage::check(...$this->sync->countAndLastKey()));
    }

    private function broadcastCheck(): void
    {
        $this->broadcast(N3fjpMessage::check(...$this->sync->countAndLastKey()));
    }

    /**
     * Tell the sender why its contact wasn't saved. Without an ADD back, the
     * N3FJP client also puts the contact back in its entry boxes.
     */
    private function reject(int $clientId, string $from, string $reason): void
    {
        Log::info('N3FJP contact rejected', ['station' => $from, 'reason' => $reason]);

        $this->queue($clientId, N3fjpMessage::chat($from, 'Field Day Commander: '.$reason));
    }

    /**
     * Show the contact on the External Loggers page as the last one received.
     */
    private function recordLastContact(ExternalContactDto $dto, ?string $rejectionReason = null): void
    {
        Cache::put("external-logger:n3fjp:{$this->config->id}:last-log", [
            'callsign' => $dto->callsign,
            'band' => $dto->bandName,
            'mode' => $dto->modeName,
            'qso_time' => $dto->timestamp->toIso8601String(),
            'section' => $dto->sectionCode,
            'source' => N3fjpContactMapper::SOURCE,
            'received_at' => now()->toIso8601String(),
            'accepted' => $rejectionReason === null,
            'rejection_reason' => $rejectionReason,
        ], now()->addDay());
    }

    private function broadcast(string $message): void
    {
        $this->queue(null, $message);
    }

    /**
     * @param  list<string>  $messages
     */
    private function queueAll(?int $clientId, array $messages): void
    {
        foreach ($messages as $message) {
            $this->queue($clientId, $message);
        }
    }

    private function queue(?int $clientId, string $message): void
    {
        $this->outbox[] = [$clientId, $message];
    }
}
