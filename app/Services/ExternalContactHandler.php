<?php

namespace App\Services;

use App\DTOs\ExternalContactDto;
use App\DTOs\ExternalRadioInfoDto;
use App\Events\ExternalContactDeleted;
use App\Events\ExternalContactReceived;
use App\Events\ExternalContactUpdated;
use App\Events\ExternalStationStatusChanged;
use App\Models\Contact;
use App\Models\EventConfiguration;
use App\Models\Mode;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Str;

class ExternalContactHandler
{
    public function __construct(
        private StationResolverService $stationResolver,
        private BandResolverService $bandResolver,
        private ModeResolverService $modeResolver,
        private SessionResolverService $sessionResolver,
        private DuplicateCheckService $dupeChecker,
    ) {}

    public function handleContact(ExternalContactDto $dto, EventConfiguration $config): Contact
    {
        // Idempotency: if we already have this external ID, treat as replace
        if ($dto->externalId !== null) {
            $existing = Contact::where('n1mm_id', $dto->externalId)->first();
            if ($existing !== null) {
                $this->updateContact($existing, $dto, $config);

                return $existing;
            }
        }

        $station = $this->stationResolver->resolve(
            $dto->stationIdentifier ?? 'Unknown',
            $config->id,
        );

        $operatorUserId = $this->resolveOperator($dto->operatorCallsign);
        $bandId = $dto->frequencyHz
            ? $this->bandResolver->resolveByFrequencyHz($dto->frequencyHz)
            : $this->bandResolver->resolveByName($dto->bandName);
        $modeId = $this->modeResolver->resolve($dto->modeName);
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
        $points = $dupeCheck['is_duplicate'] ? 0 : ($mode?->points_fd ?? 1);

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
            'received_exchange' => $dto->receivedExchange,
            'power_watts' => $session->power_watts,
            'points' => $points,
            'is_duplicate' => $dupeCheck['is_duplicate'],
            'duplicate_of_contact_id' => $dupeCheck['duplicate_of_contact_id'],
            'n1mm_id' => $dto->externalId,
            'external_source' => $dto->source,
        ]);

        $session->increment('qso_count');

        ExternalContactReceived::dispatch($contact, $config->id, $dto->source);

        return $contact;
    }

    public function handleReplace(ExternalContactDto $dto, EventConfiguration $config): void
    {
        if ($dto->externalId === null) {
            return;
        }

        $contact = Contact::where('n1mm_id', $dto->externalId)->first();
        if ($contact === null) {
            return;
        }

        $this->updateContact($contact, $dto, $config);
    }

    public function handleDelete(ExternalContactDto $dto, EventConfiguration $config): void
    {
        if ($dto->externalId === null) {
            return;
        }

        $contact = Contact::where('n1mm_id', $dto->externalId)->first();
        if ($contact === null) {
            return;
        }

        $stationName = $contact->operatingSession?->station?->name;
        $contactId = $contact->id;
        $callsign = $contact->callsign;

        $contact->delete();

        ExternalContactDeleted::dispatch($contactId, $callsign, $config->id, $dto->source, $stationName);
    }

    public function handleRadioInfo(ExternalRadioInfoDto $dto, EventConfiguration $config): void
    {
        $station = $this->stationResolver->resolve(
            $dto->stationIdentifier,
            $config->id,
        );

        $operatorUserId = $this->resolveOperator($dto->operatorCallsign);
        $bandId = $this->bandResolver->resolveByFrequencyHz($dto->frequencyHz);
        $modeId = $this->modeResolver->resolve($dto->modeName);

        // Close any active external sessions on this station with different operator/band/mode
        $activeSessions = $station->operatingSessions()
            ->whereNull('end_time')
            ->whereNotNull('external_source')
            ->get();

        foreach ($activeSessions as $activeSession) {
            $changed = $activeSession->operator_user_id !== $operatorUserId
                || $activeSession->band_id !== $bandId
                || $activeSession->mode_id !== $modeId;

            if ($changed) {
                $this->sessionResolver->closeSession($activeSession);
            }
        }

        $session = $this->sessionResolver->resolve(
            stationId: $station->id,
            operatorUserId: $operatorUserId,
            bandId: $bandId,
            modeId: $modeId,
            startTime: now(),
            externalSource: $dto->source,
        );

        ExternalStationStatusChanged::dispatch($station, $session, $config->id, $dto->source);
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
            'received_exchange' => $dto->receivedExchange ?? $contact->received_exchange,
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

    private function resolveOperator(?string $callsign): ?int
    {
        if ($callsign === null) {
            return null;
        }

        return User::where('call_sign', strtoupper($callsign))->value('id');
    }

    private function resolveSection(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        return Section::where('code', strtoupper($code))->where('is_active', true)->value('id');
    }
}
