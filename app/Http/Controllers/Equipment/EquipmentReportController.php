<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\EquipmentReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for generating and exporting equipment reports.
 *
 * Handles all 7 report types with PDF and CSV export capabilities.
 */
class EquipmentReportController extends Controller
{
    use AuthorizesRequests;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const HEADER_EVENT_PREFIX = 'Event: ';

    private const HEADER_GENERATED_PREFIX = 'Generated: ';

    private const COLUMN_POWER_WATTS = 'Power (W)';

    private const COLUMN_VALUE_USD = 'Value (USD)';

    public function __construct(
        protected EquipmentReportService $reportService
    ) {}

    /**
     * Export commitment summary report (CSV).
     *
     * Pre-event planning report with equipment grouped by type,
     * delivery timeline, and contact information.
     */
    public function commitmentSummary(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateCommitmentSummary($event->id);

        $filename = $this->generateFilename($event, 'commitment-summary', 'csv');

        return $this->exportCsv($filename, function ($handle) use ($data) {
            // Header
            fputcsv($handle, ['Equipment Commitment Summary']);
            fputcsv($handle, [self::HEADER_EVENT_PREFIX.$data['event']->name]);
            fputcsv($handle, [self::HEADER_GENERATED_PREFIX.now()->format(self::DATETIME_FORMAT)]);
            fputcsv($handle, []);

            // Summary Statistics
            fputcsv($handle, ['Summary Statistics']);
            fputcsv($handle, ['Total Items', $data['summary']['total_items']]);
            fputcsv($handle, ['Total Value (USD)', '$'.$data['summary']['total_value']]);
            fputcsv($handle, []);

            // Equipment by Type
            fputcsv($handle, ['Equipment by Type']);
            fputcsv($handle, ['Type', 'Make', 'Model', 'Owner', 'Callsign', 'Status', 'Expected Delivery', 'Bands', self::COLUMN_POWER_WATTS, self::COLUMN_VALUE_USD]);
            foreach ($data['equipment_by_type'] as $typeGroup) {
                foreach ($typeGroup['items'] as $item) {
                    fputcsv($handle, [
                        ucfirst(str_replace('_', ' ', $typeGroup['type'])),
                        $item['make'],
                        $item['model'],
                        $item['owner_name'],
                        $item['owner_callsign'],
                        ucfirst(str_replace('_', ' ', $item['status'])),
                        $item['expected_delivery'] ?? 'TBD',
                        $item['bands'],
                        $item['power_watts'] ?? 'N/A',
                        $item['value_usd'] ? '$'.number_format($item['value_usd'], 2) : 'N/A',
                    ]);
                }
            }
            fputcsv($handle, []);

            // Contacts
            fputcsv($handle, ['Owner Contacts']);
            fputcsv($handle, ['Owner Name', 'Callsign', 'Email', 'Phone', 'Equipment Count']);
            foreach ($data['contacts'] as $contact) {
                fputcsv($handle, [
                    $contact['owner_name'],
                    $contact['callsign'],
                    $contact['email'],
                    $contact['phone'],
                    $contact['equipment_count'],
                ]);
            }
        });
    }

    /**
     * Export delivery checklist report (PDF).
     *
     * Gate check-in checklist for equipment delivery with checkboxes.
     */
    public function deliveryChecklist(Event $event): Response
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateDeliveryChecklist($event->id);

        return $this->exportPdf('equipment.reports.delivery-checklist', $data, $event, 'delivery-checklist');
    }

    /**
     * Export station inventory report (PDF).
     *
     * Per-station equipment listing with capabilities and owner contacts.
     */
    public function stationInventoryPdf(Event $event): Response
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateStationInventory($event->id);

        return $this->exportPdf('equipment.reports.station-inventory', $data, $event, 'station-inventory');
    }

    /**
     * Export station inventory report (CSV).
     *
     * Per-station equipment listing with capabilities and owner contacts.
     */
    public function stationInventoryCsv(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateStationInventory($event->id);

        $filename = $this->generateFilename($event, 'station-inventory', 'csv');

        return $this->exportCsv($filename, function ($handle) use ($data) {
            // Header
            fputcsv($handle, ['Station Equipment Inventory']);
            fputcsv($handle, [self::HEADER_EVENT_PREFIX.$data['event']->name]);
            fputcsv($handle, [self::HEADER_GENERATED_PREFIX.now()->format(self::DATETIME_FORMAT)]);
            fputcsv($handle, []);

            // Stations
            foreach ($data['stations'] as $station) {
                fputcsv($handle, ['Station: '.$station['station_name'].' ('.$station['equipment_count'].' items)']);
                $this->writeEquipmentCsvRows($handle, $station['equipment']);
                fputcsv($handle, []);
            }

            // Unassigned Equipment
            if ($data['unassigned_equipment']->count() > 0) {
                fputcsv($handle, ['Unassigned Equipment']);
                $this->writeEquipmentCsvRows($handle, $data['unassigned_equipment']);
            }
        });
    }

    /**
     * Export owner contact list report (PDF).
     *
     * Simple contact reference list for equipment owners.
     */
    public function ownerContactListPdf(Event $event): Response
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateOwnerContactList($event->id);

        return $this->exportPdf('equipment.reports.owner-contacts', $data, $event, 'owner-contacts');
    }

    /**
     * Export owner contact list report (CSV).
     *
     * Simple contact reference list for equipment owners.
     */
    public function ownerContactListCsv(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateOwnerContactList($event->id);

        $filename = $this->generateFilename($event, 'owner-contacts', 'csv');

        return $this->exportCsv($filename, function ($handle) use ($data) {
            // Header
            fputcsv($handle, ['Equipment Owner Contact List']);
            fputcsv($handle, [self::HEADER_EVENT_PREFIX.$data['event']->name]);
            fputcsv($handle, [self::HEADER_GENERATED_PREFIX.now()->format(self::DATETIME_FORMAT)]);
            fputcsv($handle, []);

            // Contacts
            fputcsv($handle, ['Owner Name', 'Callsign', 'Email', 'Phone', 'Emergency Contacts', 'Equipment Count']);
            foreach ($data['contacts'] as $contact) {
                fputcsv($handle, [
                    $contact['owner_name'],
                    $contact['callsign'],
                    $contact['email'],
                    $contact['primary_phone'],
                    $contact['emergency_contacts']->implode(', '),
                    $contact['equipment_count'],
                ]);
            }
        });
    }

    /**
     * Export return checklist report (PDF).
     *
     * Post-event return tracking with checkboxes and signature lines.
     */
    public function returnChecklist(Event $event): Response
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateReturnChecklist($event->id);

        return $this->exportPdf('equipment.reports.return-checklist', $data, $event, 'return-checklist');
    }

    /**
     * Export incident report (PDF).
     *
     * Lost/damaged equipment report with circumstances and value.
     */
    public function incidentReportPdf(Event $event): Response
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateIncidentReport($event->id);

        return $this->exportPdf('equipment.reports.incident-report', $data, $event, 'incident-report');
    }

    /**
     * Export incident report (CSV).
     *
     * Lost/damaged equipment report with circumstances and value.
     */
    public function incidentReportCsv(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateIncidentReport($event->id);

        $filename = $this->generateFilename($event, 'incident-report', 'csv');

        return $this->exportCsv($filename, function ($handle) use ($data) {
            // Header
            fputcsv($handle, ['Equipment Incident Report']);
            fputcsv($handle, [self::HEADER_EVENT_PREFIX.$data['event']->name]);
            fputcsv($handle, [self::HEADER_GENERATED_PREFIX.now()->format(self::DATETIME_FORMAT)]);
            fputcsv($handle, []);

            // Summary
            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Total Incidents', $data['summary']['total_incidents']]);
            fputcsv($handle, ['Total Value at Risk (USD)', '$'.$data['summary']['total_value_at_risk']]);
            fputcsv($handle, []);

            // Incidents
            fputcsv($handle, ['Incident Details']);
            fputcsv($handle, ['Equipment', 'Type', 'Serial Number', 'Status', 'Owner', 'Callsign', 'Contact', self::COLUMN_VALUE_USD, 'Station', 'Changed At', 'Changed By', 'Circumstances']);
            foreach ($data['incidents'] as $incident) {
                fputcsv($handle, [
                    $incident['equipment_description'],
                    ucfirst(str_replace('_', ' ', $incident['type'])),
                    $incident['serial_number'] ?? 'N/A',
                    ucfirst(str_replace('_', ' ', $incident['status'])),
                    $incident['owner_name'],
                    $incident['owner_callsign'],
                    $incident['owner_contact'],
                    $incident['value_usd'] ? '$'.number_format($incident['value_usd'], 2) : 'N/A',
                    $incident['station'],
                    $incident['status_changed_at'] ?? 'N/A',
                    $incident['status_changed_by'],
                    $incident['circumstances'] ?? '',
                ]);
            }
        });
    }

    /**
     * Export historical record report (CSV).
     *
     * Complete event equipment record with final status and notes.
     */
    public function historicalRecord(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateHistoricalRecord($event->id);

        $filename = $this->generateFilename($event, 'historical-record', 'csv');

        return $this->exportCsv($filename, function ($handle) use ($data) {
            // Header
            fputcsv($handle, ['Equipment Historical Record']);
            fputcsv($handle, [self::HEADER_EVENT_PREFIX.$data['event']->name]);
            fputcsv($handle, [self::HEADER_GENERATED_PREFIX.now()->format(self::DATETIME_FORMAT)]);
            fputcsv($handle, []);

            // Summary
            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Total Equipment', $data['summary']['total_equipment']]);
            fputcsv($handle, ['Total Value (USD)', '$'.$data['summary']['total_value']]);
            fputcsv($handle, ['Success Rate', $data['summary']['success_rate']]);
            fputcsv($handle, []);

            // Equipment Records
            fputcsv($handle, ['Equipment Records']);
            fputcsv($handle, ['Type', 'Make', 'Model', 'Owner', 'Callsign', 'Station', 'Bands', self::COLUMN_POWER_WATTS, 'Committed At', 'Expected Delivery', 'Final Status', 'Status Changed At', 'Changed By', self::COLUMN_VALUE_USD, 'Notes']);
            foreach ($data['equipment_records'] as $record) {
                fputcsv($handle, [
                    ucfirst(str_replace('_', ' ', $record['type'])),
                    $record['make'],
                    $record['model'],
                    $record['owner_name'],
                    $record['owner_callsign'],
                    $record['station_assigned'],
                    $record['bands_supported'],
                    $record['power_watts'] ?? 'N/A',
                    $record['committed_at'] ?? 'N/A',
                    $record['expected_delivery_at'] ?? 'N/A',
                    ucfirst(str_replace('_', ' ', $record['final_status'])),
                    $record['status_changed_at'] ?? 'N/A',
                    $record['status_changed_by'],
                    $record['value_usd'] ? '$'.number_format($record['value_usd'], 2) : 'N/A',
                    implode(' | ', array_filter([
                        $record['delivery_notes'],
                        $record['manager_notes'],
                        $record['equipment_notes'],
                    ])),
                ]);
            }
        });
    }

    /**
     * Write equipment CSV rows with a header line.
     *
     * @param  resource  $handle  CSV file handle
     * @param  iterable<array<string, mixed>>  $equipmentList  Equipment data rows
     */
    protected function writeEquipmentCsvRows($handle, iterable $equipmentList): void
    {
        fputcsv($handle, ['Type', 'Description', 'Bands', self::COLUMN_POWER_WATTS, 'Owner', 'Callsign', 'Contact', 'Status']);
        foreach ($equipmentList as $equipment) {
            fputcsv($handle, [
                ucfirst(str_replace('_', ' ', $equipment['type'])),
                $equipment['description'],
                $equipment['bands'] ?: 'N/A',
                $equipment['power_watts'] ?? 'N/A',
                $equipment['owner_name'],
                $equipment['owner_callsign'],
                $equipment['owner_contact'],
                ucfirst(str_replace('_', ' ', $equipment['status'])),
            ]);
        }
    }

    /**
     * Generate a filename for the export.
     */
    protected function generateFilename(Event $event, string $reportType, string $extension): string
    {
        $eventSlug = Str::slug($event->name);
        $date = now()->format('Y-m-d');

        return "{$eventSlug}-{$reportType}-{$date}.{$extension}";
    }

    /**
     * Export data as CSV.
     */
    protected function exportCsv(string $filename, callable $callback): StreamedResponse
    {
        return response()->streamDownload(function () use ($callback) {
            $handle = fopen('php://output', 'w');
            $callback($handle);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Render a report blade view as a downloadable PDF.
     *
     * @param  array<string, mixed>  $data  Data passed through to the view
     */
    protected function exportPdf(string $view, array $data, Event $event, string $reportType): Response
    {
        $filename = $this->generateFilename($event, $reportType, 'pdf');

        $pdf = Pdf::loadView($view, $data + ['generated_at' => now()])
            ->setPaper('letter', 'portrait');

        return $pdf->download($filename);
    }
}
