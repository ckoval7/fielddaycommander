<?php

namespace App\Http\Controllers;

use App\Events\ContactLogged;
use App\Http\Requests\StoreContactSyncRequest;
use App\Models\Contact;
use App\Models\OperatingSession;
use App\Services\DuplicateCheckService;
use Illuminate\Http\JsonResponse;

class ContactSyncController extends Controller
{
    public function store(StoreContactSyncRequest $request, DuplicateCheckService $dupeService): JsonResponse
    {
        // Idempotency: if this UUID already exists, return the existing contact
        $existing = Contact::where('uuid', $request->uuid)->first();
        if ($existing) {
            return response()->json([
                'uuid' => $existing->uuid,
                'contact_id' => $existing->id,
                'points' => $existing->points,
                'is_duplicate' => $existing->is_duplicate,
            ], 200);
        }

        $session = OperatingSession::findOrFail($request->operating_session_id);

        $dupeCheck = $dupeService->check(
            $request->callsign,
            $request->band_id,
            $request->mode_id,
            $session->station->event_configuration_id,
        );

        $mode = $session->mode;

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
            'points' => $dupeCheck['is_duplicate'] ? 0 : $mode->points_fd,
            'is_duplicate' => $dupeCheck['is_duplicate'],
            'duplicate_of_contact_id' => $dupeCheck['duplicate_of_contact_id'],
        ]);

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
}
