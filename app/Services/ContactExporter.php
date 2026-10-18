<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Services;

use App\Support\CsvSafe;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactExporter
{
    /**
     * Export contacts to CSV format.
     *
     * @param  Collection  $contacts  Collection of Contact models
     */
    public function exportCsv(Collection $contacts): StreamedResponse
    {
        $filename = 'field-day-logbook-'.date('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($contacts) {
            $output = fopen('php://output', 'w');

            // Write headers
            $headers = [
                'QSO Time',
                'Callsign',
                'Band',
                'Mode',
                'Section',
                'Class',
                'Points',
                'Duplicate Status',
                'Logger',
                'Station',
            ];
            CsvSafe::put($output, $headers);

            // Write data rows
            foreach ($contacts as $contact) {
                CsvSafe::put($output, [
                    $contact->qso_time?->format('Y-m-d H:i:s') ?? '',
                    $contact->callsign ?? '',
                    $contact->band?->name ?? '',
                    $contact->mode?->name ?? '',
                    $contact->section?->code ?? '',
                    $contact->exchange_class ?? '',
                    $contact->points ?? 0,
                    $contact->is_duplicate ? 'Yes' : 'No',
                    $contact->logger?->name ?? '',
                    $contact->operatingSession?->station?->name ?? '',
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
