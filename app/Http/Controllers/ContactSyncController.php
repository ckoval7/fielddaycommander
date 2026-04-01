<?php

namespace App\Http\Controllers;

use App\Events\ContactLogged;
use App\Http\Requests\StoreContactSyncRequest;
use App\Models\Contact;
use App\Models\OperatingSession;
use App\Services\DuplicateCheckService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

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

        if ($session->end_time !== null) {
            return response()->json(['message' => 'This operating session has ended.'], 422);
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

        try {
            $contact = Contact::create([
                'uuid' => $request->uuid,
                'event_configuration_id' => $session->station->event_configuration_id,
                'operating_session_id' => $session->id,
                'logger_user_id' => $request->user()->id,
                'band_id' => $request->band_id,
                'mode_id' => $request->mode_id,
                'qso_time' => $request->qso_time,
                'callsign' => $request->callsign,
                'section_id' => $request->section_id,
                'received_exchange' => $request->received_exchange,
                'power_watts' => $request->power_watts,
                'points' => $dupeCheck['is_duplicate'] ? 0 : ($isGotaContact ? 0 : $mode->points_fd),
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
