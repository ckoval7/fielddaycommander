<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Events\ExternalContactDeleted;
use App\Events\ExternalContactReceived;
use App\Events\ExternalContactUpdated;
use App\Exceptions\InvalidExternalContactException;
use App\Exceptions\OutOfPeriodContactException;
use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Section;
use App\Models\Station;
use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ExternalContactHandler
{
    use DetectsConcurrencyErrors;

    public function __construct(
        private StationResolverService $stationResolver,
        private BandResolverService $bandResolver,
        private ModeResolverService $modeResolver,
        private SessionResolverService $sessionResolver,
        private DuplicateCheckService $dupes,
        private UserResolverService $userResolver,
    ) {}

    /**
     * Callsigns accepted from external loggers; the same shape the web sync
     * endpoint (StoreContactSyncRequest) accepts.
     */
    private const CALLSIGN_PATTERN = '/^[A-Z0-9\/]+$/';

    /**
     * Width of contacts.external_id. Longer ids are stored as a hash so the
     * contact is kept and later replace/delete packets still find it.
     */
    private const EXTERNAL_ID_MAX_LENGTH = 100;

    /**
     * Width of contacts.submode.
     */
    private const SUBMODE_MAX_LENGTH = 20;

    public function handleContact(ExternalContactDto $dto, EventConfiguration $config): Contact
    {
        return $this->retryingOnLockContention(fn () => $this->storeContact($dto, $config));
    }

    public function handleReplace(ExternalContactDto $dto, EventConfiguration $config): void
    {
        $this->retryingOnLockContention(fn () => $this->replaceContact($dto, $config));
    }

    public function handleDelete(ExternalContactDto $dto, EventConfiguration $config): void
    {
        $this->retryingOnLockContention(fn () => $this->deleteContact($dto, $config));
    }

    /**
     * Run $operation, retrying it once from the start when it lost a lock
     * wait or a deadlock (e.g. while an ADIF import batch held the event
     * lock). UDP has no resend, so giving up would drop the contact.
     *
     * Each write step runs in its own locked transaction that rolls back on
     * failure, and every step is safe to repeat, so the retry cannot store a
     * contact twice.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $operation
     * @return TReturn
     */
    private function retryingOnLockContention(Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            if (! $this->causedByConcurrencyError($exception)) {
                throw $exception;
            }

            Log::info('Retrying external contact after lock contention', ['error' => $exception->getMessage()]);

            return $operation();
        }
    }

    private function storeContact(ExternalContactDto $dto, EventConfiguration $config): Contact
    {
        $this->assertValidCallsign($dto);

        // Idempotency: if we already have this external ID, treat as replace
        if ($dto->externalId !== null) {
            $existing = $this->findByExternalId($dto->externalId, $config);
            if ($existing !== null) {
                $this->restoreIfTrashed($existing);
                $this->updateContact($existing, $dto, $config);

                return $existing;
            }
        }

        $event = $config->event;
        if ($event !== null && ! $event->isWithinOperatingPeriod($dto->timestamp)) {
            throw new OutOfPeriodContactException(
                "QSO time {$dto->timestamp->toIso8601String()} is outside event window "
                ."{$event->start_time->toIso8601String()} – {$event->operatingEndsAt()->toIso8601String()}"
            );
        }

        $stationIdentifier = $dto->stationIdentifier ?? 'Unknown';
        $station = $this->stationResolver->resolve($stationIdentifier, $config->id);

        // Fallback to cached RadioInfo for operator/band/mode if not in contact
        $cachedRadioInfo = $this->getCachedRadioInfo($stationIdentifier, $dto->source);

        $operatorCallsign = $dto->operatorCallsign ?? $cachedRadioInfo?->operatorCallsign;
        $operatorUserId = $this->resolveOperator($operatorCallsign);

        $frequencyHz = $dto->frequencyHz ?? $cachedRadioInfo?->frequencyHz;
        $bandId = $frequencyHz
            ? $this->bandResolver->resolveByFrequencyHz($frequencyHz)
            : $this->bandResolver->resolveByName($dto->bandName);

        $this->assertBandAllowedAtStation($station, $bandId, $dto);

        $modeName = $dto->modeName ?? $cachedRadioInfo?->modeName;
        $modeId = $this->modeResolver->resolve($modeName, $dto->parentModeName);
        $sectionId = $this->resolveSection($dto->sectionCode);

        $session = $this->sessionResolver->resolve(
            stationId: $station->id,
            operatorUserId: $operatorUserId,
            bandId: $bandId,
            modeId: $modeId,
            startTime: $dto->timestamp,
            externalSource: $dto->source,
        );

        $this->sessionResolver->touchActivity($session);

        $contact = new Contact([
            'uuid' => Str::uuid()->toString(),
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $operatorUserId,
            'band_id' => $bandId,
            'mode_id' => $modeId,
            'submode' => $this->storableSubmode($modeName, $dto),
            'qso_time' => $dto->timestamp,
            'callsign' => $dto->callsign,
            'section_id' => $sectionId,
            'exchange_class' => $dto->exchangeClass ?? $this->extractClassFromExchange($dto->receivedExchange),
            'power_watts' => $session->power_watts,
            ...$dto->advancedFields,
            'adif_fields' => $dto->adifFields,
            'is_gota_contact' => (bool) $station->is_gota,
            'external_id' => $dto->externalId !== null ? $this->storableExternalId($dto->externalId, $config->id, $dto->source) : null,
            'external_source' => $dto->source,
        ]);

        // Without a band and mode the contact can be neither dupe-checked nor
        // priced, so it is stored at 0 points until a replace completes it.
        $this->dupes->locked($config->id, function () use ($contact, $session) {
            $this->dupes->save($contact);
            $session->increment('qso_count');
        });

        ExternalContactReceived::dispatch($contact, $config->id, $dto->source, $config->event_id);

        return $contact;
    }

    private function replaceContact(ExternalContactDto $dto, EventConfiguration $config): void
    {
        if ($dto->externalId === null) {
            return;
        }

        $this->assertValidCallsign($dto);

        // N1MM+ sends contactdelete then contactreplace with the same ID when an
        // operator edits a QSO, so the contact may already be soft-deleted.
        $contact = $this->findByExternalId($dto->externalId, $config);
        if ($contact === null) {
            return;
        }

        $event = $config->event;
        if ($event !== null && ! $event->isWithinOperatingPeriod($dto->timestamp)) {
            return;
        }

        $this->restoreIfTrashed($contact);
        $this->updateContact($contact, $dto, $config);
    }

    private function deleteContact(ExternalContactDto $dto, EventConfiguration $config): void
    {
        if ($dto->externalId === null) {
            return;
        }

        $contact = Contact::query()
            ->where('event_configuration_id', $config->id)
            ->where('external_id', $this->storableExternalId($dto->externalId, $config->id))
            ->first();
        if ($contact === null) {
            return;
        }

        $stationName = $contact->operatingSession?->station?->name;
        $contactId = $contact->id;
        $callsign = $contact->callsign;

        $this->dupes->locked($config->id, function () use ($contact) {
            $contact->delete();
            $this->dupes->reevaluate($contact);
            $contact->operatingSession?->decrement('qso_count');
        });

        ExternalContactDeleted::dispatch($contactId, $callsign, $config->id, $dto->source, $stationName, $config->event_id);
    }

    /**
     * Store RadioInfo in cache for use when contacts arrive.
     *
     * RadioInfo is NOT used to create sessions or trigger notifications.
     * Sessions are only created when actual contacts are logged.
     *
     * $config is unused here but kept so every handleX() method shares the
     * same (dto, config) signature that N1mmListenCommand dispatches against.
     */
    public function handleRadioInfo(ExternalRadioInfoDto $dto, EventConfiguration $config): void
    {
        $this->cacheRadioInfo($dto);
    }

    private function cacheRadioInfo(ExternalRadioInfoDto $dto): void
    {
        $key = $this->radioInfoCacheKey($dto->stationIdentifier, $dto->source);
        Cache::put($key, $dto, now()->addMinutes(5));
    }

    private function getCachedRadioInfo(string $stationIdentifier, string $source): ?ExternalRadioInfoDto
    {
        $key = $this->radioInfoCacheKey($stationIdentifier, $source);

        return Cache::get($key);
    }

    private function radioInfoCacheKey(string $stationIdentifier, string $source): string
    {
        return "external_radio_info:{$source}:{$stationIdentifier}";
    }

    private function restoreIfTrashed(Contact $contact): void
    {
        if (! $contact->trashed()) {
            return;
        }

        $this->dupes->locked($contact->event_configuration_id, function () use ($contact) {
            $contact->restore();
            $this->dupes->reevaluate($contact);
            $contact->operatingSession?->increment('qso_count');
        });
    }

    /**
     * The contact this event configuration already holds for an external
     * logger's QSO id, including soft-deleted ones. Scoped to the event so
     * one event's packets can never touch another event's contacts.
     */
    private function findByExternalId(string $externalId, EventConfiguration $config): ?Contact
    {
        return Contact::withTrashed()
            ->where('event_configuration_id', $config->id)
            ->where('external_id', $this->storableExternalId($externalId, $config->id))
            ->first();
    }

    /**
     * Drop packets whose callsign is not a plausible callsign. UDP input is
     * unauthenticated, and callsigns end up in the logbook and the UI.
     *
     * @throws InvalidExternalContactException
     */
    private function assertValidCallsign(ExternalContactDto $dto): void
    {
        if (preg_match(self::CALLSIGN_PATTERN, strtoupper(trim($dto->callsign))) === 1 && strlen($dto->callsign) <= 20) {
            return;
        }

        Log::warning('Dropped external contact with an invalid callsign', [
            'source' => $dto->source,
            'external_id' => $dto->externalId,
            'callsign' => mb_substr($dto->callsign, 0, 40),
        ]);

        throw new InvalidExternalContactException('Invalid callsign');
    }

    /**
     * Drop contacts the free VHF station logs below 50 MHz: it operates
     * exclusively on 6m and up, so an HF contact there would be another
     * transmitter the entry class doesn't count.
     *
     * @throws InvalidExternalContactException
     */
    private function assertBandAllowedAtStation(Station $station, ?int $bandId, ExternalContactDto $dto): void
    {
        $band = $bandId !== null ? Band::find($bandId) : null;

        if ($band === null || $band->isAllowedAtStation($station)) {
            return;
        }

        Log::warning('Dropped external contact below 50 MHz at the free VHF station', [
            'source' => $dto->source,
            'external_id' => $dto->externalId,
            'callsign' => mb_substr($dto->callsign, 0, 40),
            'station' => $station->name,
            'band' => $band->name,
        ]);

        throw new InvalidExternalContactException('Band not allowed at the free VHF station', 'below 50 MHz at VHF station');
    }

    private function updateContact(Contact $contact, ExternalContactDto $dto, EventConfiguration $config): void
    {
        $bandId = $dto->frequencyHz
            ? $this->bandResolver->resolveByFrequencyHz($dto->frequencyHz)
            : ($this->bandResolver->resolveByName($dto->bandName) ?? $contact->band_id);
        $station = $contact->operatingSession?->station;
        if ($station !== null) {
            $this->assertBandAllowedAtStation($station, $bandId, $dto);
        }

        $modeId = $this->modeResolver->resolve($dto->modeName, $dto->parentModeName) ?? $contact->mode_id;
        $sectionId = $this->resolveSection($dto->sectionCode) ?? $contact->section_id;

        $previousKey = $this->dupes->keyFor($contact);

        $contact->fill([
            'callsign' => $dto->callsign,
            'qso_time' => $dto->timestamp,
            'band_id' => $bandId,
            'mode_id' => $modeId,
            'submode' => $dto->modeName !== null ? $this->storableSubmode($dto->modeName, $dto) : $contact->submode,
            'section_id' => $sectionId,
            'exchange_class' => $dto->exchangeClass ?? $this->extractClassFromExchange($dto->receivedExchange) ?? $contact->exchange_class,
            // A replace carries the logger's full record, so what it sends
            // wins; fields it leaves out keep their values.
            ...$dto->advancedFields,
            'adif_fields' => $dto->adifFields ?? $contact->adif_fields,
        ]);

        // Re-settles both the group the contact left and the one it joined,
        // repricing it for its (possibly new) mode.
        $this->dupes->save($contact, $previousKey);

        ExternalContactUpdated::dispatch($contact, $config->id, $dto->source, $config->event_id);
    }

    /**
     * The external id as stored: ids longer than the column are replaced by
     * a stable hash (and logged, at most once a minute per source) instead of
     * failing the insert and dropping the contact.
     */
    private function storableExternalId(string $externalId, int $eventConfigurationId, ?string $source = null): string
    {
        if (strlen($externalId) <= self::EXTERNAL_ID_MAX_LENGTH) {
            return $externalId;
        }

        if ($source !== null && Cache::add("external-logger:{$source}:long-external-id-warned", true, 60)) {
            Log::warning('External logger sent an over-long QSO id; storing a hash of it', [
                'source' => $source,
                'event_configuration_id' => $eventConfigurationId,
                'length' => strlen($externalId),
                'external_id' => mb_substr($externalId, 0, 120),
            ]);
        }

        return 'sha256:'.hash('sha256', $externalId);
    }

    /**
     * The mode name as stored in contacts.submode, cut to the column width
     * (logged, at most once a minute per source) rather than truncated
     * silently by the database.
     */
    private function storableSubmode(?string $modeName, ExternalContactDto $dto): ?string
    {
        if ($modeName === null || mb_strlen($modeName) <= self::SUBMODE_MAX_LENGTH) {
            return $modeName;
        }

        if (Cache::add("external-logger:{$dto->source}:long-mode-warned", true, 60)) {
            Log::warning('External logger sent an over-long mode name; storing it truncated', [
                'source' => $dto->source,
                'callsign' => mb_substr($dto->callsign, 0, 40),
                'mode' => mb_substr($modeName, 0, 120),
            ]);
        }

        return mb_substr($modeName, 0, self::SUBMODE_MAX_LENGTH);
    }

    private function extractClassFromExchange(?string $exchange): ?string
    {
        if ($exchange === null) {
            return null;
        }

        $tokens = preg_split('/\s+/', trim($exchange));
        foreach ($tokens as $token) {
            if (preg_match(ExchangeParserService::EXCHANGE_CLASS_PATTERN, $token)) {
                return strtoupper($token);
            }
        }

        return null;
    }

    private function resolveOperator(?string $callsign): ?int
    {
        if ($callsign === null) {
            return null;
        }

        return $this->userResolver->resolveOrCreate($callsign)->id;
    }

    private function resolveSection(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        return Section::where('code', strtoupper($code))->where('is_active', true)->value('id');
    }
}
