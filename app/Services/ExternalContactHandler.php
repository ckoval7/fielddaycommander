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
use App\Models\AuditLog;
use App\Models\Band;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Section;
use App\Models\Station;
use App\Support\AdvancedContactFields;
use App\Support\EventSections;
use Closure;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Carbon;
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
     * Operator callsigns linked to a user: the shape of a callsign, within
     * the width of users.call_sign.
     */
    private const OPERATOR_PATTERN = '/^[A-Z0-9\/]{3,12}$/';

    /**
     * Width of contacts.external_id. Longer ids are stored as a hash so the
     * contact is kept and later replace/delete packets still find it.
     */
    private const EXTERNAL_ID_MAX_LENGTH = 100;

    /**
     * Contact fields an external logger can change, recorded in the audit
     * log when an edit changes them. Points and dupe flags follow from these.
     *
     * @var list<string>
     */
    private const AUDITED_FIELDS = [
        'callsign', 'qso_time', 'band_id', 'mode_id', 'submode', 'section_id', 'exchange_class',
        'frequency_hz', 'power_watts', 'rst_sent', 'rst_received', 'grid_square', 'contact_name', 'notes',
    ];

    private const N1MM_SOURCE = 'n1mm';

    /**
     * How long a delete for a QSO not yet seen is remembered, in case the
     * QSO's own packet arrives after it.
     */
    private const EARLY_DELETE_MINUTES = 10;

    /**
     * Width of contacts.submode.
     */
    private const SUBMODE_MAX_LENGTH = 20;

    public function handleContact(ExternalContactDto $dto, EventConfiguration $config): Contact
    {
        return $this->retryingOnLockContention(fn () => $this->storeContact($dto, $config));
    }

    /**
     * @param  string|null  $senderIp  Address the edit came from, for the audit log
     */
    public function handleReplace(ExternalContactDto $dto, EventConfiguration $config, ?string $senderIp = null): void
    {
        $this->retryingOnLockContention(fn () => $this->replaceContact($dto, $config, $senderIp));
    }

    /**
     * @param  string|null  $senderIp  Address the delete came from, for the audit log
     */
    public function handleDelete(ExternalContactDto $dto, EventConfiguration $config, ?string $senderIp = null): void
    {
        $this->retryingOnLockContention(fn () => $this->deleteContact($dto, $config, $senderIp));
    }

    /**
     * Apply an external logger's edit to a contact it found by its own key
     * rather than by external id (N3FJP numbers records, including ones
     * logged in Field Day Commander).
     *
     * @param  string|null  $sender  The PC or station the edit came from, for the audit log
     * @param  string|null  $senderIp  Its address, for the audit log
     *
     * @throws InvalidExternalContactException
     */
    public function handleEditOf(
        Contact $contact,
        ExternalContactDto $dto,
        EventConfiguration $config,
        ?string $sender = null,
        ?string $senderIp = null,
    ): void {
        $this->assertValidCallsign($dto);

        $this->retryingOnLockContention(function () use ($contact, $dto, $config, $sender, $senderIp) {
            $this->restoreIfTrashed($contact);
            $this->updateContact($contact, $dto, $config, $senderIp, $sender);
        });
    }

    /**
     * Delete a contact an external logger found by its own key.
     *
     * @param  string|null  $sender  The PC or station the delete came from, for the audit log
     * @param  string|null  $senderIp  Its address, for the audit log
     */
    public function handleDeleteOf(
        Contact $contact,
        EventConfiguration $config,
        string $source,
        ?string $sender = null,
        ?string $senderIp = null,
    ): void {
        $this->retryingOnLockContention(fn () => $this->deleteExisting($contact, $config, $source, $sender, $senderIp));
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

        $repeat = $this->repeatOf($dto, $config);
        if ($repeat !== null) {
            return $repeat;
        }

        $event = $config->event;
        if ($event !== null && ! $event->isWithinOperatingPeriod($dto->timestamp)) {
            throw new OutOfPeriodContactException(
                "QSO time {$dto->timestamp->toIso8601String()} is outside event window "
                ."{$event->start_time->toIso8601String()} – {$event->operatingEndsAt()->toIso8601String()}"
            );
        }

        // A logger with no station name set sends a blank one.
        $stationIdentifier = trim((string) $dto->stationIdentifier) !== '' ? trim((string) $dto->stationIdentifier) : 'Unknown';
        $station = $this->stationResolver->resolve($stationIdentifier, $config->id);

        // Fallback to cached RadioInfo for operator/band/mode if not in contact
        $cachedRadioInfo = $this->getCachedRadioInfo($stationIdentifier, $dto->source);

        $operatorCallsign = $dto->operatorCallsign ?? $cachedRadioInfo?->operatorCallsign;
        $operatorUserId = $this->resolveOperator($operatorCallsign);

        $frequencyHz = $dto->frequencyHz ?? $cachedRadioInfo?->frequencyHz;
        // The frequency decides the band; the logger's band name covers a
        // contact logged without one, or with one outside every known band.
        $bandId = ($frequencyHz ? $this->bandResolver->resolveByFrequencyHz($frequencyHz) : null)
            ?? $this->bandResolver->resolveByName($dto->bandName);

        $this->assertBandAllowedAtStation($station, $bandId, $dto);

        $modeName = $dto->modeName ?? $cachedRadioInfo?->modeName;
        $modeId = $this->modeResolver->resolve($modeName, $dto->parentModeName);
        $sectionId = $this->resolveSection($dto->sectionCode, $config);

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
            'exchange_class' => $this->storableExchangeClass($dto->exchangeClass) ?? $this->extractClassFromExchange($dto->receivedExchange),
            'power_watts' => AdvancedContactFields::powerWattsFromAdif($dto->adifFields ?? []) ?? $session->power_watts,
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

    /**
     * The contact a packet repeats, when it is for a QSO already stored
     * (updated first, unless it is only a copy of the original), or null
     * for a new QSO.
     *
     * Idempotency: a known external ID is treated as a replace. A deleted
     * contact stays deleted: a second copy of a QSO logged before its delete
     * (relayed by another networked N1MM PC, or arriving late, since N1MM
     * doesn't keep its sends in order) must not bring it back.
     *
     * @throws InvalidExternalContactException When the QSO was deleted before its own packet arrived
     */
    private function repeatOf(ExternalContactDto $dto, EventConfiguration $config): ?Contact
    {
        if ($dto->externalId === null) {
            // A second copy of a QSO whose packets lost their ID to
            // truncation (relayed by another PC, or sent again).
            return $dto->source === self::N1MM_SOURCE ? $this->soleContactLoggedAt($dto, $config, withoutExternalId: true) : null;
        }

        $existing = $this->findByExternalId($dto->externalId, $config);

        if ($existing !== null) {
            if (! $existing->trashed() && ! $this->isCopyOfOriginal($dto)) {
                $this->updateContact($existing, $dto, $config);
            }

            return $existing;
        }

        // N1MM sends each packet on its own task, so a delete can overtake
        // the contactinfo it deletes; that QSO stays deleted.
        if (! $dto->isReplace && Cache::has($this->earlyDeleteKey($dto->source, $config->id, $dto->externalId))) {
            throw new InvalidExternalContactException('Deleted before it arrived', 'deleted before it arrived');
        }

        return null;
    }

    private function replaceContact(ExternalContactDto $dto, EventConfiguration $config, ?string $senderIp): void
    {
        if ($dto->externalId === null) {
            return;
        }

        $this->assertValidCallsign($dto);

        // N1MM+ sends contactdelete then contactreplace with the same ID when an
        // operator edits a QSO, so the contact may already be soft-deleted.
        $contact = $this->findByExternalId($dto->externalId, $config);

        // A replace for a QSO never seen is a whole record: N1MM's
        // "Rebroadcast contest QSOs" sends every QSO this way, and an edit
        // can overtake its contactinfo. Store it as new.
        // A QSO first stored without its ID (its contactinfo was cut short)
        // takes the ID from its first replace, found by the call and time
        // it had before the edit.
        if ($contact === null && ($contact = $this->adoptContactWithoutId($dto, $config)) !== null) {
            $contact->forceFill(['external_id' => $this->storableExternalId($dto->externalId, $config->id, $dto->source)])->saveQuietly();
        }

        if ($contact === null) {
            try {
                $this->storeContact($dto, $config);
            } catch (OutOfPeriodContactException) {
                // Like an edit outside the event window: ignored.
            }

            return;
        }

        $event = $config->event;
        if ($event !== null && ! $event->isWithinOperatingPeriod($dto->timestamp)) {
            return;
        }

        $this->restoreIfTrashed($contact);
        $this->updateContact($contact, $dto, $config, $senderIp);
    }

    private function deleteContact(ExternalContactDto $dto, EventConfiguration $config, ?string $senderIp): void
    {
        $contact = ($dto->externalId !== null ? Contact::query()
            ->where('event_configuration_id', $config->id)
            ->where('external_id', $this->storableExternalId($dto->externalId, $config->id))
            ->first() : null) ?? $this->soleContactLoggedAt($dto, $config);

        if ($contact === null) {
            if ($dto->externalId !== null) {
                Cache::put($this->earlyDeleteKey($dto->source, $config->id, $dto->externalId), true, now()->addMinutes(self::EARLY_DELETE_MINUTES));
            }

            return;
        }

        $this->deleteExisting($contact, $config, $dto->source, $dto->stationIdentifier, $senderIp);
    }

    /**
     * Delete a contact for an external logger and audit it like a delete in
     * the logbook. External loggers have no user, so the entry names the
     * logger, the station or PC that sent the delete and its address.
     */
    private function deleteExisting(
        Contact $contact,
        EventConfiguration $config,
        string $source,
        ?string $sender = null,
        ?string $senderIp = null,
    ): void {
        $stationName = $contact->operatingSession?->station?->name;
        $contactId = $contact->id;
        $callsign = $contact->callsign;

        if ($contact->trashed()) {
            return;
        }

        $this->dupes->locked($config->id, function () use ($contact) {
            $contact->delete();
            $this->dupes->reevaluate($contact);
            $contact->operatingSession?->decrement('qso_count');
        });

        AuditLog::log(
            'contact.deleted',
            auditable: $contact,
            oldValues: [
                'callsign' => $callsign,
                'exchange_class' => $contact->exchange_class,
                'session_id' => $contact->operating_session_id,
            ],
            newValues: $this->auditSender($source, $sender, $senderIp),
            ipAddress: $senderIp,
        );

        ExternalContactDeleted::dispatch($contactId, $callsign, $config->id, $source, $stationName, $config->event_id);
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
     * The one live contact from this logger with the delete's callsign and
     * QSO time, for a delete whose id matches nothing. A networked N1MM PC
     * that relays another PC's delete sends a new random id, so only the
     * callsign and time identify the QSO. Null unless exactly one matches.
     */
    private function soleContactLoggedAt(ExternalContactDto $dto, EventConfiguration $config, bool $withoutExternalId = false, ?string $callsign = null, ?Carbon $time = null): ?Contact
    {
        $matches = Contact::query()
            ->where('event_configuration_id', $config->id)
            ->where('external_source', $dto->source)
            ->where('callsign', strtoupper(trim($callsign ?? $dto->callsign)))
            ->where('qso_time', ($time ?? $dto->timestamp)->copy()->utc()->format('Y-m-d H:i:s'))
            ->when($withoutExternalId, fn ($query) => $query->whereNull('external_id'))
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * The live contact stored without an ID that a replace is an edit of,
     * found by the call and time the replace says it had before.
     */
    private function adoptContactWithoutId(ExternalContactDto $dto, EventConfiguration $config): ?Contact
    {
        if ($dto->source !== self::N1MM_SOURCE) {
            return null;
        }

        return $this->soleContactLoggedAt($dto, $config, withoutExternalId: true, callsign: $dto->oldCallsign ?: null, time: $dto->oldTimestamp)
            ?? $this->soleContactLoggedAt($dto, $config, withoutExternalId: true);
    }

    /**
     * Whether a contact packet for a QSO already stored is just another copy
     * of the original. N1MM sends edits only as contactreplace, so a second
     * contactinfo (relayed by a networked PC, or overtaken by an edit) never
     * carries anything newer and must not undo an edit.
     */
    private function isCopyOfOriginal(ExternalContactDto $dto): bool
    {
        return $dto->source === self::N1MM_SOURCE && ! $dto->isReplace;
    }

    private function earlyDeleteKey(string $source, int $eventConfigurationId, string $externalId): string
    {
        return "external-logger:{$source}:{$eventConfigurationId}:deleted:".hash('sha256', $externalId);
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

    /**
     * @param  string|null  $sender  Who sent the edit, when not the DTO's station
     */
    private function updateContact(
        Contact $contact,
        ExternalContactDto $dto,
        EventConfiguration $config,
        ?string $senderIp = null,
        ?string $sender = null,
    ): void {
        $bandId = ($dto->frequencyHz ? $this->bandResolver->resolveByFrequencyHz($dto->frequencyHz) : null)
            ?? $this->bandResolver->resolveByName($dto->bandName)
            ?? $contact->band_id;
        $station = $contact->operatingSession?->station;
        if ($station !== null) {
            $this->assertBandAllowedAtStation($station, $bandId, $dto);
        }

        $modeId = $this->modeResolver->resolve($dto->modeName, $dto->parentModeName) ?? $contact->mode_id;
        $sectionId = $this->resolveSection($dto->sectionCode, $config) ?? $contact->section_id;

        $previousKey = $this->dupes->keyFor($contact);

        $contact->fill([
            'callsign' => $dto->callsign,
            'qso_time' => $dto->timestamp,
            'band_id' => $bandId,
            'mode_id' => $modeId,
            'submode' => $dto->modeName !== null ? $this->storableSubmode($dto->modeName, $dto) : $contact->submode,
            'section_id' => $sectionId,
            'exchange_class' => $this->storableExchangeClass($dto->exchangeClass) ?? $this->extractClassFromExchange($dto->receivedExchange) ?? $contact->exchange_class,
            // A replace carries the logger's full record, so what it sends
            // wins; fields it leaves out keep their values.
            'power_watts' => AdvancedContactFields::powerWattsFromAdif($dto->adifFields ?? []) ?? $contact->power_watts,
            ...$dto->advancedFields,
            'adif_fields' => $dto->adifFields ?? $contact->adif_fields,
        ]);

        $changed = array_intersect_key($contact->getDirty(), array_flip(self::AUDITED_FIELDS));
        $before = array_map(
            fn (mixed $value) => $value instanceof \DateTimeInterface ? Carbon::instance($value)->toDateTimeString() : $value,
            array_intersect_key($contact->getOriginal(), $changed),
        );

        // Re-settles both the group the contact left and the one it joined,
        // repricing it for its (possibly new) mode.
        $this->dupes->save($contact, $previousKey);

        if ($changed !== []) {
            $after = array_map(fn (string $field) => $field === 'qso_time'
                ? $contact->qso_time?->toDateTimeString()
                : $contact->getAttribute($field), array_combine(array_keys($changed), array_keys($changed)));

            AuditLog::log(
                'contact.updated',
                auditable: $contact,
                oldValues: $before,
                newValues: [...$after, ...$this->auditSender($dto->source, $sender ?? $dto->stationIdentifier, $senderIp)],
                ipAddress: $senderIp,
            );
        }

        ExternalContactUpdated::dispatch($contact, $config->id, $dto->source, $config->event_id);
    }

    /**
     * Who made a change, for the audit log: external loggers have no user,
     * so the entry names the logger, the station or PC and its address.
     *
     * @return array<string, string>
     */
    private function auditSender(string $source, ?string $sender, ?string $senderIp): array
    {
        return array_filter([
            'changed_by' => 'external_logger',
            'source' => $source,
            'sender' => $sender !== null ? mb_substr($sender, 0, 100) : null,
            'sender_ip' => $senderIp,
        ], fn (?string $value) => $value !== null && $value !== '');
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

    /**
     * The user for the operator a logger names, or null when the name is not
     * a callsign that fits users.call_sign. An operator field is free text
     * in most loggers, and anything else would make a ghost account out of
     * it (or, on MySQL, fail the insert and lose the contact).
     */
    private function resolveOperator(?string $callsign): ?int
    {
        $callsign = strtoupper(trim((string) $callsign));

        if (preg_match(self::OPERATOR_PATTERN, $callsign) !== 1) {
            return null;
        }

        return $this->userResolver->resolveOrCreate($callsign)->id;
    }

    /**
     * A logger's class field, or the class found in it, when it holds a
     * Field Day class; otherwise null, so the class is looked for in the
     * received exchange instead of overflowing contacts.exchange_class.
     */
    private function storableExchangeClass(?string $class): ?string
    {
        $class = strtoupper(trim((string) $class));

        if (preg_match(ExchangeParserService::EXCHANGE_CLASS_PATTERN, $class) === 1) {
            return $class;
        }

        // An operator who typed the whole exchange ("2A CT") into the field.
        return $this->extractClassFromExchange($class);
    }

    /**
     * Resolve a logged section code to the id of the section it counts as for
     * the event, so YT and MX outside Winter Field Day store as TER and DX.
     */
    private function resolveSection(?string $code, EventConfiguration $config): ?int
    {
        if ($code === null) {
            return null;
        }

        $code = strtoupper($code);
        $countedCode = EventSections::countedCode($code, $config->event);

        $countedId = Section::where('code', $countedCode)->where('is_active', true)->value('id');

        if ($countedId !== null || $countedCode === $code) {
            return $countedId;
        }

        return Section::where('code', $code)->where('is_active', true)->value('id');
    }
}
