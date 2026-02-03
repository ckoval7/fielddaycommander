<?php

namespace App\Livewire\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\Station;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Event Equipment Dashboard component for Event Managers.
 *
 * Provides comprehensive view of all equipment commitments for an event
 * with filtering, search, status management, and station assignment
 * capabilities.
 *
 * @property-read Collection $allCommitments All equipment commitments for the event
 * @property-read Collection $filteredCommitments Filtered equipment commitments
 * @property-read array $statsCards Stats cards data (committed, delivered, in_use, returned, issues)
 * @property-read Collection $equipmentByType Equipment grouped by type
 * @property-read Collection $recentActivity Recent status changes
 * @property-read Collection $commitmentsByOwner Equipment grouped by owner
 * @property-read Collection $commitmentsByStation Equipment grouped by station
 * @property-read Collection $availableStations Stations available for assignment
 * @property-read array $equipmentTypes Available equipment types for filtering
 * @property-read array $statusOptions Status options for filtering
 */
class EventEquipmentDashboard extends Component
{
    use AuthorizesRequests;

    /**
     * The event being managed.
     */
    public Event $event;

    /**
     * Current active tab.
     */
    public string $activeTab = 'overview';

    /**
     * Global search query.
     */
    public string $searchQuery = '';

    /**
     * Filter by equipment type.
     */
    public ?string $typeFilter = null;

    /**
     * Filter by commitment status.
     */
    public ?string $statusFilter = null;

    /**
     * Filter by station assignment.
     */
    public ?int $stationFilter = null;

    /**
     * Show status change modal.
     */
    public bool $showStatusModal = false;

    /**
     * Commitment ID for status change.
     */
    public ?int $statusChangeCommitmentId = null;

    /**
     * New status to set.
     */
    public string $newStatus = '';

    /**
     * Notes for status change.
     */
    public string $statusChangeNotes = '';

    /**
     * Show station assignment modal.
     */
    public bool $showAssignModal = false;

    /**
     * Commitment ID for station assignment.
     */
    public ?int $assignCommitmentId = null;

    /**
     * Station ID to assign.
     */
    public ?int $assignStationId = null;

    /**
     * Mount the component with the event.
     *
     * Loads the event and verifies user has appropriate permissions
     * (manage-event-equipment or view-all-equipment).
     */
    public function mount(Event $event): void
    {
        // User must have either manage-event-equipment or view-all-equipment permission
        if (! auth()->user()->can('manage-event-equipment') && ! auth()->user()->can('view-all-equipment')) {
            abort(403, 'Unauthorized action.');
        }

        $this->event = $event->load([
            'eventType',
            'eventConfiguration.section',
            'eventConfiguration.operatingClass',
        ]);
    }

    /**
     * Get all equipment commitments for this event with relationships.
     *
     * @return Collection<int, EquipmentEvent>
     */
    #[Computed]
    public function allCommitments(): Collection
    {
        return EquipmentEvent::query()
            ->forEvent($this->event->id)
            ->with([
                'equipment.owner',
                'equipment.owningOrganization',
                'station',
                'statusChangedBy',
                'assignedBy',
            ])
            ->orderBy('status_changed_at', 'desc')
            ->get();
    }

    /**
     * Get filtered equipment commitments based on search and filters.
     *
     * Searches across: equipment make, equipment model, owner name, owner callsign.
     *
     * @return Collection<int, EquipmentEvent>
     */
    #[Computed]
    public function filteredCommitments(): Collection
    {
        $commitments = $this->allCommitments;

        // Apply search filter
        if ($this->searchQuery !== '') {
            $search = strtolower($this->searchQuery);
            $commitments = $commitments->filter(function (EquipmentEvent $commitment) use ($search) {
                $equipment = $commitment->equipment;
                $owner = $equipment->owner;

                // Search in equipment make and model
                if (str_contains(strtolower($equipment->make ?? ''), $search)) {
                    return true;
                }
                if (str_contains(strtolower($equipment->model ?? ''), $search)) {
                    return true;
                }

                // Search in owner name and callsign
                if ($owner) {
                    $fullName = strtolower("{$owner->first_name} {$owner->last_name}");
                    if (str_contains($fullName, $search)) {
                        return true;
                    }
                    if (str_contains(strtolower($owner->call_sign ?? ''), $search)) {
                        return true;
                    }
                }

                // Search in organization name (for club equipment)
                if ($equipment->owningOrganization) {
                    if (str_contains(strtolower($equipment->owningOrganization->name ?? ''), $search)) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Apply type filter
        if ($this->typeFilter !== null && $this->typeFilter !== '') {
            $commitments = $commitments->filter(function (EquipmentEvent $commitment) {
                return $commitment->equipment->type === $this->typeFilter;
            });
        }

        // Apply status filter
        if ($this->statusFilter !== null && $this->statusFilter !== '') {
            $commitments = $commitments->filter(function (EquipmentEvent $commitment) {
                return $commitment->status === $this->statusFilter;
            });
        }

        // Apply station filter
        if ($this->stationFilter !== null) {
            $commitments = $commitments->filter(function (EquipmentEvent $commitment) {
                if ($this->stationFilter === 0) {
                    // Unassigned
                    return $commitment->station_id === null;
                }

                return $commitment->station_id === $this->stationFilter;
            });
        }

        return $commitments->values();
    }

    /**
     * Get stats cards data.
     *
     * Returns counts for committed, delivered, in_use, returned, and issues.
     *
     * @return array{
     *     committed: int,
     *     delivered: int,
     *     in_use: int,
     *     returned: int,
     *     issues: int,
     *     total: int
     * }
     */
    #[Computed]
    public function statsCards(): array
    {
        $commitments = $this->allCommitments;

        return [
            'committed' => $commitments->where('status', 'committed')->count(),
            'delivered' => $commitments->where('status', 'delivered')->count(),
            'in_use' => $commitments->where('status', 'in_use')->count(),
            'returned' => $commitments->where('status', 'returned')->count(),
            'issues' => $commitments->whereIn('status', ['cancelled', 'lost', 'damaged'])->count(),
            'total' => $commitments->count(),
        ];
    }

    /**
     * Get equipment grouped by type with counts.
     *
     * @return Collection<string, array{type: string, label: string, count: int, items: Collection}>
     */
    #[Computed]
    public function equipmentByType(): Collection
    {
        return $this->allCommitments
            ->groupBy(fn (EquipmentEvent $c) => $c->equipment->type)
            ->map(function (Collection $items, string $type) {
                return [
                    'type' => $type,
                    'label' => ucfirst(str_replace('_', ' ', $type)),
                    'count' => $items->count(),
                    'items' => $items,
                ];
            })
            ->sortByDesc('count')
            ->values();
    }

    /**
     * Get recent activity (last 20 status changes).
     *
     * @return Collection<int, EquipmentEvent>
     */
    #[Computed]
    public function recentActivity(): Collection
    {
        return $this->allCommitments
            ->sortByDesc('status_changed_at')
            ->take(20)
            ->values();
    }

    /**
     * Get commitments grouped by owner.
     *
     * @return Collection<int, array{owner_id: int|null, owner_name: string, callsign: string|null, is_club: bool, count: int, items: Collection}>
     */
    #[Computed]
    public function commitmentsByOwner(): Collection
    {
        return $this->allCommitments
            ->groupBy(function (EquipmentEvent $commitment) {
                $equipment = $commitment->equipment;
                if ($equipment->owner_organization_id) {
                    return 'org_'.$equipment->owner_organization_id;
                }

                return 'user_'.($equipment->owner_user_id ?? 'unknown');
            })
            ->map(function (Collection $items, string $key) {
                $firstEquipment = $items->first()->equipment;

                if (str_starts_with($key, 'org_')) {
                    return [
                        'owner_id' => $firstEquipment->owner_organization_id,
                        'owner_name' => $firstEquipment->owningOrganization->name ?? 'Club Equipment',
                        'callsign' => null,
                        'is_club' => true,
                        'count' => $items->count(),
                        'items' => $items,
                    ];
                }

                $owner = $firstEquipment->owner;

                return [
                    'owner_id' => $firstEquipment->owner_user_id,
                    'owner_name' => $owner
                        ? trim("{$owner->first_name} {$owner->last_name}")
                        : 'Unknown Owner',
                    'callsign' => $owner->call_sign ?? null,
                    'is_club' => false,
                    'count' => $items->count(),
                    'items' => $items,
                ];
            })
            ->sortByDesc('count')
            ->values();
    }

    /**
     * Get commitments grouped by station (including unassigned).
     *
     * @return Collection<int, array{station_id: int|null, station_name: string, is_gota: bool, count: int, items: Collection}>
     */
    #[Computed]
    public function commitmentsByStation(): Collection
    {
        $grouped = $this->allCommitments
            ->groupBy(fn (EquipmentEvent $c) => $c->station_id ?? 'unassigned')
            ->map(function (Collection $items, int|string $stationId) {
                if ($stationId === 'unassigned') {
                    return [
                        'station_id' => null,
                        'station_name' => 'Unassigned',
                        'is_gota' => false,
                        'count' => $items->count(),
                        'items' => $items,
                    ];
                }

                $station = $items->first()->station;

                return [
                    'station_id' => $station->id ?? null,
                    'station_name' => $station->name ?? 'Unknown Station',
                    'is_gota' => $station->is_gota ?? false,
                    'count' => $items->count(),
                    'items' => $items,
                ];
            })
            ->sortBy(function (array $group) {
                // Sort unassigned last, then by name
                if ($group['station_id'] === null) {
                    return 'zzz';
                }

                return $group['station_name'];
            });

        return $grouped->values();
    }

    /**
     * Get available stations for assignment.
     *
     * @return Collection<int, Station>
     */
    #[Computed]
    public function availableStations(): Collection
    {
        if (! $this->event->eventConfiguration) {
            return collect();
        }

        return Station::query()
            ->where('event_configuration_id', $this->event->eventConfiguration->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get unique equipment types for filtering.
     *
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function equipmentTypes(): array
    {
        $types = $this->allCommitments
            ->pluck('equipment.type')
            ->unique()
            ->filter()
            ->sort()
            ->values();

        return $types->map(fn (string $type) => [
            'id' => $type,
            'name' => ucfirst(str_replace('_', ' ', $type)),
        ])->toArray();
    }

    /**
     * Get available status options for filtering.
     *
     * @return array<int, array{id: string, name: string}>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return [
            ['id' => 'committed', 'name' => 'Committed'],
            ['id' => 'delivered', 'name' => 'Delivered'],
            ['id' => 'in_use', 'name' => 'In Use'],
            ['id' => 'returned', 'name' => 'Returned'],
            ['id' => 'cancelled', 'name' => 'Cancelled'],
            ['id' => 'lost', 'name' => 'Lost'],
            ['id' => 'damaged', 'name' => 'Damaged'],
        ];
    }

    /**
     * Check if the current user can manage equipment.
     *
     * Only users with manage-event-equipment can change status/assign stations.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('manage-event-equipment');
    }

    /**
     * Open the status change modal for a commitment.
     */
    public function openStatusModal(int $commitmentId): void
    {
        $this->statusChangeCommitmentId = $commitmentId;
        $this->newStatus = '';
        $this->statusChangeNotes = '';
        $this->showStatusModal = true;
    }

    /**
     * Change the status of an equipment commitment.
     *
     * Validates the transition, updates status tracking fields, and appends notes.
     *
     * @param  int  $commitmentId  The ID of the EquipmentEvent record
     * @param  string  $newStatus  The new status to transition to
     * @param  string|null  $notes  Optional notes to append
     */
    public function changeEquipmentStatus(int $commitmentId, string $newStatus, ?string $notes = null): void
    {
        // Authorize - must have manage-event-equipment permission
        if (! auth()->user()->can('manage-event-equipment')) {
            $this->dispatch('notify', title: 'Error', description: 'You do not have permission to manage event equipment.', type: 'error');

            return;
        }

        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        // Validate this commitment belongs to this event
        if ($commitment->event_id !== $this->event->id) {
            $this->dispatch('notify', title: 'Error', description: 'This equipment is not committed to this event.', type: 'error');

            return;
        }

        // Attempt status change
        if ($commitment->changeStatus($newStatus, auth()->user(), $notes)) {
            $this->dispatch('notify', title: 'Success', description: 'Equipment status updated successfully.', type: 'success');

            // Clear cached computed properties
            unset($this->allCommitments);
            unset($this->filteredCommitments);
            unset($this->statsCards);
            unset($this->equipmentByType);
            unset($this->recentActivity);
            unset($this->commitmentsByOwner);
            unset($this->commitmentsByStation);

            // Close modal
            $this->showStatusModal = false;
            $this->statusChangeCommitmentId = null;
            $this->newStatus = '';
            $this->statusChangeNotes = '';
        } else {
            $this->dispatch('notify', title: 'Error', description: 'Invalid status transition.', type: 'error');
        }
    }

    /**
     * Confirm and execute status change from modal.
     */
    public function confirmStatusChange(): void
    {
        if ($this->statusChangeCommitmentId && $this->newStatus) {
            $this->changeEquipmentStatus(
                $this->statusChangeCommitmentId,
                $this->newStatus,
                $this->statusChangeNotes !== '' ? $this->statusChangeNotes : null
            );
        }
    }

    /**
     * Open the station assignment modal for a commitment.
     */
    public function openAssignModal(int $commitmentId): void
    {
        $this->assignCommitmentId = $commitmentId;
        $this->assignStationId = null;
        $this->showAssignModal = true;
    }

    /**
     * Assign equipment to a station.
     *
     * This will also change the status to 'in_use' if currently 'delivered'.
     *
     * @param  int  $commitmentId  The ID of the EquipmentEvent record
     * @param  int  $stationId  The ID of the station to assign
     */
    public function assignToStation(int $commitmentId, int $stationId): void
    {
        // Authorize - must have manage-event-equipment permission
        if (! auth()->user()->can('manage-event-equipment')) {
            $this->dispatch('notify', title: 'Error', description: 'You do not have permission to manage event equipment.', type: 'error');

            return;
        }

        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        // Validate this commitment belongs to this event
        if ($commitment->event_id !== $this->event->id) {
            $this->dispatch('notify', title: 'Error', description: 'This equipment is not committed to this event.', type: 'error');

            return;
        }

        // Validate station belongs to this event's configuration
        $station = Station::findOrFail($stationId);
        if ($station->event_configuration_id !== $this->event->eventConfiguration?->id) {
            $this->dispatch('notify', title: 'Error', description: 'This station does not belong to this event.', type: 'error');

            return;
        }

        // Attempt assignment (includes status change to in_use)
        if ($commitment->assignToStation($stationId, auth()->user())) {
            $this->dispatch('notify', title: 'Success', description: "Equipment assigned to {$station->name}.", type: 'success');

            // Clear cached computed properties
            unset($this->allCommitments);
            unset($this->filteredCommitments);
            unset($this->statsCards);
            unset($this->equipmentByType);
            unset($this->recentActivity);
            unset($this->commitmentsByOwner);
            unset($this->commitmentsByStation);

            // Close modal
            $this->showAssignModal = false;
            $this->assignCommitmentId = null;
            $this->assignStationId = null;
        } else {
            $this->dispatch('notify', title: 'Error', description: 'Unable to assign equipment. Equipment must be delivered first.', type: 'error');
        }
    }

    /**
     * Confirm and execute station assignment from modal.
     */
    public function confirmAssignment(): void
    {
        if ($this->assignCommitmentId && $this->assignStationId) {
            $this->assignToStation($this->assignCommitmentId, $this->assignStationId);
        }
    }

    /**
     * Remove equipment from station assignment.
     *
     * This will remove the station_id but keep the status as is.
     *
     * @param  int  $commitmentId  The ID of the EquipmentEvent record
     */
    public function unassignFromStation(int $commitmentId): void
    {
        // Authorize - must have manage-event-equipment permission
        if (! auth()->user()->can('manage-event-equipment')) {
            $this->dispatch('notify', title: 'Error', description: 'You do not have permission to manage event equipment.', type: 'error');

            return;
        }

        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        // Validate this commitment belongs to this event
        if ($commitment->event_id !== $this->event->id) {
            $this->dispatch('notify', title: 'Error', description: 'This equipment is not committed to this event.', type: 'error');

            return;
        }

        // Remove station assignment
        $commitment->station_id = null;
        $commitment->assigned_by_user_id = null;
        $commitment->save();

        $this->dispatch('notify', title: 'Success', description: 'Equipment unassigned from station.', type: 'success');

        // Clear cached computed properties
        unset($this->allCommitments);
        unset($this->filteredCommitments);
        unset($this->statsCards);
        unset($this->equipmentByType);
        unset($this->recentActivity);
        unset($this->commitmentsByOwner);
        unset($this->commitmentsByStation);
    }

    /**
     * Clear all filters.
     */
    public function clearFilters(): void
    {
        $this->searchQuery = '';
        $this->typeFilter = null;
        $this->statusFilter = null;
        $this->stationFilter = null;

        // Clear filtered cache
        unset($this->filteredCommitments);
    }

    /**
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.equipment.event-equipment-dashboard');
    }
}
