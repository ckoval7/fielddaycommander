<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\DTOs\ExternalContactDto;
use App\Exceptions\InvalidExternalContactException;
use App\Exceptions\OutOfPeriodContactException;
use App\Exceptions\UnknownStationException;
use App\Models\Relay;
use App\Support\AdvancedContactFields;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Stores batches of contacts sent by a relay, through the same handler as
 * the local logger listeners. Each contact succeeds or fails on its own.
 */
class RelayContactIngestService
{
    public const SOURCE = 'relay';

    public const MAX_BATCH_SIZE = 100;

    public const STATUS_ACCEPTED = 'accepted';

    /** Will never be stored, so the relay shouldn't send it again unchanged. */
    public const STATUS_REJECTED = 'rejected';

    /** Not stored this time. The relay should send it again later. */
    public const STATUS_RETRY = 'retry';

    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(private ExternalContactHandler $handler) {}

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     * @return list<array{index: int, status: string, contact_uuid?: string, code?: string, message?: string}>
     */
    public function ingest(Relay $relay, array $contacts): array
    {
        $results = [];

        foreach (array_values($contacts) as $index => $contact) {
            $results[] = ['index' => $index, ...$this->ingestOne($relay, $contact)];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array{status: string, contact_uuid?: string, code?: string, message?: string}
     */
    private function ingestOne(Relay $relay, array $contact): array
    {
        $validator = Validator::make($contact, $this->rules(), AdvancedContactFields::messages());

        if ($validator->fails()) {
            return $this->rejected('invalid', $validator->errors()->first());
        }

        $dto = $this->toDto($validator->validated(), $relay);
        $config = $relay->eventConfiguration;

        try {
            // A retry can arrive while its timed-out original is still being
            // stored, and the handler's repeat check doesn't hold a lock.
            $stored = Cache::lock("relay-contact:{$config->id}:{$dto->externalId}", self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->handler->handleContact($dto, $config));
        } catch (Throwable $exception) {
            return $this->failureResult($exception);
        }

        return ['status' => self::STATUS_ACCEPTED, 'contact_uuid' => $stored->uuid];
    }

    /**
     * @return array{status: string, code: string, message?: string}
     */
    private function failureResult(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof OutOfPeriodContactException => $this->rejected('out_of_period', 'The contact is outside the event\'s operating period.'),
            $exception instanceof InvalidExternalContactException => $this->rejected('invalid_contact', $exception->rejectionReason),
            // Kept in the relay's queue: it's stored once someone creates the station.
            $exception instanceof UnknownStationException => ['status' => self::STATUS_RETRY, 'code' => 'unknown_station', 'message' => 'Ask someone with manage-stations permission to create a station with the name or hostname sent for this contact. The contact stays in the relay\'s queue until then.'],
            $exception instanceof LockTimeoutException => ['status' => self::STATUS_RETRY, 'code' => 'busy'],
            default => $this->serverError($exception),
        };
    }

    /**
     * @return array{status: string, code: string}
     */
    private function serverError(Throwable $exception): array
    {
        report($exception);

        return ['status' => self::STATUS_RETRY, 'code' => 'server_error'];
    }

    /**
     * @return array{status: string, code: string, message: string}
     */
    private function rejected(string $code, string $message): array
    {
        return ['status' => self::STATUS_REJECTED, 'code' => $code, 'message' => $message];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/'],
            'callsign' => ['required', 'string', 'max:20'],
            'qso_time' => ['required', 'string', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
            'band' => ['nullable', 'string', 'max:10'],
            'mode' => ['nullable', 'string', 'max:20'],
            'operator' => ['nullable', 'string', 'max:20'],
            'station' => ['nullable', 'string', 'max:50'],
            'exchange_class' => ['nullable', 'string', 'max:10'],
            'section' => ['nullable', 'string', 'max:10'],
            'power_watts' => ['nullable', 'integer', 'min:1', 'max:'.AdvancedContactFields::MAX_POWER_WATTS],
            ...AdvancedContactFields::rules(),
        ];
    }

    /**
     * @param  array<string, mixed>  $contact  Validated contact
     */
    private function toDto(array $contact, Relay $relay): ExternalContactDto
    {
        $advancedFields = array_filter(
            array_intersect_key($contact, array_flip(AdvancedContactFields::FIELDS)),
            fn (mixed $value): bool => $value !== null,
        );
        $upper = fn (string $field): ?string => isset($contact[$field]) ? strtoupper($contact[$field]) : null;

        return new ExternalContactDto(
            callsign: strtoupper($contact['callsign']),
            timestamp: Carbon::parse($contact['qso_time'])->utc(),
            source: self::SOURCE,
            bandName: $contact['band'] ?? null,
            modeName: $contact['mode'] ?? null,
            operatorCallsign: $upper('operator'),
            stationIdentifier: $contact['station'] ?? $relay->name,
            frequencyHz: $contact['frequency_hz'] ?? null,
            exchangeClass: $upper('exchange_class'),
            sectionCode: $upper('section'),
            externalId: self::SOURCE.':'.$contact['idempotency_key'],
            advancedFields: $advancedFields,
            adifFields: isset($contact['power_watts']) ? ['TX_PWR' => (string) $contact['power_watts']] : null,
            mayCreateStation: $relay->canCreateStations(),
        );
    }
}
