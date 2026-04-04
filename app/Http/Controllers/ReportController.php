<?php

namespace App\Http\Controllers;

use App\Services\CabrilloExporter;
use App\Services\EventContextService;
use App\Services\SubmissionReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for generating and downloading Field Day report files.
 *
 * Handles Cabrillo log export and ARRL submission sheet for the active event.
 */
class ReportController extends Controller
{
    public function __construct(
        protected CabrilloExporter $cabrilloExporter,
        protected SubmissionReportService $submissionReportService,
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
     * Download the ARRL submission sheet PDF for the active event.
     */
    public function submissionSheet(): Response
    {
        $config = $this->eventContextService->getEventConfiguration();

        if ($config === null) {
            abort(404);
        }

        $data = $this->submissionReportService->getData($config);

        $callsign = strtolower($config->callsign);
        $year = $config->event->start_time->year;
        $filename = "{$callsign}-{$year}-submission-sheet.pdf";

        $pdf = Pdf::loadView('reports.submission-sheet-pdf', $data)
            ->setPaper('letter', 'portrait');

        return $pdf->download($filename);
    }
}
