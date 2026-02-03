<?php

namespace App\Http\Controllers\Equipment;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\EquipmentReportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
            fputcsv($handle, ['Event: '.$data['event']->name]);
            fputcsv($handle, ['Generated: '.now()->format('Y-m-d H:i:s')]);
            fputcsv($handle, []);

            // Summary Statistics
            fputcsv($handle, ['Summary Statistics']);
            fputcsv($handle, ['Total Items', $data['summary']['total_items']]);
            fputcsv($handle, ['Total Value (USD)', '$'.$data['summary']['total_value']]);
            fputcsv($handle, []);

            // Equipment by Type
            fputcsv($handle, ['Equipment by Type']);
            fputcsv($handle, ['Type', 'Make', 'Model', 'Owner', 'Callsign', 'Status', 'Expected Delivery', 'Bands', 'Power (W)', 'Value (USD)']);
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
    public function deliveryChecklist(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateDeliveryChecklist($event->id);

        $filename = $this->generateFilename($event, 'delivery-checklist', 'html');

        return $this->exportPdf($filename, function () use ($data) {
            return $this->generateDeliveryChecklistHtml($data);
        });
    }

    /**
     * Export station inventory report (PDF).
     *
     * Per-station equipment listing with capabilities and owner contacts.
     */
    public function stationInventoryPdf(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateStationInventory($event->id);

        $filename = $this->generateFilename($event, 'station-inventory', 'html');

        return $this->exportPdf($filename, function () use ($data) {
            return $this->generateStationInventoryHtml($data);
        });
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
            fputcsv($handle, ['Event: '.$data['event']->name]);
            fputcsv($handle, ['Generated: '.now()->format('Y-m-d H:i:s')]);
            fputcsv($handle, []);

            // Stations
            foreach ($data['stations'] as $station) {
                fputcsv($handle, ['Station: '.$station['station_name'].' ('.$station['equipment_count'].' items)']);
                fputcsv($handle, ['Type', 'Description', 'Bands', 'Power (W)', 'Owner', 'Callsign', 'Contact', 'Status']);
                foreach ($station['equipment'] as $equipment) {
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
                fputcsv($handle, []);
            }

            // Unassigned Equipment
            if ($data['unassigned_equipment']->count() > 0) {
                fputcsv($handle, ['Unassigned Equipment']);
                fputcsv($handle, ['Type', 'Description', 'Bands', 'Power (W)', 'Owner', 'Callsign', 'Contact', 'Status']);
                foreach ($data['unassigned_equipment'] as $equipment) {
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
        });
    }

    /**
     * Export owner contact list report (PDF).
     *
     * Simple contact reference list for equipment owners.
     */
    public function ownerContactListPdf(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateOwnerContactList($event->id);

        $filename = $this->generateFilename($event, 'owner-contacts', 'html');

        return $this->exportPdf($filename, function () use ($data) {
            return $this->generateOwnerContactsHtml($data);
        });
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
            fputcsv($handle, ['Event: '.$data['event']->name]);
            fputcsv($handle, ['Generated: '.now()->format('Y-m-d H:i:s')]);
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
    public function returnChecklist(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateReturnChecklist($event->id);

        $filename = $this->generateFilename($event, 'return-checklist', 'html');

        return $this->exportPdf($filename, function () use ($data) {
            return $this->generateReturnChecklistHtml($data);
        });
    }

    /**
     * Export incident report (PDF).
     *
     * Lost/damaged equipment report with circumstances and value.
     */
    public function incidentReportPdf(Event $event): StreamedResponse
    {
        $this->authorize('manage-event-equipment');

        $data = $this->reportService->generateIncidentReport($event->id);

        $filename = $this->generateFilename($event, 'incident-report', 'html');

        return $this->exportPdf($filename, function () use ($data) {
            return $this->generateIncidentReportHtml($data);
        });
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
            fputcsv($handle, ['Event: '.$data['event']->name]);
            fputcsv($handle, ['Generated: '.now()->format('Y-m-d H:i:s')]);
            fputcsv($handle, []);

            // Summary
            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Total Incidents', $data['summary']['total_incidents']]);
            fputcsv($handle, ['Total Value at Risk (USD)', '$'.$data['summary']['total_value_at_risk']]);
            fputcsv($handle, []);

            // Incidents
            fputcsv($handle, ['Incident Details']);
            fputcsv($handle, ['Equipment', 'Type', 'Serial Number', 'Status', 'Owner', 'Callsign', 'Contact', 'Value (USD)', 'Station', 'Changed At', 'Changed By', 'Circumstances']);
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
            fputcsv($handle, ['Event: '.$data['event']->name]);
            fputcsv($handle, ['Generated: '.now()->format('Y-m-d H:i:s')]);
            fputcsv($handle, []);

            // Summary
            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Total Equipment', $data['summary']['total_equipment']]);
            fputcsv($handle, ['Total Value (USD)', '$'.$data['summary']['total_value']]);
            fputcsv($handle, ['Success Rate', $data['summary']['success_rate']]);
            fputcsv($handle, []);

            // Equipment Records
            fputcsv($handle, ['Equipment Records']);
            fputcsv($handle, ['Type', 'Make', 'Model', 'Owner', 'Callsign', 'Station', 'Bands', 'Power (W)', 'Committed At', 'Expected Delivery', 'Final Status', 'Status Changed At', 'Changed By', 'Value (USD)', 'Notes']);
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
     * Export data as PDF (simple HTML).
     *
     * For now, we're using HTML export. In the future, this could be
     * enhanced with a proper PDF library like dompdf or wkhtmltopdf.
     */
    protected function exportPdf(string $filename, callable $callback): StreamedResponse
    {
        $html = $callback();

        return response()->streamDownload(function () use ($html) {
            echo $html;
        }, $filename, [
            'Content-Type' => 'text/html',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Generate simple HTML for delivery checklist.
     */
    protected function generateDeliveryChecklistHtml(array $data): string
    {
        $html = '<!DOCTYPE html><html><head><title>Delivery Checklist</title></head><body>';
        $html .= '<h1>Equipment Delivery Checklist</h1>';
        $html .= '<p><strong>Event:</strong> '.$data['event']->name.'</p>';
        $html .= '<p><strong>Generated:</strong> '.now()->format('Y-m-d H:i:s').'</p>';
        $html .= '<table border="1" cellpadding="5"><thead><tr>';
        $html .= '<th>☐</th><th>Expected Delivery</th><th>Equipment</th><th>Owner</th><th>Contact</th><th>Signature</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($data['checklist_items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>☐</td>';
            $html .= '<td>'.($item['expected_delivery'] ?? 'TBD').'</td>';
            $html .= '<td>'.$item['equipment_description'].'</td>';
            $html .= '<td>'.$item['owner_name'].' ('.$item['owner_callsign'].')</td>';
            $html .= '<td>'.$item['owner_phone'].'</td>';
            $html .= '<td>_______________________</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        return $html;
    }

    /**
     * Generate simple HTML for station inventory.
     */
    protected function generateStationInventoryHtml(array $data): string
    {
        $html = '<!DOCTYPE html><html><head><title>Station Inventory</title></head><body>';
        $html .= '<h1>Station Equipment Inventory</h1>';
        $html .= '<p><strong>Event:</strong> '.$data['event']->name.'</p>';
        foreach ($data['stations'] as $station) {
            $html .= '<h2>'.$station['station_name'].'</h2>';
            $html .= '<table border="1" cellpadding="5"><thead><tr>';
            $html .= '<th>Type</th><th>Description</th><th>Owner</th><th>Contact</th><th>Status</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($station['equipment'] as $eq) {
                $html .= '<tr>';
                $html .= '<td>'.ucfirst(str_replace('_', ' ', $eq['type'])).'</td>';
                $html .= '<td>'.$eq['description'].'</td>';
                $html .= '<td>'.$eq['owner_name'].' ('.$eq['owner_callsign'].')</td>';
                $html .= '<td>'.$eq['owner_contact'].'</td>';
                $html .= '<td>'.ucfirst(str_replace('_', ' ', $eq['status'])).'</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table><br>';
        }
        $html .= '</body></html>';

        return $html;
    }

    /**
     * Generate simple HTML for owner contacts.
     */
    protected function generateOwnerContactsHtml(array $data): string
    {
        $html = '<!DOCTYPE html><html><head><title>Owner Contacts</title></head><body>';
        $html .= '<h1>Equipment Owner Contact List</h1>';
        $html .= '<p><strong>Event:</strong> '.$data['event']->name.'</p>';
        $html .= '<table border="1" cellpadding="5"><thead><tr>';
        $html .= '<th>Owner</th><th>Callsign</th><th>Email</th><th>Phone</th><th>Equipment Count</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($data['contacts'] as $contact) {
            $html .= '<tr>';
            $html .= '<td>'.$contact['owner_name'].'</td>';
            $html .= '<td>'.$contact['callsign'].'</td>';
            $html .= '<td>'.$contact['email'].'</td>';
            $html .= '<td>'.$contact['primary_phone'].'</td>';
            $html .= '<td>'.$contact['equipment_count'].'</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        return $html;
    }

    /**
     * Generate simple HTML for return checklist.
     */
    protected function generateReturnChecklistHtml(array $data): string
    {
        $html = '<!DOCTYPE html><html><head><title>Return Checklist</title></head><body>';
        $html .= '<h1>Equipment Return Checklist</h1>';
        $html .= '<p><strong>Event:</strong> '.$data['event']->name.'</p>';
        $html .= '<table border="1" cellpadding="5"><thead><tr>';
        $html .= '<th>☐</th><th>Equipment</th><th>Owner</th><th>Station</th><th>Signature</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($data['return_items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>☐</td>';
            $html .= '<td>'.$item['equipment_description'].'</td>';
            $html .= '<td>'.$item['owner_name'].' ('.$item['owner_callsign'].')</td>';
            $html .= '<td>'.$item['station'].'</td>';
            $html .= '<td>_______________________</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        return $html;
    }

    /**
     * Generate simple HTML for incident report.
     */
    protected function generateIncidentReportHtml(array $data): string
    {
        $html = '<!DOCTYPE html><html><head><title>Incident Report</title></head><body>';
        $html .= '<h1>Equipment Incident Report</h1>';
        $html .= '<p><strong>Event:</strong> '.$data['event']->name.'</p>';
        $html .= '<p><strong>Total Incidents:</strong> '.$data['summary']['total_incidents'].'</p>';
        $html .= '<p><strong>Total Value at Risk:</strong> $'.$data['summary']['total_value_at_risk'].'</p>';
        $html .= '<table border="1" cellpadding="5"><thead><tr>';
        $html .= '<th>Equipment</th><th>Status</th><th>Owner</th><th>Value</th><th>Circumstances</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($data['incidents'] as $incident) {
            $html .= '<tr>';
            $html .= '<td>'.$incident['equipment_description'].'</td>';
            $html .= '<td>'.ucfirst(str_replace('_', ' ', $incident['status'])).'</td>';
            $html .= '<td>'.$incident['owner_name'].' ('.$incident['owner_callsign'].')</td>';
            $html .= '<td>$'.number_format($incident['value_usd'] ?? 0, 2).'</td>';
            $html .= '<td>'.$incident['circumstances'].'</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        return $html;
    }
}
