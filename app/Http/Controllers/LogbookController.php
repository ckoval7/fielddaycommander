<?php

namespace App\Http\Controllers;

use App\Services\ContactExporter;
use App\Services\EventContextService;
use App\Services\LogbookQueryBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogbookController extends Controller
{
    public function __construct(
        protected LogbookQueryBuilder $queryBuilder,
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
     * Export filtered contacts to CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        // Get active event
        $activeEvent = $this->eventContext->getContextEvent();

        if (! $activeEvent || ! $activeEvent->eventConfiguration) {
            abort(404, 'No active event found');
        }

        // Build filters from request
        $filters = [
            'event_configuration_id' => $activeEvent->eventConfiguration->id,
            'band_ids' => array_filter([$request->input('band_id')]),
            'mode_ids' => array_filter([$request->input('mode_id')]),
            'station_ids' => array_filter([$request->input('station_id')]),
            'operator_ids' => array_filter([$request->input('operator_id')]),
            'time_from' => $request->input('time_from'),
            'time_to' => $request->input('time_to'),
            'callsign' => $request->input('callsign'),
            'section_ids' => array_filter([$request->input('section_id')]),
            'duplicate_filter' => $request->input('duplicate_filter'),
        ];

        // Get filtered contacts
        $contacts = $this->queryBuilder
            ->applyFilters($filters)
            ->get();

        return $this->exporter->exportCsv($contacts);
    }
}
