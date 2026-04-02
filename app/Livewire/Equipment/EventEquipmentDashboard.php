<?php

namespace App\Livewire\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
 * @property-read array $statsCards Stats cards data (committed, delivered, returned, issues)
 * @property-read Collection $equipmentByType Equipment grouped by type
 * @property-read Collection $recentActivity Recent status changes
 * @property-read Collection $commitmentsByOwner Equipment grouped by owner
 * @property-read Collection $commitmentsByStation Equipment grouped by station
 * @property-read array $equipmentTypes Available equipment types for filtering
 * @property-read array $statusOptions Status options for filtering
 */
class EventEquipmentDashboard extends Component
{
    use AuthorizesRequests;

    private const PERMISSION_ERROR = 'You do not have permission to manage event equipment.';

    private const NOT_COMMITTED_ERROR = 'This equipment is not committed to this event.';

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
     * Show commit club equipment modal.
     */
    public bool $showCommitModal = false;

    /**
     * Selected club equipment ID for commitment.
     */
    public ?int $commitEquipmentId = null;

    /**
     * Expected delivery date for commitment.
     */
    public ?string $commitExpectedDeliveryAt = null;

    /**
     * Delivery notes for commitment.
     */
    public ?string $commitDeliveryNotes = null;

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
                'equipment.manager',
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

        if ($this->searchQuery !== '') {
            $commitments = $this->applySearchFilter($commitments);
        }

        if ($this->typeFilter !== null && $this->typeFilter !== '') {
            $commitments = $commitments->filter(fn (EquipmentEvent $c) => $c->equipment->type === $this->typeFilter);
        }

        if ($this->statusFilter !== null && $this->statusFilter !== '') {
            $commitments = $commitments->filter(fn (EquipmentEvent $c) => $c->status === $this->statusFilter);
        }

        if ($this->stationFilter !== null) {
            $commitments = $commitments->filter(fn (EquipmentEvent $c) => $this->stationFilter === 0
                ? $c->station_id === null
                : $c->station_id === $this->stationFilter);
        }

        return $commitments->values();
    }

    /**
     * Apply search filter across equipment make, model, owner name/callsign, and organization.
     *
     * @param  Collection<int, EquipmentEvent>  $commitments
     * @return Collection<int, EquipmentEvent>
     */
    protected function applySearchFilter(Collection $commitments): Collection
    {
        $search = strtolower($this->searchQuery);

        return $commitments->filter(fn (EquipmentEvent $commitment) => $this->commitmentMatchesSearch($commitment, $search));
    }

    /**
     * Check if a commitment matches the search query.
     */
    protected function commitmentMatchesSearch(EquipmentEvent $commitment, string $search): bool
    {
        $equipment = $commitment->equipment;
        $owner = $equipment->owner;

        return str_contains(strtolower($equipment->make ?? ''), $search)
            || str_contains(strtolower($equipment->model ?? ''), $search)
            || ($owner && str_contains(strtolower("{$owner->first_name} {$owner->last_name}"), $search))
            || ($owner && str_contains(strtolower($owner->call_sign ?? ''), $search))
            || ($equipment->owningOrganization && str_contains(strtolower($equipment->owningOrganization->name ?? ''), $search));
    }

    /**
     * Get stats cards data.
     *
     * Returns counts for committed, delivered, returned, and issues.
     *
     * @return array{
     *     committed: int,
     *     delivered: int,
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
            ['id' => 'returned', 'name' => 'Returned'],
            ['id' => 'cancelled', 'name' => 'Cancelled'],
            ['id' => 'lost', 'name' => 'Lost'],
            ['id' => 'damaged', 'name' => 'Damaged'],
        ];
    }

    /**
     * Map of equipment_id => Station for primary radios in this event.
     *
     * Used to show station assignment for radios that are tracked via
     * stations.radio_equipment_id rather than equipment_event.station_id.
     *
     * @return Collection<int, Station>
     */
    #[Computed]
    public function primaryRadioStations(): Collection
    {
        if (! $this->event->eventConfiguration) {
            return collect();
        }

        return Station::query()
            ->where('event_configuration_id', $this->event->eventConfiguration->id)
            ->whereNotNull('radio_equipment_id')
            ->get()
            ->keyBy('radio_equipment_id');
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
            $this->dispatch('notify', title: 'Error', description: self::PERMISSION_ERROR, type: 'error');

            return;
        }

        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        // Validate this commitment belongs to this event
        if ($commitment->event_id !== $this->event->id) {
            $this->dispatch('notify', title: 'Error', description: self::NOT_COMMITTED_ERROR, type: 'error');

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
     * Get club equipment available to commit (not already committed to this event).
     *
     * @return Collection<int, Equipment>
     */
    #[Computed]
    public function availableClubEquipment(): Collection
    {
        $alreadyCommittedIds = $this->allCommitments
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->pluck('equipment_id')
            ->toArray();

        return Equipment::query()
            ->whereNotNull('owner_organization_id')
            ->whereNotIn('id', $alreadyCommittedIds)
            ->orderBy('make')
            ->orderBy('model')
            ->get();
    }

    /**
     * Open the commit club equipment modal.
     */
    public function openCommitModal(): void
    {
        $this->commitEquipmentId = null;
        $this->commitExpectedDeliveryAt = null;
        $this->commitDeliveryNotes = null;
        $this->showCommitModal = true;

        unset($this->availableClubEquipment);
    }

    /**
     * Commit club equipment to this event.
     */
    public function commitClubEquipment(): void
    {
        if (! auth()->user()->can('manage-event-equipment')) {
            $this->dispatch('notify', title: 'Error', description: self::PERMISSION_ERROR, type: 'error');

            return;
        }

        $this->validate([
            'commitEquipmentId' => [
                'required',
                'exists:equipment,id',
                function ($attribute, $value, $fail) {
                    $equipment = Equipment::find($value);
                    if (! $equipment || ! $equipment->is_club_equipment) {
                        $fail('Only club equipment can be committed from the dashboard.');
                    }
                },
            ],
            'commitExpectedDeliveryAt' => [
                'nullable',
                'date',
            ],
            'commitDeliveryNotes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        // Check for overlapping commitments
        $hasOverlap = EquipmentEvent::query()
            ->where('equipment_id', $this->commitEquipmentId)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->whereHas('event', function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->whereBetween('start_time', [$this->event->start_time, $this->event->end_time])
                        ->orWhereBetween('end_time', [$this->event->start_time, $this->event->end_time])
                        ->orWhere(function (Builder $q2) {
                            $q2->where('start_time', '<=', $this->event->start_time)
                                ->where('end_time', '>=', $this->event->end_time);
                        });
                });
            })
            ->exists();

        if ($hasOverlap) {
            $this->addError('commitEquipmentId', 'This equipment is already committed to an overlapping event.');

            return;
        }

        EquipmentEvent::updateOrCreate(
            [
                'equipment_id' => $this->commitEquipmentId,
                'event_id' => $this->event->id,
            ],
            [
                'status' => 'committed',
                'committed_at' => now(),
                'expected_delivery_at' => $this->commitExpectedDeliveryAt ? Carbon::parse($this->commitExpectedDeliveryAt) : null,
                'delivery_notes' => $this->commitDeliveryNotes,
                'station_id' => null,
                'assigned_by_user_id' => null,
                'manager_notes' => null,
                'status_changed_at' => now(),
                'status_changed_by_user_id' => auth()->id(),
            ]
        );

        $this->dispatch('notify', title: 'Success', description: 'Club equipment committed to event.', type: 'success');

        // Close modal and reset
        $this->showCommitModal = false;
        $this->commitEquipmentId = null;
        $this->commitExpectedDeliveryAt = null;
        $this->commitDeliveryNotes = null;

        // Clear cached computed properties
        unset($this->allCommitments);
        unset($this->filteredCommitments);
        unset($this->statsCards);
        unset($this->equipmentByType);
        unset($this->recentActivity);
        unset($this->commitmentsByOwner);
        unset($this->commitmentsByStation);
        unset($this->availableClubEquipment);
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
        return view('livewire.equipment.event-equipment-dashboard')->layout('layouts.app');
    }
}
