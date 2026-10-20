<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Http\Controllers;

use App\Services\AdifExporter;
use App\Services\CabrilloExporter;
use App\Services\EventContextService;
use App\Services\SubmissionReportService;
use App\Services\WfdEventSummaryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for generating and downloading Field Day report files.
 *
 * Handles Cabrillo and ADIF log export, the ARRL submission sheet and the
 * Winter Field Day event summary for the active event.
 */
class ReportController extends Controller
{
    public function __construct(
        protected CabrilloExporter $cabrilloExporter,
        protected AdifExporter $adifExporter,
        protected WfdEventSummaryService $wfdEventSummaryService,
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
     * Download the ADIF log for the active event.
     */
    public function adif(): StreamedResponse
    {
        $config = $this->eventContextService->getEventConfiguration();

        if ($config === null) {
            abort(404);
        }

        $content = $this->adifExporter->export($config);
        $filename = $this->adifExporter->filename($config);

        return response()->streamDownload(
            function () use ($content) {
                echo $content;
            },
            $filename,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * Download the Winter Field Day event summary PDF for the active event.
     *
     * The counterpart of the ARRL submission sheet for rulebooks that do not
     * use it; Field Day events have no such document.
     */
    public function wfdEventSummary(): Response
    {
        $config = $this->eventContextService->getEventConfiguration();

        if ($config === null || $config->usesArrlSubmissionSheet()) {
            abort(404);
        }

        $data = $this->wfdEventSummaryService->getData($config);

        $callsign = strtolower(str_replace('/', '_', $config->callsign));
        $filename = "{$callsign}-{$data['event_year']}-event-summary.pdf";

        return Pdf::loadView('reports.wfd-event-summary-pdf', $data)
            ->setPaper('letter', 'portrait')
            ->stream($filename);
    }

    /**
     * Open the ARRL submission sheet PDF for the active event.
     */
    public function submissionSheet(): Response
    {
        $config = $this->eventContextService->getEventConfiguration();

        if ($config === null) {
            abort(404);
        }

        // The sheet mirrors ARRL's Field Day entry form. Winter Field Day has
        // no paper equivalent — entrants upload a log and tick their objectives
        // on winterfieldday.org — so rendering it there would hand the operator
        // a form no one accepts.
        if (! $config->usesArrlSubmissionSheet()) {
            abort(404);
        }

        $data = $this->submissionReportService->getData($config);

        $callsign = strtolower($config->callsign);
        $year = $config->event->start_time->year;
        $filename = "{$callsign}-{$year}-submission-sheet.pdf";

        $pdf = Pdf::loadView('reports.submission-sheet-pdf', $data)
            ->setPaper('letter', 'portrait');

        // The footer's "of N" comes from the expected page count; a long
        // bulletin copy can spill onto extra pages, so render again with
        // the real count when they differ.
        $pdf->render();
        $pageCount = $pdf->getDomPDF()->getCanvas()->get_page_count();

        if ($pageCount !== $data['total_pages']) {
            $pdf = Pdf::loadView('reports.submission-sheet-pdf', ['total_pages' => $pageCount] + $data)
                ->setPaper('letter', 'portrait');
        }

        return $pdf->stream($filename);
    }
}
