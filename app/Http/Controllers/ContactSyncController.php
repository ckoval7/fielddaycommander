<?php

namespace App\Http\Controllers;

use App\Events\ContactLogged;
use App\Http\Requests\StoreContactSyncRequest;
use App\Models\Contact;
use App\Models\OperatingSession;
use App\Services\DuplicateCheckService;
use App\Support\StationMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ContactSyncController extends Controller
{
    public function store(StoreContactSyncRequest $request, DuplicateCheckService $dupeService): JsonResponse
    {
        // Idempotency: if this UUID already exists, return the existing contact
        $existing = Contact::where('uuid', $request->uuid)->first();
        if ($existing) {
            return $this->idempotentResponse($existing);
        }

        $session = OperatingSession::findOrFail($request->operating_session_id);

        if ($session->operator_user_id !== $request->user()->id) {
            abort(403, 'You are not the operator of this session.');
        }

        if (! StationMode::allowsStation($session->station_id)) {
            abort(403, 'This browser can only log for its assigned station.');
        }

        $timingError = $this->timingError($session, Carbon::parse($request->qso_time));
        if ($timingError) {
            return $timingError;
        }

        $isGotaContact = $session->station->is_gota;

        $dupeCheck = $dupeService->check(
            $request->callsign,
            $request->band_id,
            $request->mode_id,
            $session->station->event_configuration_id,
            $isGotaContact,
        );

        $mode = $session->mode;
        $contactPoints = $session->station->eventConfiguration->pointsForContact($mode, $session->station);

        try {
            $contact = Contact::create([
                'uuid' => $request->uuid,
                'event_configuration_id' => $session->station->event_configuration_id,
                'operating_session_id' => $session->id,
                'logger_user_id' => $request->user()->id,
                'band_id' => $request->band_id,
                'mode_id' => $request->mode_id,
                'submode' => $request->submode,
                'qso_time' => $request->qso_time,
                'callsign' => $request->callsign,
                'section_id' => $request->section_id,
                'exchange_class' => $request->exchange_class,
                'power_watts' => $request->power_watts,
                'points' => $dupeCheck['is_duplicate'] ? 0 : $contactPoints,
                'is_duplicate' => $dupeCheck['is_duplicate'],
                'duplicate_of_contact_id' => $dupeCheck['duplicate_of_contact_id'],
                'is_gota_contact' => $isGotaContact,
                'gota_operator_first_name' => $isGotaContact ? $request->gota_operator_first_name : null,
                'gota_operator_last_name' => $isGotaContact ? $request->gota_operator_last_name : null,
                'gota_operator_callsign' => $isGotaContact ? $request->gota_operator_callsign : null,
                'gota_operator_user_id' => $isGotaContact ? $request->gota_operator_user_id : null,
                'gota_coach_user_id' => ($isGotaContact && $session->is_supervised) ? $session->operator_user_id : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->idempotentResponse(Contact::where('uuid', $request->uuid)->firstOrFail());
        }

        $session->increment('qso_count');

        $event = $session->station->eventConfiguration->event;
        ContactLogged::dispatch($contact->load(['band', 'mode', 'section']), $event);

        return response()->json([
            'uuid' => $contact->uuid,
            'contact_id' => $contact->id,
            'points' => $contact->points,
            'is_duplicate' => $contact->is_duplicate,
        ], 201);
    }

    /**
     * Reject contacts made outside the session or event window.
     *
     * Contacts queued offline can arrive after their session was ended
     * (take-over, idle timeout, End Session), so they are judged by when
     * they were made, not when they synced.
     */
    private function timingError(OperatingSession $session, Carbon $qsoTime): ?JsonResponse
    {
        if ($session->end_time !== null && ! $qsoTime->between($session->start_time, $session->end_time)) {
            return response()->json(['message' => 'This operating session has ended.'], 422);
        }

        $event = $session->station->eventConfiguration?->event;
        if (! $event || Carbon::parse($event->end_time)->lt($qsoTime)) {
            return response()->json(['message' => 'This event has ended. Use transcription to enter contacts from paper logs.'], 422);
        }

        if (Carbon::parse($event->start_time)->gt(appNow())) {
            return response()->json(['message' => 'This event has not started yet. Contacts can only be logged once the event begins.'], 422);
        }

        return null;
    }

    private function idempotentResponse(Contact $contact): JsonResponse
    {
        return response()->json([
            'uuid' => $contact->uuid,
            'contact_id' => $contact->id,
            'points' => $contact->points,
            'is_duplicate' => $contact->is_duplicate,
        ], 200);
    }
}
