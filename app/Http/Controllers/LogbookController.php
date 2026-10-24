<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers;

use App\Services\ContactExporter;
use App\Services\EventContextService;
use App\Services\LogbookFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogbookController extends Controller
{
    public function __construct(
        protected ContactExporter $exporter,
        protected EventContextService $eventContext
    ) {}

    /**
     * Display the logbook browser.
     */
    public function index(): View
    {
        // Get active event for context
        $activeEvent = $this->eventContext->getContextEvent();

        return view('logbook.index', [
            'activeEvent' => $activeEvent,
        ]);
    }

    /**
     * Export the contacts the logbook browser lists to CSV.
     *
     * Takes the browser's own query parameters (`event`, `bandIds[]`,
     * `callsignSearch`, ...) and runs the same filtered query, streaming rows
     * in chunks so large logs stay within memory.
     */
    public function export(Request $request): StreamedResponse
    {
        $eventId = filter_var($request->query('event'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $event = LogbookFilters::resolveEvent($eventId === false ? null : $eventId);

        if (! $event || ! $event->eventConfiguration) {
            abort(404, 'No active event found');
        }

        $contacts = LogbookFilters::fromArray($request->query())
            ->query($event->eventConfiguration->id)
            ->orderByDesc('id')
            ->lazy(500);

        return $this->exporter->exportCsv($contacts);
    }
}
