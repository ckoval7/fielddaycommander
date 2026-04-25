<?php

namespace App\Services;

use App\Models\EquipmentEvent;
use App\Models\Event;
use Illuminate\Support\Collection;

/**
 * Service class for generating equipment reports across 7 different report types.
 */
class EquipmentReportService
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Generate pre-event planning report with equipment commitments summary.
     *
     * Returns equipment grouped by type with counts, gaps analysis,
     * delivery timeline, contact information, and total value.
     *
     * @param  int  $eventId  The event ID to generate report for
     * @return array{
     *     event: Event,
     *     summary: array{
     *         total_items: int,
     *         total_value: string,
     *         by_type: Collection,
     *         by_status: Collection
     *     },
     *     equipment_by_type: Collection,
     *     delivery_timeline: Collection,
     *     contacts: Collection
     * }
     */
    public function generateCommitmentSummary(int $eventId): array
    {
        $event = Event::findOrFail($eventId);

        // Get all equipment commitments with relationships
        $commitments = EquipmentEvent::query()
            ->where('event_id', $eventId)
            ->with([
                'equipment.owner',
                'equipment.owningOrganization',
                'equipment.bands',
                'station',
            ])
            ->get();

        // Calculate summary statistics
        $totalValue = $commitments->sum(fn ($c) => $c->equipment->value_usd ?? 0);
        $byType = $commitments->groupBy('equipment.type')->map(fn ($items) => [
            'count' => $items->count(),
            'total_value' => $items->sum(fn ($c) => $c->equipment->value_usd ?? 0),
        ]);
        $byStatus = $commitments->groupBy('status')->map->count();

        // Group equipment by type with full details
        $equipmentByType = $commitments->groupBy('equipment.type')->map(function ($items, $type) {
            return [
                'type' => $type,
                'count' => $items->count(),
                'items' => $items->map(fn ($c) => [
                    'id' => $c->id,
                    'equipment_id' => $c->equipment_id,
                    'make' => $c->equipment->make,
                    'model' => $c->equipment->model,
                    'description' => $c->equipment->description,
                    'owner_name' => $c->equipment->owner_name,
                    'owner_callsign' => $c->equipment->owner?->call_sign ?? 'N/A',
                    'status' => $c->status,
                    'expected_delivery' => $c->expected_delivery_at?->format('Y-m-d H:i'),
                    'value_usd' => $c->equipment->value_usd,
                    'bands' => $c->equipment->bands->pluck('name')->implode(', '),
                    'power_watts' => $c->equipment->power_output_watts,
                ]),
            ];
        });

        // Create delivery timeline (grouped by delivery date)
        $deliveryTimeline = $commitments
            ->filter(fn ($c) => $c->expected_delivery_at !== null)
            ->sortBy('expected_delivery_at')
            ->groupBy(fn ($c) => $c->expected_delivery_at->format('Y-m-d'))
            ->map(function ($items, $date) {
                return [
                    'date' => $date,
                    'count' => $items->count(),
                    'items' => $items->map(fn ($c) => [
                        'time' => $c->expected_delivery_at->format('H:i'),
                        'equipment' => "{$c->equipment->make} {$c->equipment->model}",
                        'owner' => $c->equipment->owner_name,
                        'owner_callsign' => $c->equipment->owner?->call_sign ?? 'N/A',
                        'contact_phone' => $c->equipment->emergency_contact_phone ?? $c->equipment->owner?->email ?? 'N/A',
                    ]),
                ];
            });

        // Extract unique contacts
        $contacts = $commitments
            ->map(fn ($c) => [
                'owner_name' => $c->equipment->owner_name,
                'callsign' => $c->equipment->owner?->call_sign ?? 'N/A',
                'email' => $c->equipment->owner?->email ?? 'N/A',
                'phone' => $c->equipment->emergency_contact_phone ?? 'N/A',
                'equipment_count' => 1,
            ])
            ->groupBy('callsign')
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'owner_name' => $first['owner_name'],
                    'callsign' => $first['callsign'],
                    'email' => $first['email'],
                    'phone' => $first['phone'],
                    'equipment_count' => $items->count(),
                ];
            })
            ->values();

        return [
            'event' => $event,
            'summary' => [
                'total_items' => $commitments->count(),
                'total_value' => number_format($totalValue, 2),
                'by_type' => $byType,
                'by_status' => $byStatus,
            ],
            'equipment_by_type' => $equipmentByType,
            'delivery_timeline' => $deliveryTimeline,
            'contacts' => $contacts,
        ];
    }

    /**
     * Generate gate check-in checklist for equipment delivery.
     *
     * Returns equipment grouped by expected delivery time with checkboxes,
     * owner contact information, and equipment descriptions.
     * Sorted chronologically by expected_delivery_at.
     *
     * @param  int  $eventId  The event ID to generate checklist for
     * @return array{
     *     event: Event,
     *     checklist_items: Collection
     * }
     */
    public function generateDeliveryChecklist(int $eventId): array
    {
        $event = Event::findOrFail($eventId);

        $checklistItems = EquipmentEvent::query()
            ->where('event_id', $eventId)
            ->whereIn('status', ['committed', 'delivered'])
            ->with([
                'equipment.owner',
                'equipment.owningOrganization',
            ])
            ->orderBy('expected_delivery_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'checkbox' => '☐',
                'expected_delivery' => $c->expected_delivery_at?->format('Y-m-d') ?? 'TBD',
                'equipment_description' => trim("{$c->equipment->make} {$c->equipment->model}") ?: $c->equipment->description,
                'type' => $c->equipment->type,
                'owner_name' => $c->equipment->owner_name,
                'owner_callsign' => $c->equipment->owner?->call_sign ?? 'N/A',
                'owner_phone' => $c->equipment->emergency_contact_phone ?? $c->equipment->owner?->email ?? 'N/A',
                'delivery_notes' => $c->delivery_notes,
                'status' => $c->status,
                'signature_line' => '_______________________',
            ]);

        return [
            'event' => $event,
            'checklist_items' => $checklistItems,
        ];
    }

    /**
     * Generate per-station equipment inventory list.
     *
     * Returns sections per station with assigned equipment,
     * including capabilities (bands, power), owner contact, and setup notes.
     *
     * @param  int  $eventId  The event ID to generate inventory for
     * @return array{
     *     event: Event,
     *     stations: Collection,
     *     unassigned_equipment: Collection
     * }
     */
    public function generateStationInventory(int $eventId): array
    {
        $event = Event::findOrFail($eventId);

        // Get equipment commitments grouped by station
        $commitments = EquipmentEvent::query()
            ->where('event_id', $eventId)
            ->whereIn('status', ['committed', 'delivered'])
            ->with([
                'equipment.owner',
                'equipment.bands',
                'station',
            ])
            ->get();

        // Group by station
        $stations = $commitments
            ->filter(fn ($c) => $c->station_id !== null)
            ->groupBy('station_id')
            ->map(function ($items, $stationId) {
                $station = $items->first()->station;

                return [
                    'station_id' => $stationId,
                    'station_name' => $station?->name ?? "Station #{$stationId}",
                    'equipment_count' => $items->count(),
                    'equipment' => $items->map(fn ($c) => [
                        'id' => $c->id,
                        'type' => $c->equipment->type,
                        'description' => trim("{$c->equipment->make} {$c->equipment->model}") ?: $c->equipment->description,
                        'bands' => $c->equipment->bands->pluck('name')->implode(', '),
                        'power_watts' => $c->equipment->power_output_watts,
                        'owner_name' => $c->equipment->owner_name,
                        'owner_callsign' => $c->equipment->owner?->call_sign ?? 'N/A',
                        'owner_contact' => $c->equipment->emergency_contact_phone ?? $c->equipment->owner?->email ?? 'N/A',
                        'setup_notes' => $c->equipment->notes,
                        'delivery_notes' => $c->delivery_notes,
                        'status' => $c->status,
                    ]),
                ];
            });

        // Get unassigned equipment
        $unassignedEquipment = $commitments
            ->filter(fn ($c) => $c->station_id === null)
            ->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->equipment->type,
                'description' => trim("{$c->equipment->make} {$c->equipment->model}") ?: $c->equipment->description,
                'bands' => $c->equipment->bands->pluck('name')->implode(', '),
                'power_watts' => $c->equipment->power_output_watts,
                'owner_name' => $c->equipment->owner_name,
                'owner_callsign' => $c->equipment->owner?->call_sign ?? 'N/A',
                'owner_contact' => $c->equipment->emergency_contact_phone ?? $c->equipment->owner?->email ?? 'N/A',
                'status' => $c->status,
            ]);

        return [
            'event' => $event,
            'stations' => $stations,
            'unassigned_equipment' => $unassignedEquipment,
        ];
    }

    /**
     * Generate simple contact reference list for equipment owners.
     *
     * Returns operators with contact information and equipment summary,
     * including emergency contacts from equipment records.
     *
     * @param  int  $eventId  The event ID to generate contact list for
     * @return array{
     *     event: Event,
     *     contacts: Collection
     * }
     */
    public function generateOwnerContactList(int $eventId): array
    {
        $event = Event::findOrFail($eventId);

        $commitments = EquipmentEvent::query()
            ->where('event_id', $eventId)
            ->with([
                'equipment.owner',
            ])
            ->get();

        $contacts = $commitments
            ->groupBy(fn ($c) => $c->equipment->owner?->id ?? 'club')
            ->map(function ($items) {
                $first = $items->first();
                $owner = $first->equipment->owner;

                return [
                    'owner_name' => $first->equipment->owner_name,
                    'callsign' => $owner?->call_sign ?? 'N/A',
                    'email' => $owner?->email ?? 'N/A',
                    'primary_phone' => $owner?->email ?? 'N/A',
                    'emergency_contacts' => $items
                        ->pluck('equipment.emergency_contact_phone')
                        ->filter()
                        ->unique()
                        ->values(),
                    'equipment_count' => $items->count(),
                    'equipment_list' => $items->map(fn ($c) => [
                        'type' => $c->equipment->type,
                        'description' => trim("{$c->equipment->make} {$c->equipment->model}") ?: $c->equipment->description,
                        'status' => $c->status,
                    ]),
                ];
            })
            ->values()
            ->sortBy('owner_name');

        return [
            'event' => $event,
            'contacts' => $contacts,
        ];
    }

    /**
     * Generate post-event return tracking checklist.
     *
     * Returns equipment needing return (status: delivered),
     * with checkboxes, owner signature lines, and special instructions.
     *
     * @param  int  $eventId  The event ID to generate return checklist for
     * @return array{
     *     event: Event,
     *     return_items: Collection,
     *     summary: array{total_items: int, by_owner: Collection}
     * }
     */
    public function generateReturnChecklist(int $eventId): array
    {
        $event = Event::findOrFail($eventId);

        $returnItems = EquipmentEvent::query()
            ->where('event_id', $eventId)
            ->whereIn('status', ['delivered'])
            ->with([
                'equipment.owner',
                'station',
            ])
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'checkbox' => '☐',
                'equipment_description' => trim("{$c->equipment->make} {$c->equipment->model}") ?: $c->equipment->description,
                'type' => $c->equipment->type,
                'serial_number' => $c->equipment->serial_number,
                'owner_name' => $c->equipment->owner_name,
                'owner_callsign' => $c->equipment->owner?->call_sign ?? 'N/A',
                'owner_contact' => $c->equipment->emergency_contact_phone ?? $c->equipment->owner?->email ?? 'N/A',
                'station' => $c->station?->name ?? 'Unassigned',
                'status' => $c->status,
                'special_instructions' => $c->equipment->notes,
                'condition_notes' => '',
                'signature_line' => '_______________________',
                'date_line' => '__________',
            ]);

        $byOwner = $returnItems->groupBy('owner_name')->map->count();

        return [
            'event' => $event,
            'return_items' => $returnItems,
            'summary' => [
                'total_items' => $returnItems->count(),
                'by_owner' => $byOwner,
            ],
        ];
    }

    /**
     * Generate lost/damaged equipment incident report.
     *
     * Returns equipment with lost or damaged status,
     * including manager notes, circumstances, owner contact, value, and timestamps.
     *
     * @param  int  $eventId  The event ID to generate incident report for
     * @return array{
     *     event: Event,
     *     incidents: Collection,
     *     summary: array{
     *         total_incidents: int,
     *         total_value_at_risk: string,
     *         by_status: Collection
     *     }
     * }
     */
    public function generateIncidentReport(int $eventId): array
    {
        $event = Event::findOrFail($eventId);

        $incidents = EquipmentEvent::query()
            ->where('event_id', $eventId)
            ->whereIn('status', ['lost', 'damaged', 'cancelled'])
            ->with([
                'equipment.owner',
                'statusChangedBy',
                'station',
            ])
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'equipment_description' => trim("{$c->equipment->make} {$c->equipment->model}") ?: $c->equipment->description,
                'type' => $c->equipment->type,
                'serial_number' => $c->equipment->serial_number,
                'status' => $c->status,
                'status_changed_at' => $c->status_changed_at?->format(self::DATETIME_FORMAT),
                'status_changed_by' => $c->statusChangedBy?->call_sign ?? 'Unknown',
                'owner_name' => $c->equipment->owner_name,
                'owner_callsign' => $c->equipment->owner?->call_sign ?? 'N/A',
                'owner_email' => $c->equipment->owner?->email ?? 'N/A',
                'owner_contact' => $c->equipment->emergency_contact_phone ?? 'N/A',
                'value_usd' => $c->equipment->value_usd,
                'station' => $c->station?->name ?? 'Unassigned',
                'manager_notes' => $c->manager_notes,
                'circumstances' => $c->manager_notes,
            ]);

        $totalValue = $incidents->sum('value_usd');
        $byStatus = $incidents->groupBy('status')->map->count();

        return [
            'event' => $event,
            'incidents' => $incidents,
            'summary' => [
                'total_incidents' => $incidents->count(),
                'total_value_at_risk' => number_format($totalValue, 2),
                'by_status' => $byStatus,
            ],
        ];
    }

    /**
     * Generate complete event equipment historical record.
     *
     * Returns all equipment with final status,
     * including performance notes and "what worked" summary.
     *
     * @param  int  $eventId  The event ID to generate historical record for
     * @return array{
     *     event: Event,
     *     equipment_records: Collection,
     *     summary: array{
     *         total_equipment: int,
     *         total_value: string,
     *         final_status_breakdown: Collection,
     *         success_rate: string
     *     }
     * }
     */
    public function generateHistoricalRecord(int $eventId): array
    {
        $event = Event::findOrFail($eventId);

        $equipmentRecords = EquipmentEvent::query()
            ->where('event_id', $eventId)
            ->with([
                'equipment.owner',
                'equipment.bands',
                'station',
                'statusChangedBy',
            ])
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'equipment_id' => $c->equipment_id,
                'type' => $c->equipment->type,
                'make' => $c->equipment->make,
                'model' => $c->equipment->model,
                'description' => $c->equipment->description,
                'owner_name' => $c->equipment->owner_name,
                'owner_callsign' => $c->equipment->owner?->call_sign ?? 'N/A',
                'committed_at' => $c->committed_at?->format(self::DATETIME_FORMAT),
                'expected_delivery_at' => $c->expected_delivery_at?->format(self::DATETIME_FORMAT),
                'final_status' => $c->status,
                'status_changed_at' => $c->status_changed_at?->format(self::DATETIME_FORMAT),
                'status_changed_by' => $c->statusChangedBy?->call_sign ?? 'N/A',
                'station_assigned' => $c->station?->name ?? 'Unassigned',
                'bands_supported' => $c->equipment->bands->pluck('name')->implode(', '),
                'power_watts' => $c->equipment->power_output_watts,
                'value_usd' => $c->equipment->value_usd,
                'delivery_notes' => $c->delivery_notes,
                'manager_notes' => $c->manager_notes,
                'equipment_notes' => $c->equipment->notes,
            ]);

        $totalValue = $equipmentRecords->sum('value_usd');
        $statusBreakdown = $equipmentRecords->groupBy('final_status')->map->count();

        // Calculate success rate (returned / total)
        $successfulItems = $equipmentRecords->whereIn('final_status', ['returned'])->count();
        $total = $equipmentRecords->count();
        $successRate = $total > 0 ? ($successfulItems / $total) * 100 : 0;

        return [
            'event' => $event,
            'equipment_records' => $equipmentRecords,
            'summary' => [
                'total_equipment' => $total,
                'total_value' => number_format($totalValue, 2),
                'final_status_breakdown' => $statusBreakdown,
                'success_rate' => number_format($successRate, 2).'%',
            ],
        ];
    }
}
