<?php

namespace App\Services;

use App\Exceptions\StationCloneException;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\EventConfiguration;
use App\Models\Station;
use App\Models\User;
use App\Notifications\Equipment\EquipmentCommitted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service class for cloning stations between events.
 *
 * Handles station cloning with optional equipment assignment copying,
 * conflict detection, and transaction management.
 */
class StationCloneService
{
    /**
     * Clone stations from one event to another.
     *
     * @param  int  $sourceEventId  The source event configuration ID
     * @param  int  $targetEventId  The target event configuration ID
     * @param  array<int>  $stationIds  Array of station IDs to clone
     * @param  array{
     *     copy_equipment?: bool,
     *     name_suffix?: string|null,
     *     skip_conflicts?: bool
     * }  $options  Cloning options
     * @return array{
     *     success: bool,
     *     stations_cloned: int,
     *     equipment_assigned: int,
     *     equipment_skipped: int,
     *     conflicts: array<array{equipment_id: int, equipment_type: string, make_model: string, reason: string, station_name: string}>,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     cloned_station_ids: array<int>
     * }
     */
    public function cloneStations(
        int $sourceEventId,
        int $targetEventId,
        array $stationIds,
        array $options = []
    ): array {
        $result = [
            'success' => false,
            'stations_cloned' => 0,
            'equipment_assigned' => 0,
            'equipment_skipped' => 0,
            'conflicts' => [],
            'errors' => [],
            'warnings' => [],
            'cloned_station_ids' => [],
        ];

        // Set default options
        $copyEquipment = $options['copy_equipment'] ?? false;
        $nameSuffix = $options['name_suffix'] ?? null;
        $skipConflicts = $options['skip_conflicts'] ?? true;

        // Validate inputs
        $validationErrors = $this->validateInputs(
            $sourceEventId,
            $targetEventId,
            $stationIds
        );

        if (! empty($validationErrors)) {
            $result['errors'] = $validationErrors;

            return $result;
        }

        // Check user permission
        $user = Auth::user();
        if (! $user || ! $user->can('manage-stations')) {
            $result['errors'][] = 'Permission denied: You do not have permission to manage stations.';
            Log::warning('Station clone attempted without manage-stations permission', [
                'user_id' => $user?->id,
                'source_event_id' => $sourceEventId,
                'target_event_id' => $targetEventId,
            ]);

            return $result;
        }

        try {
            $context = [
                'sourceEventId' => $sourceEventId,
                'targetEventId' => $targetEventId,
                'stationIds' => $stationIds,
                'copyEquipment' => $copyEquipment,
                'nameSuffix' => $nameSuffix,
                'skipConflicts' => $skipConflicts,
                'user' => $user,
            ];

            DB::transaction(function () use ($context, &$result) {
                $this->executeCloneTransaction($context, $result);
            });
        } catch (StationCloneException $e) {
            $result['errors'][] = $e->getMessage();
            Log::warning('Station clone failed with runtime exception', [
                'error' => $e->getMessage(),
                'source_event_id' => $sourceEventId,
                'target_event_id' => $targetEventId,
            ]);
        } catch (\Exception $e) {
            $result['errors'][] = 'An unexpected error occurred while cloning stations.';
            Log::error('Station clone failed with unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'source_event_id' => $sourceEventId,
                'target_event_id' => $targetEventId,
            ]);
        }

        return $result;
    }

    /**
     * Execute the station cloning within a database transaction.
     *
     * @param  array{sourceEventId: int, targetEventId: int, stationIds: array, copyEquipment: bool, nameSuffix: ?string, skipConflicts: bool, user: \App\Models\User}  $context
     * @param  array<string, mixed>  $result  Result array (passed by reference)
     */
    protected function executeCloneTransaction(array $context, array &$result): void
    {
        $sourceEventId = $context['sourceEventId'];
        $targetEventId = $context['targetEventId'];
        $stationIds = $context['stationIds'];
        $copyEquipment = $context['copyEquipment'];
        $nameSuffix = $context['nameSuffix'];
        $skipConflicts = $context['skipConflicts'];
        $user = $context['user'];

        $sourceStations = Station::query()
            ->whereIn('id', $stationIds)
            ->where('event_configuration_id', $sourceEventId)
            ->with(['additionalEquipment', 'primaryRadio'])
            ->get();

        if ($sourceStations->isEmpty()) {
            throw new StationCloneException('No valid stations found to clone.');
        }

        $targetHasGota = Station::query()
            ->where('event_configuration_id', $targetEventId)
            ->where('is_gota', true)
            ->exists();

        $targetEventConfig = EventConfiguration::findOrFail($targetEventId);
        $targetEvent = $targetEventConfig->event;

        foreach ($sourceStations as $sourceStation) {
            $clonedStation = $this->cloneStation(
                $sourceStation,
                $targetEventId,
                $nameSuffix,
                $targetHasGota,
                $result
            );

            if (! $clonedStation) {
                continue;
            }

            $result['stations_cloned']++;
            $result['cloned_station_ids'][] = $clonedStation->id;

            if ($sourceStation->is_gota && ! $targetHasGota) {
                $targetHasGota = true;
            }

            if ($copyEquipment) {
                $this->cloneEquipmentAssignments(
                    $sourceStation,
                    $clonedStation,
                    $targetEvent,
                    $user,
                    $skipConflicts,
                    $result
                );
            }
        }

        if (! $skipConflicts && ! empty($result['conflicts'])) {
            throw new StationCloneException(
                'Equipment conflicts detected and skip_conflicts is disabled.'
            );
        }

        $result['success'] = true;

        Log::info('Stations cloned successfully', [
            'user_id' => $user->id,
            'source_event_id' => $sourceEventId,
            'target_event_id' => $targetEventId,
            'stations_cloned' => $result['stations_cloned'],
            'equipment_assigned' => $result['equipment_assigned'],
            'equipment_skipped' => $result['equipment_skipped'],
        ]);
    }

    /**
     * Validate input parameters for station cloning.
     *
     * @param  int  $sourceEventId  The source event configuration ID
     * @param  int  $targetEventId  The target event configuration ID
     * @param  array<int>  $stationIds  Array of station IDs to validate
     * @return array<string> Array of validation error messages
     */
    protected function validateInputs(
        int $sourceEventId,
        int $targetEventId,
        array $stationIds
    ): array {
        $errors = [];

        // Check source event exists
        $sourceEvent = EventConfiguration::find($sourceEventId);
        if (! $sourceEvent) {
            $errors[] = 'Source event configuration not found.';

            return $errors;
        }

        // Check source event has stations
        $sourceStationCount = Station::where('event_configuration_id', $sourceEventId)->count();
        if ($sourceStationCount === 0) {
            $errors[] = 'Source event has no stations to clone.';
        }

        // Check target event exists
        $targetEvent = EventConfiguration::find($targetEventId);
        if (! $targetEvent) {
            $errors[] = 'Target event configuration not found.';

            return $errors;
        }

        // Ensure source and target are different
        if ($sourceEventId === $targetEventId) {
            $errors[] = 'Source and target events must be different.';
        }

        // Validate station IDs
        if (empty($stationIds)) {
            $errors[] = 'No station IDs provided for cloning.';
        } else {
            // Verify all station IDs exist in source event
            $validStationCount = Station::query()
                ->whereIn('id', $stationIds)
                ->where('event_configuration_id', $sourceEventId)
                ->count();

            if ($validStationCount !== count($stationIds)) {
                $errors[] = 'One or more station IDs do not exist in the source event.';
            }
        }

        return $errors;
    }

    /**
     * Clone a single station to the target event.
     *
     * @param  Station  $sourceStation  The station to clone
     * @param  int  $targetEventId  The target event configuration ID
     * @param  string|null  $nameSuffix  Optional suffix to append to station name
     * @param  bool  $targetHasGota  Whether target event already has a GOTA station
     * @param  array  $result  Reference to result array for adding warnings
     * @return Station|null The cloned station or null on failure
     */
    protected function cloneStation(
        Station $sourceStation,
        int $targetEventId,
        ?string $nameSuffix,
        bool $targetHasGota,
        array &$result
    ): ?Station {
        try {
            // Build the new station name
            $newName = $sourceStation->name;
            if ($nameSuffix) {
                $newName .= ' '.$nameSuffix;
            }

            // Handle GOTA conflict
            $isGota = $sourceStation->is_gota;
            if ($isGota && $targetHasGota) {
                $isGota = false;
                $result['warnings'][] = sprintf(
                    'Station "%s" was a GOTA station, but target event already has a GOTA station. Created as non-GOTA.',
                    $sourceStation->name
                );
            }

            // Create the cloned station
            $clonedStation = Station::create([
                'event_configuration_id' => $targetEventId,
                'name' => $newName,
                'radio_equipment_id' => $sourceStation->radio_equipment_id,
                'is_gota' => $isGota,
                'is_vhf_only' => $sourceStation->is_vhf_only,
                'is_satellite' => $sourceStation->is_satellite,
                'max_power_watts' => $sourceStation->max_power_watts,
                'power_source_description' => $sourceStation->power_source_description,
            ]);

            Log::debug('Station cloned', [
                'source_station_id' => $sourceStation->id,
                'cloned_station_id' => $clonedStation->id,
                'target_event_id' => $targetEventId,
            ]);

            return $clonedStation;
        } catch (\Exception $e) {
            Log::error('Failed to clone station', [
                'source_station_id' => $sourceStation->id,
                'target_event_id' => $targetEventId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Clone equipment assignments from source station to cloned station.
     *
     * @param  Station  $sourceStation  The original station with equipment
     * @param  Station  $clonedStation  The newly cloned station
     * @param  Event  $targetEvent  The target event (for availability checks)
     * @param  User  $user  The user performing the clone
     * @param  bool  $skipConflicts  Whether to skip conflicting equipment
     * @param  array  $result  Reference to result array for updating counts
     */
    protected function cloneEquipmentAssignments(
        Station $sourceStation,
        Station $clonedStation,
        Event $targetEvent,
        User $user,
        bool $skipConflicts,
        array &$result
    ): void {
        // Get equipment assigned to the source station
        $sourceEquipmentEvents = EquipmentEvent::query()
            ->where('station_id', $sourceStation->id)
            ->whereIn('status', ['committed', 'delivered', 'in_use'])
            ->with(['equipment.owner', 'equipment.owningOrganization'])
            ->get();

        foreach ($sourceEquipmentEvents as $sourceEquipmentEvent) {
            $equipment = $sourceEquipmentEvent->equipment;

            if (! $equipment) {
                continue;
            }

            // Check equipment availability
            $availability = $this->checkEquipmentAvailability(
                $equipment->id,
                $targetEvent->id
            );

            if ($availability['available']) {
                // Create new equipment assignment
                $newEquipmentEvent = $this->createEquipmentAssignment(
                    $equipment,
                    $targetEvent->id,
                    $clonedStation->id,
                    $user
                );

                if ($newEquipmentEvent) {
                    $result['equipment_assigned']++;

                    // Send notification to equipment owner
                    $this->sendEquipmentCommittedNotification(
                        $newEquipmentEvent,
                        $user
                    );
                }
            } else {
                // Equipment is not available
                $makeModel = trim("{$equipment->make} {$equipment->model}");
                if (empty($makeModel)) {
                    $makeModel = ucfirst($equipment->type);
                }

                $conflict = [
                    'equipment_id' => $equipment->id,
                    'equipment_type' => $equipment->type,
                    'make_model' => $makeModel,
                    'reason' => $availability['reason'],
                    'station_name' => $sourceStation->name,
                ];

                $result['conflicts'][] = $conflict;
                $result['equipment_skipped']++;

                Log::info('Equipment skipped due to conflict', [
                    'equipment_id' => $equipment->id,
                    'target_event_id' => $targetEvent->id,
                    'reason' => $availability['reason'],
                ]);

                if (! $skipConflicts) {
                    throw new StationCloneException(sprintf(
                        'Equipment "%s" is not available: %s',
                        $makeModel,
                        $availability['reason']
                    ));
                }
            }
        }
    }

    /**
     * Check if equipment is available for assignment to a target event.
     *
     * @param  int  $equipmentId  The equipment ID to check
     * @param  int  $targetEventId  The target event ID
     * @return array{available: bool, reason: string|null}
     */
    protected function checkEquipmentAvailability(int $equipmentId, int $targetEventId): array
    {
        // Check if equipment exists and is not deleted
        $equipment = Equipment::withTrashed()->find($equipmentId);

        if (! $equipment) {
            return [
                'available' => false,
                'reason' => 'Equipment not found.',
            ];
        }

        if ($equipment->trashed()) {
            return [
                'available' => false,
                'reason' => 'Equipment has been deleted.',
            ];
        }

        // Check if already committed to the target event
        $existingCommitment = EquipmentEvent::query()
            ->where('equipment_id', $equipmentId)
            ->where('event_id', $targetEventId)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->exists();

        if ($existingCommitment) {
            return [
                'available' => false,
                'reason' => 'Equipment is already committed to the target event.',
            ];
        }

        // Check if equipment is in use at another overlapping event
        $targetEvent = Event::find($targetEventId);

        if (! $targetEvent) {
            return [
                'available' => false,
                'reason' => 'Target event not found.',
            ];
        }

        $overlappingCommitment = EquipmentEvent::query()
            ->where('equipment_id', $equipmentId)
            ->where('event_id', '!=', $targetEventId)
            ->whereIn('status', ['committed', 'delivered', 'in_use'])
            ->whereHas('event', function ($query) use ($targetEvent) {
                $query->where(function ($q) use ($targetEvent) {
                    // Event starts during target event
                    $q->whereBetween('start_time', [$targetEvent->start_time, $targetEvent->end_time])
                        // Event ends during target event
                        ->orWhereBetween('end_time', [$targetEvent->start_time, $targetEvent->end_time])
                        // Event completely encompasses target event
                        ->orWhere(function ($encompassQuery) use ($targetEvent) {
                            $encompassQuery->where('start_time', '<=', $targetEvent->start_time)
                                ->where('end_time', '>=', $targetEvent->end_time);
                        });
                });
            })
            ->with('event')
            ->first();

        if ($overlappingCommitment) {
            $conflictingEventName = $overlappingCommitment->event?->name ?? 'another event';

            return [
                'available' => false,
                'reason' => "Equipment is committed to overlapping event: {$conflictingEventName}.",
            ];
        }

        return [
            'available' => true,
            'reason' => null,
        ];
    }

    /**
     * Detect all equipment conflicts for a source station against target event.
     *
     * @param  Station  $sourceStation  The source station to check
     * @param  int  $targetEventId  The target event ID
     * @return array<array{equipment_id: int, equipment_type: string, make_model: string, reason: string}>
     */
    public function detectEquipmentConflicts(Station $sourceStation, int $targetEventId): array
    {
        $conflicts = [];

        $equipmentEvents = EquipmentEvent::query()
            ->where('station_id', $sourceStation->id)
            ->whereIn('status', ['committed', 'delivered', 'in_use'])
            ->with('equipment')
            ->get();

        $targetEventConfig = EventConfiguration::find($targetEventId);
        $targetEvent = $targetEventConfig?->event;

        if (! $targetEvent) {
            return [[
                'equipment_id' => 0,
                'make_model' => 'N/A',
                'reason' => 'Target event not found.',
            ]];
        }

        foreach ($equipmentEvents as $equipmentEvent) {
            $equipment = $equipmentEvent->equipment;

            if (! $equipment) {
                continue;
            }

            $availability = $this->checkEquipmentAvailability(
                $equipment->id,
                $targetEvent->id
            );

            if (! $availability['available']) {
                $makeModel = trim("{$equipment->make} {$equipment->model}");
                if (empty($makeModel)) {
                    $makeModel = ucfirst($equipment->type);
                }

                $conflicts[] = [
                    'equipment_id' => $equipment->id,
                    'equipment_type' => $equipment->type,
                    'make_model' => $makeModel,
                    'reason' => $availability['reason'],
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Create a new equipment event assignment.
     *
     * @param  Equipment  $equipment  The equipment to assign
     * @param  int  $eventId  The event ID
     * @param  int  $stationId  The station ID
     * @param  User  $user  The user making the assignment
     * @return EquipmentEvent|null The created assignment or null on failure
     */
    protected function createEquipmentAssignment(
        Equipment $equipment,
        int $eventId,
        int $stationId,
        User $user
    ): ?EquipmentEvent {
        try {
            $equipmentEvent = EquipmentEvent::create([
                'equipment_id' => $equipment->id,
                'event_id' => $eventId,
                'station_id' => $stationId,
                'status' => 'committed',
                'committed_at' => now(),
                'assigned_by_user_id' => $user->id,
                'status_changed_at' => now(),
                'status_changed_by_user_id' => $user->id,
            ]);

            Log::debug('Equipment assignment created', [
                'equipment_id' => $equipment->id,
                'event_id' => $eventId,
                'station_id' => $stationId,
                'equipment_event_id' => $equipmentEvent->id,
            ]);

            return $equipmentEvent;
        } catch (\Exception $e) {
            Log::error('Failed to create equipment assignment', [
                'equipment_id' => $equipment->id,
                'event_id' => $eventId,
                'station_id' => $stationId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send equipment committed notification to the equipment owner.
     *
     * @param  EquipmentEvent  $equipmentEvent  The equipment event record
     * @param  User  $user  The user who performed the clone
     */
    protected function sendEquipmentCommittedNotification(
        EquipmentEvent $equipmentEvent,
        User $user
    ): void {
        // Load equipment with owner relationship
        $equipmentEvent->load('equipment.owner');
        $equipment = $equipmentEvent->equipment;

        if (! $equipment) {
            return;
        }

        // Only notify the equipment owner (not the user doing the clone)
        $owner = $equipment->owner;

        if ($owner && $owner->id !== $user->id) {
            try {
                $owner->notify(new EquipmentCommitted($equipmentEvent, 'operator'));

                Log::debug('Equipment committed notification sent', [
                    'equipment_event_id' => $equipmentEvent->id,
                    'owner_id' => $owner->id,
                ]);
            } catch (\Exception $e) {
                // Log but don't fail the clone operation
                Log::warning('Failed to send equipment committed notification', [
                    'equipment_event_id' => $equipmentEvent->id,
                    'owner_id' => $owner->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Preview what would happen when cloning stations.
     *
     * This method does NOT create any records - it only returns
     * information about what would be cloned and any conflicts.
     *
     * @param  int  $sourceEventId  The source event configuration ID
     * @param  int  $targetEventId  The target event configuration ID
     * @param  array<int>  $stationIds  Array of station IDs to preview
     * @param  bool  $includeEquipment  Whether to include equipment analysis
     * @return array{
     *     valid: bool,
     *     stations: array<array{id: int, name: string, is_gota: bool, equipment_count: int}>,
     *     conflicts: array<array{equipment_id: int, equipment_type: string, make_model: string, reason: string, station_name: string}>,
     *     warnings: array<string>,
     *     errors: array<string>
     * }
     */
    public function previewClone(
        int $sourceEventId,
        int $targetEventId,
        array $stationIds,
        bool $includeEquipment = true
    ): array {
        $preview = [
            'valid' => false,
            'stations' => [],
            'conflicts' => [],
            'warnings' => [],
            'errors' => [],
        ];

        // Validate inputs
        $validationErrors = $this->validateInputs($sourceEventId, $targetEventId, $stationIds);

        if (! empty($validationErrors)) {
            $preview['errors'] = $validationErrors;

            return $preview;
        }

        // Load stations
        $stations = Station::query()
            ->whereIn('id', $stationIds)
            ->where('event_configuration_id', $sourceEventId)
            ->with(['additionalEquipment'])
            ->get();

        // Check for GOTA conflict
        $targetHasGota = Station::query()
            ->where('event_configuration_id', $targetEventId)
            ->where('is_gota', true)
            ->exists();

        $sourceHasGota = $stations->where('is_gota', true)->isNotEmpty();

        if ($targetHasGota && $sourceHasGota) {
            $preview['warnings'][] = 'Target event already has a GOTA station. Cloned GOTA stations will be converted to non-GOTA.';
        }

        // Get target event for availability checks
        $targetEventConfig = EventConfiguration::find($targetEventId);
        $targetEvent = $targetEventConfig?->event;

        foreach ($stations as $station) {
            $stationPreview = [
                'id' => $station->id,
                'name' => $station->name,
                'is_gota' => $station->is_gota,
                'equipment_count' => $station->additionalEquipment->count(),
            ];

            $preview['stations'][] = $stationPreview;

            // Check equipment conflicts if requested
            if ($includeEquipment && $targetEvent) {
                $stationConflicts = $this->detectEquipmentConflicts($station, $targetEventId);
                foreach ($stationConflicts as $conflict) {
                    $conflict['station_name'] = $station->name;
                    $preview['conflicts'][] = $conflict;
                }
            }
        }

        $preview['valid'] = true;

        return $preview;
    }
}
