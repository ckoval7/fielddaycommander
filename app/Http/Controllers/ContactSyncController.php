<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers;

use App\Events\ContactLogged;
use App\Http\Requests\StoreContactSyncRequest;
use App\Models\Contact;
use App\Models\OperatingSession;
use App\Services\DuplicateCheckService;
use App\Support\AdvancedContactFields;
use App\Support\EventSections;
use App\Support\StationMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ContactSyncController extends Controller
{
    /**
     * How far ahead of the server clock a QSO time may be, to allow for a
     * logging device whose clock runs slightly fast.
     */
    private const FUTURE_SKEW_MINUTES = 5;

    /**
     * Response header carrying the server's application clock (appNow(), so
     * Developer Tools' fake clock included) in epoch milliseconds. The
     * contact queue measures its offset from it and stamps qso_time in
     * server time, so a device with a wrong clock still logs correct times.
     */
    public const SERVER_TIME_HEADER = 'X-Server-Time';

    public function store(StoreContactSyncRequest $request, DuplicateCheckService $dupeService): JsonResponse
    {
        // Idempotency: if this UUID already exists, return the existing contact
        $existing = Contact::where('uuid', $request->uuid)->first();

        $response = $existing
            ? $this->idempotentResponse($existing)
            : $this->storeContact($request, $dupeService);

        return $response->header(self::SERVER_TIME_HEADER, (string) appNow()->getTimestampMs());
    }

    private function storeContact(StoreContactSyncRequest $request, DuplicateCheckService $dupeService): JsonResponse
    {
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
        $eventConfigurationId = $session->station->event_configuration_id;

        $contact = new Contact([
            'uuid' => $request->uuid,
            'event_configuration_id' => $eventConfigurationId,
            'operating_session_id' => $session->id,
            'logger_user_id' => $request->user()->id,
            'band_id' => $request->band_id,
            'mode_id' => $request->mode_id,
            'submode' => $request->submode,
            'qso_time' => $request->qso_time,
            'callsign' => $request->callsign,
            'section_id' => EventSections::countedSectionId((int) $request->section_id, $session->station->eventConfiguration?->event),
            'exchange_class' => $request->exchange_class,
            'power_watts' => $request->power_watts,
            ...$request->safe()->only(AdvancedContactFields::FIELDS),
            'is_gota_contact' => $isGotaContact,
            'gota_operator_first_name' => $isGotaContact ? $request->gota_operator_first_name : null,
            'gota_operator_last_name' => $isGotaContact ? $request->gota_operator_last_name : null,
            'gota_operator_callsign' => $isGotaContact ? $request->gota_operator_callsign : null,
            'gota_operator_user_id' => $isGotaContact ? $request->gota_operator_user_id : null,
            'gota_coach_user_id' => ($isGotaContact && $session->is_supervised) ? $session->operator_user_id : null,
        ]);

        // Dupe evaluation, insert and the session tally commit together under
        // the event lock: two operators logging the same station at once
        // cannot both get full points, and a failure anywhere leaves neither
        // the contact nor the qso_count behind. Bonus reconciliation and
        // notifications run after the commit, outside the lock.
        try {
            $dupeService->locked($eventConfigurationId, function () use ($dupeService, $contact, $session) {
                $dupeService->save($contact);
                $session->increment('qso_count');
            });
        } catch (UniqueConstraintViolationException) {
            return $this->idempotentResponse(Contact::where('uuid', $request->uuid)->firstOrFail());
        }

        $event = $session->station->eventConfiguration->event;

        try {
            ContactLogged::dispatch($contact->load(['band', 'mode', 'section']), $event);
        } catch (\Throwable $e) {
            report($e);
        }

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
     *
     * Everything is judged on the application clock (appNow()): sessions
     * start and end on it, and the contact queue stamps qso_time on it.
     * A future time is checked first and answered with the server time and
     * a code, so a queue whose offset was wrong can re-stamp and retry
     * instead of being told the event has ended.
     */
    private function timingError(OperatingSession $session, Carbon $qsoTime): ?JsonResponse
    {
        $serverNow = appNow();

        if ($qsoTime->gt($serverNow->copy()->addMinutes(self::FUTURE_SKEW_MINUTES))) {
            return response()->json([
                'message' => 'This contact is dated in the future. Check the clock on this device.',
                'code' => 'qso_time_in_future',
                'server_time' => $serverNow->getTimestampMs(),
            ], 422);
        }

        if ($session->end_time !== null && ! $qsoTime->between($session->start_time, $session->end_time)) {
            return response()->json(['message' => 'This operating session has ended.'], 422);
        }

        $event = $session->station->eventConfiguration?->event;
        if (! $event || Carbon::parse($event->end_time)->lt($qsoTime)) {
            return response()->json(['message' => 'This event has ended. Use Transcribe Paper Log to enter contacts from paper logs.'], 422);
        }

        if ($event->operatingEndsAt()?->lt($qsoTime)) {
            return response()->json(['message' => 'Your 24-hour operating period has ended. Use Transcribe Paper Log to enter contacts from paper logs.'], 422);
        }

        if (Carbon::parse($event->start_time)->gt($qsoTime)) {
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
