<?php

namespace App\Services;

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Events\ExternalContactDeleted;
use App\Events\ExternalContactReceived;
use App\Events\ExternalContactUpdated;
use App\Exceptions\OutOfPeriodContactException;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Section;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ExternalContactHandler
{
    public function __construct(
        private StationResolverService $stationResolver,
        private BandResolverService $bandResolver,
        private ModeResolverService $modeResolver,
        private SessionResolverService $sessionResolver,
        private DuplicateCheckService $dupeChecker,
        private UserResolverService $userResolver,
    ) {}

    public function handleContact(ExternalContactDto $dto, EventConfiguration $config): Contact
    {
        // Idempotency: if we already have this external ID, treat as replace
        if ($dto->externalId !== null) {
            $existing = Contact::where('external_id', $dto->externalId)->first();
            if ($existing !== null) {
                $this->updateContact($existing, $dto, $config);

                return $existing;
            }
        }

        $event = $config->event;
        if ($event?->start_time !== null && $event?->end_time !== null
            && ($dto->timestamp->lt($event->start_time) || $dto->timestamp->gt($event->end_time))) {
            throw new OutOfPeriodContactException(
                "QSO time {$dto->timestamp->toIso8601String()} is outside event window "
                ."{$event->start_time->toIso8601String()} – {$event->end_time->toIso8601String()}"
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

        $modeName = $dto->modeName ?? $cachedRadioInfo?->modeName;
        $modeId = $this->modeResolver->resolve($modeName);
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

        $dupeCheck = ($bandId !== null && $modeId !== null)
            ? $this->dupeChecker->check($dto->callsign, $bandId, $modeId, $config->id)
            : ['is_duplicate' => false, 'duplicate_of_contact_id' => null];

        $mode = $modeId !== null ? Mode::find($modeId) : null;
        $points = match (true) {
            $dupeCheck['is_duplicate'] => 0,
            $mode !== null => $config->pointsForContact($mode, $station),
            default => 1,
        };

        $contact = Contact::create([
            'uuid' => Str::uuid()->toString(),
            'event_configuration_id' => $config->id,
            'operating_session_id' => $session->id,
            'logger_user_id' => $operatorUserId,
            'band_id' => $bandId,
            'mode_id' => $modeId,
            'qso_time' => $dto->timestamp,
            'callsign' => $dto->callsign,
            'section_id' => $sectionId,
            'exchange_class' => $dto->exchangeClass ?? $this->extractClassFromExchange($dto->receivedExchange),
            'power_watts' => $session->power_watts,
            'points' => $points,
            'is_duplicate' => $dupeCheck['is_duplicate'],
            'duplicate_of_contact_id' => $dupeCheck['duplicate_of_contact_id'],
            'external_id' => $dto->externalId,
            'external_source' => $dto->source,
        ]);

        $session->increment('qso_count');

        ExternalContactReceived::dispatch($contact, $config->id, $dto->source, $config->event_id);

        return $contact;
    }

    public function handleReplace(ExternalContactDto $dto, EventConfiguration $config): void
    {
        if ($dto->externalId === null) {
            return;
        }

        $contact = Contact::where('external_id', $dto->externalId)->first();
        if ($contact === null) {
            return;
        }

        $event = $config->event;
        if ($event?->start_time !== null && $event?->end_time !== null
            && ($dto->timestamp->lt($event->start_time) || $dto->timestamp->gt($event->end_time))) {
            return;
        }

        $this->updateContact($contact, $dto, $config);
    }

    public function handleDelete(ExternalContactDto $dto, EventConfiguration $config): void
    {
        if ($dto->externalId === null) {
            return;
        }

        $contact = Contact::where('external_id', $dto->externalId)->first();
        if ($contact === null) {
            return;
        }

        $stationName = $contact->operatingSession?->station?->name;
        $contactId = $contact->id;
        $callsign = $contact->callsign;

        $contact->delete();

        ExternalContactDeleted::dispatch($contactId, $callsign, $config->id, $dto->source, $stationName);
    }

    /**
     * Store RadioInfo in cache for use when contacts arrive.
     *
     * RadioInfo is NOT used to create sessions or trigger notifications.
     * Sessions are only created when actual contacts are logged.
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

    private function updateContact(Contact $contact, ExternalContactDto $dto, EventConfiguration $config): void
    {
        $bandId = $dto->frequencyHz
            ? $this->bandResolver->resolveByFrequencyHz($dto->frequencyHz)
            : ($this->bandResolver->resolveByName($dto->bandName) ?? $contact->band_id);
        $modeId = $this->modeResolver->resolve($dto->modeName) ?? $contact->mode_id;
        $sectionId = $this->resolveSection($dto->sectionCode) ?? $contact->section_id;

        $contact->update([
            'callsign' => $dto->callsign,
            'qso_time' => $dto->timestamp,
            'band_id' => $bandId,
            'mode_id' => $modeId,
            'section_id' => $sectionId,
            'exchange_class' => $dto->exchangeClass ?? $this->extractClassFromExchange($dto->receivedExchange) ?? $contact->exchange_class,
        ]);

        if ($bandId !== null && $modeId !== null) {
            $dupeCheck = $this->dupeChecker->check($dto->callsign, $bandId, $modeId, $config->id);
            $contact->update([
                'is_duplicate' => $dupeCheck['is_duplicate'],
                'duplicate_of_contact_id' => $dupeCheck['duplicate_of_contact_id'],
                'points' => $dupeCheck['is_duplicate'] ? 0 : $contact->points,
            ]);
        }

        ExternalContactUpdated::dispatch($contact, $config->id, $dto->source);
    }

    private function extractClassFromExchange(?string $exchange): ?string
    {
        if ($exchange === null) {
            return null;
        }

        $tokens = preg_split('/\s+/', trim($exchange));
        foreach ($tokens as $token) {
            if (preg_match('/^\d{1,2}[A-F]$/i', $token)) {
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
