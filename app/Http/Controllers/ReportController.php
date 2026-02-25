<?php

namespace App\Http\Controllers;

use App\Services\CabrilloExporter;
use App\Services\ClubSummaryReportService;
use App\Services\EventContextService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for generating and downloading Field Day report files.
 *
 * Handles Cabrillo log export and the club summary PDF for the active event.
 */
class ReportController extends Controller
{
    public function __construct(
        protected CabrilloExporter $cabrilloExporter,
        protected ClubSummaryReportService $clubSummaryReportService,
        protected EventContextService $eventContextService,
    ) {}

    /**
     * Download the Cabrillo log file for the active event.
     */
    public function cabrillo(): StreamedResponse
    {
        $config = $this->eventContextService->getEventConfiguration();

        if ($config === null) {
            abort(404);
        }

        $content = $this->cabrilloExporter->export($config);
        $filename = $this->cabrilloExporter->filename($config);

        return response()->streamDownload(
            function () use ($content) {
                echo $content;
            },
            $filename,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * Download the club summary PDF for the active event.
     */
    public function clubSummary(): Response
    {
        $config = $this->eventContextService->getEventConfiguration();

        if ($config === null) {
            abort(404);
        }

        $data = $this->clubSummaryReportService->getData($config);

        $config->loadMissing('event');
        $callsign = strtolower($config->callsign);
        $year = $config->event->start_time->year;
        $filename = "{$callsign}-{$year}-club-summary.pdf";

        $pdf = Pdf::loadView('reports.club-summary-pdf', $data)
            ->setPaper('letter', 'portrait');

        return $pdf->download($filename);
    }
}
