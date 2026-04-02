<?php

namespace App\Livewire\Stations;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use App\Models\OperatingSession;
use App\Models\Station;
use App\Models\User;
use App\Notifications\Equipment\EquipmentReassigned;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Equipment Assignment component for assigning equipment to stations.
 *
 * Provides a two-column interface with assigned equipment on the left
 * and available equipment on the right, supporting drag & drop and
 * button-based assignment workflows.
 *
 * @property-read Collection $assignedEquipmentByType Assigned equipment grouped by type
 * @property-read Collection $eventCommittedEquipment Equipment committed to event but not assigned
 * @property-read Collection $catalogEquipment All available equipment not yet committed
 * @property-read Station|null $stationModel The station being managed
 * @property-read Event|null $eventModel The event for this station
 * @property-read int $assignedCount Total count of assigned equipment
 * @property-read float $assignedTotalValue Total value of assigned equipment
 */
class EquipmentAssignment extends Component
{
    use AuthorizesRequests;

    /**
     * The station ID being managed.
     */
    public ?int $stationId = null;

    /**
     * Active tab in the available equipment section.
     */
    public string $availableTab = 'committed';

    /**
     * Search query for filtering equipment.
     */
    public string $searchQuery = '';

    /**
     * Equipment type filter.
     */
    public ?string $typeFilter = null;

    /**
     * Owner filter: 'all', 'my', 'club', or a specific user ID.
     */
    public string $ownerFilter = 'all';

    /**
     * Band compatibility filter (for radios/antennas).
     */
    public ?int $bandFilter = null;

    /**
     * Show conflict resolution modal.
     */
    public bool $showConflictModal = false;

    /**
     * Equipment ID for conflict resolution.
     */
    public ?int $conflictEquipmentId = null;

    /**
     * Conflicting station data for modal display.
     */
    public ?array $conflictData = null;

    /**
     * Show equipment details modal.
     */
    public bool $showDetailsModal = false;

    /**
     * Equipment ID for details modal.
     */
    public ?int $detailsEquipmentId = null;

    /**
     * Show warning confirmation modal (band/power warnings).
     */
    public bool $showWarningModal = false;

    /**
     * Warning messages to display in the modal.
     *
     * @var array<int, array{title: string, message: string}>
     */
    public array $warningMessages = [];

    /**
     * Pending equipment assignment ID (waiting for warning confirmation).
     */
    public ?int $pendingEquipmentId = null;

    /**
     * Whether pending assignment is from catalog.
     */
    public bool $pendingFromCatalog = false;

    /**
     * Show unassign confirmation modal.
     */
    public bool $showUnassignConfirmModal = false;

    /**
     * Equipment ID for unassign confirmation.
     */
    public ?int $unassignEquipmentId = null;

    /**
     * Equipment types for filtering.
     *
     * @var array<int, array{id: string, name: string, icon: string}>
     */
    /**
     * Equipment types for filtering - sourced from Equipment::TYPES.
     *
     * @return array<int, array{id: string, name: string, icon: string}>
     */
    protected function equipmentTypes(): array
    {
        return Equipment::typeFilters();
    }

    /**
     * Mount the component with a station.
     */
    public function mount(int $stationId): void
    {
        $this->stationId = $stationId;

        // Verify the station exists and user has permission
        Station::with('eventConfiguration.event')->findOrFail($stationId);

        // User must have manage-stations permission to assign equipment
        if (! auth()->user()->can('manage-stations')) {
            abort(403, 'You do not have permission to manage station equipment.');
        }

        // Set default owner filter based on permissions
        $this->ownerFilter = auth()->user()->can('view-all-equipment') ? 'all' : 'my';
    }

    /**
     * Get the station model.
     */
    #[Computed]
    public function stationModel(): ?Station
    {
        if (! $this->stationId) {
            return null;
        }

        return Station::with([
            'primaryRadio.owner',
            'primaryRadio.owningOrganization',
            'primaryRadio.bands',
            'eventConfiguration.event',
        ])->find($this->stationId);
    }

    /**
     * Get the event model for this station.
     */
    #[Computed]
    public function eventModel(): ?Event
    {
        return $this->stationModel?->eventConfiguration?->event;
    }

    /**
     * Get equipment currently assigned to this station, grouped by type.
     *
     * @return Collection<string, Collection>
     */
    #[Computed]
    public function assignedEquipmentByType(): Collection
    {
        if (! $this->stationId || ! $this->eventModel) {
            return collect();
        }

        $commitments = EquipmentEvent::query()
            ->where('station_id', $this->stationId)
            ->where('event_id', $this->eventModel->id)
            ->whereIn('status', ['committed', 'delivered'])
            ->with([
                'equipment.owner',
                'equipment.owningOrganization',
                'equipment.bands',
                'assignedBy',
            ])
            ->get();

        // Group by equipment type in display order
        $typeOrder = Equipment::typeKeys();
        $grouped = collect();

        foreach ($typeOrder as $type) {
            $items = $commitments->filter(
                fn (EquipmentEvent $c) => $c->equipment->type === $type
            );

            if ($items->isNotEmpty()) {
                $grouped[$type] = $items;
            }
        }

        return $grouped;
    }

    /**
     * Get equipment committed to this event but not assigned to any station.
     *
     * @return Collection<int, EquipmentEvent>
     */
    #[Computed]
    public function eventCommittedEquipment(): Collection
    {
        if (! $this->eventModel) {
            return collect();
        }

        $query = EquipmentEvent::query()
            ->where('event_id', $this->eventModel->id)
            ->whereNull('station_id')
            ->whereIn('status', ['committed', 'delivered'])
            ->with([
                'equipment.owner',
                'equipment.owningOrganization',
                'equipment.bands',
            ]);

        return $this->applyFilters($query)->get();
    }

    /**
     * Get all available equipment from the catalog (not committed to this event).
     *
     * @return Collection<int, Equipment>
     */
    #[Computed]
    public function catalogEquipment(): Collection
    {
        if (! $this->eventModel) {
            return collect();
        }

        $query = Equipment::query()
            ->with(['owner', 'owningOrganization', 'bands'])
            ->whereDoesntHave('commitments', function ($q) {
                $q->where('event_id', $this->eventModel->id)
                    ->whereIn('status', ['committed', 'delivered']);
            })
            ->availableForEvent($this->eventModel->id);

        return $this->applyEquipmentFilters($query)->take(50)->get();
    }

    /**
     * Check if the station has an active operating session.
     */
    #[Computed]
    public function hasActiveSession(): bool
    {
        if (! $this->stationId) {
            return false;
        }

        return OperatingSession::query()
            ->where('station_id', $this->stationId)
            ->whereNull('end_time')
            ->exists();
    }

    /**
     * Check if station has committed (not yet delivered) equipment during an active session.
     */
    #[Computed]
    public function hasCommittedEquipmentDuringSession(): bool
    {
        if (! $this->hasActiveSession || ! $this->eventModel) {
            return false;
        }

        return EquipmentEvent::query()
            ->where('station_id', $this->stationId)
            ->where('event_id', $this->eventModel->id)
            ->where('status', 'committed')
            ->exists();
    }

    /**
     * Get the total count of assigned equipment.
     */
    #[Computed]
    public function assignedCount(): int
    {
        return $this->assignedEquipmentByType->flatten()->count();
    }

    /**
     * Get the total value of assigned equipment.
     */
    #[Computed]
    public function assignedTotalValue(): float
    {
        return $this->assignedEquipmentByType
            ->flatten()
            ->sum(fn (EquipmentEvent $c) => (float) ($c->equipment->value_usd ?? 0));
    }

    /**
     * Get available equipment types for the filter dropdown.
     *
     * @return array<int, array{id: string, name: string, icon: string}>
     */
    #[Computed]
    public function availableTypes(): array
    {
        return $this->equipmentTypes();
    }

    /**
     * Check if user can manage equipment assignments.
     */
    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('manage-stations');
    }

    // Validation Methods

    /**
     * Check if equipment is already assigned to another station for this event.
     *
     * Returns detailed information about any assignment conflicts including:
     * - Whether the equipment is conflicted
     * - Current station details
     * - Assignment metadata (user, timestamp)
     * - Whether reassignment is allowed (always true)
     *
     * @param  int  $equipmentId  The equipment to check
     * @param  int  $stationId  The target station ID
     * @return array{
     *   is_conflicted: bool,
     *   current_station_name: string|null,
     *   current_station_id: int|null,
     *   assigned_by_user: \App\Models\User|null,
     *   assigned_at: \Illuminate\Support\Carbon|null,
     *   can_reassign: bool,
     *   conflict_message: string
     * }
     */
    public function checkEquipmentConflict(int $equipmentId, int $stationId): array
    {
        $existingCommitment = EquipmentEvent::query()
            ->where('equipment_id', $equipmentId)
            ->where('event_id', $this->eventModel->id)
            ->whereNotNull('station_id')
            ->where('station_id', '!=', $stationId)
            ->whereIn('status', ['committed', 'delivered'])
            ->with(['station', 'assignedBy'])
            ->first();

        if (! $existingCommitment) {
            return [
                'is_conflicted' => false,
                'current_station_name' => null,
                'current_station_id' => null,
                'assigned_by_user' => null,
                'assigned_at' => null,
                'can_reassign' => true,
                'conflict_message' => '',
            ];
        }

        $statusLabel = ucfirst($existingCommitment->status);
        $conflictMessage = "This equipment is currently assigned to {$existingCommitment->station->name} ({$statusLabel}). You can reassign it to this station.";

        return [
            'is_conflicted' => true,
            'current_station_name' => $existingCommitment->station->name,
            'current_station_id' => $existingCommitment->station_id,
            'assigned_by_user' => $existingCommitment->assignedBy,
            'assigned_at' => $existingCommitment->updated_at,
            'can_reassign' => true,
            'conflict_message' => $conflictMessage,
        ];
    }

    /**
     * Validate that equipment bands are compatible with station's primary radio.
     *
     * For antennas: Checks if antenna bands overlap with primary radio bands.
     * For radios: Not applicable (radios are the primary, not additional equipment).
     * For other equipment: Always compatible (no band restrictions).
     *
     * @param  int  $equipmentId  The equipment to validate
     * @param  int  $stationId  The target station ID
     * @return array{compatible: bool, warning_message: string|null}
     */
    public function validateBandCompatibility(int $equipmentId, int $stationId): array
    {
        $equipment = Equipment::with('bands')->findOrFail($equipmentId);
        $station = Station::with('primaryRadio.bands')->findOrFail($stationId);

        // Only validate band compatibility for antennas
        if ($equipment->type !== 'antenna') {
            return ['compatible' => true, 'warning_message' => null];
        }

        return $this->checkAntennaBandCompatibility($equipment, $station);
    }

    /**
     * Check antenna band compatibility with station's primary radio.
     *
     * @return array{compatible: bool, warning_message: string|null}
     */
    protected function checkAntennaBandCompatibility(Equipment $equipment, Station $station): array
    {
        if (! $station->primaryRadio) {
            return ['compatible' => true, 'warning_message' => 'No primary radio assigned to station. Band compatibility cannot be verified.'];
        }

        $equipmentBands = $equipment->bands->pluck('id');
        $radioBands = $station->primaryRadio->bands->pluck('id');

        if ($equipmentBands->isEmpty() || $radioBands->isEmpty()) {
            return ['compatible' => true, 'warning_message' => 'Band information is incomplete. Please verify compatibility manually.'];
        }

        if ($equipmentBands->intersect($radioBands)->isEmpty()) {
            $equipmentBandNames = $equipment->bands->pluck('name')->join(', ');
            $radioBandNames = $station->primaryRadio->bands->pluck('name')->join(', ');

            return ['compatible' => false, 'warning_message' => "Antenna bands ({$equipmentBandNames}) may not be compatible with radio bands ({$radioBandNames})."];
        }

        return ['compatible' => true, 'warning_message' => null];
    }

    /**
     * Validate that adding equipment won't exceed power limits.
     *
     * Checks two limits:
     * 1. Station max_power_watts (if set)
     * 2. Event operating class max_power_watts (from event configuration)
     *
     * NOTE: For amplifiers, the amplifier power_output_watts IS the total power
     * (not additive with radio power). The amplifier replaces/overrides the radio power.
     *
     * @param  int  $equipmentId  The equipment to validate
     * @param  int  $stationId  The target station ID
     * @return array{within_limits: bool, warning_message: string|null, calculated_power: int}
     */
    public function validatePowerLimits(int $equipmentId, int $stationId): array
    {
        $equipment = Equipment::findOrFail($equipmentId);
        $station = Station::with([
            'primaryRadio',
            'eventConfiguration.operatingClass',
        ])->findOrFail($stationId);

        // Only validate power for amplifiers
        if ($equipment->type !== 'amplifier') {
            return [
                'within_limits' => true,
                'warning_message' => null,
                'calculated_power' => 0,
            ];
        }

        // Get amplifier power
        $amplifierPower = $equipment->power_output_watts ?? 0;

        // If no power specified, can't validate
        if ($amplifierPower === 0) {
            return [
                'within_limits' => true,
                'warning_message' => 'Amplifier power output not specified. Please verify power limits manually.',
                'calculated_power' => 0,
            ];
        }

        $warnings = [];

        // Check station power limit
        if ($station->max_power_watts && $amplifierPower > $station->max_power_watts) {
            $warnings[] = "Amplifier output ({$amplifierPower}W) exceeds station limit ({$station->max_power_watts}W).";
        }

        // Check event operating class power limit
        $operatingClassLimit = $station->eventConfiguration?->operatingClass?->max_power_watts;
        if ($operatingClassLimit && $amplifierPower > $operatingClassLimit) {
            $className = $station->eventConfiguration->operatingClass->name ?? 'operating class';
            $warnings[] = "Amplifier output ({$amplifierPower}W) exceeds {$className} limit ({$operatingClassLimit}W).";
        }

        if (! empty($warnings)) {
            return [
                'within_limits' => false,
                'warning_message' => implode(' ', $warnings),
                'calculated_power' => $amplifierPower,
            ];
        }

        return [
            'within_limits' => true,
            'warning_message' => null,
            'calculated_power' => $amplifierPower,
        ];
    }

    /**
     * Validate that equipment type is appropriate for the assignment context.
     *
     * Prevents:
     * - Radios from being assigned as additional equipment (must be primary radio)
     * - Invalid equipment types
     *
     * @param  int  $equipmentId  The equipment to validate
     * @param  string  $assignmentType  The assignment context ('assignment', 'primary', etc.)
     * @return array{valid: bool, error_message: string|null}
     */
    public function validateEquipmentType(int $equipmentId, string $assignmentType): array
    {
        $equipment = Equipment::findOrFail($equipmentId);

        // Radios cannot be assigned as additional equipment
        if ($equipment->type === 'radio' && $assignmentType === 'assignment') {
            return [
                'valid' => false,
                'error_message' => 'Radios must be selected as the primary radio in the station form, not as additional equipment.',
            ];
        }

        // Validate equipment type is in allowed list
        if (! in_array($equipment->type, Equipment::typeKeys(), true)) {
            return [
                'valid' => false,
                'error_message' => "Invalid equipment type: {$equipment->type}",
            ];
        }

        return [
            'valid' => true,
            'error_message' => null,
        ];
    }

    /**
     * Apply filters to EquipmentEvent query.
     */
    private function applyFilters($query)
    {
        // Search filter
        if ($this->searchQuery !== '') {
            $search = $this->searchQuery;
            $query->whereHas('equipment', function ($q) use ($search) {
                $q->where('make', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Type filter
        if ($this->typeFilter) {
            $query->whereHas('equipment', function ($q) {
                $q->where('type', $this->typeFilter);
            });
        }

        // Owner filter
        if ($this->ownerFilter === 'my') {
            $query->whereHas('equipment', function ($q) {
                $q->where('owner_user_id', auth()->id());
            });
        } elseif ($this->ownerFilter === 'club') {
            $query->whereHas('equipment', function ($q) {
                $q->whereNotNull('owner_organization_id');
            });
        }

        // Band filter
        if ($this->bandFilter) {
            $query->whereHas('equipment.bands', function ($q) {
                $q->where('bands.id', $this->bandFilter);
            });
        }

        return $query;
    }

    /**
     * Apply filters to Equipment query (for catalog tab).
     */
    private function applyEquipmentFilters($query)
    {
        // Search filter
        if ($this->searchQuery !== '') {
            $search = $this->searchQuery;
            $query->where(function ($q) use ($search) {
                $q->where('make', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Type filter - exclude radios (they go in primary radio selection)
        if ($this->typeFilter) {
            $query->where('type', $this->typeFilter);
        } else {
            // By default, exclude radios from the catalog since primary radio is selected elsewhere
            $query->where('type', '!=', 'radio');
        }

        // Owner filter
        if ($this->ownerFilter === 'my') {
            $query->where('owner_user_id', auth()->id());
        } elseif ($this->ownerFilter === 'club') {
            $query->whereNotNull('owner_organization_id');
        }

        // Band filter
        if ($this->bandFilter) {
            $query->whereHas('bands', function ($q) {
                $q->where('bands.id', $this->bandFilter);
            });
        }

        return $query;
    }

    /**
     * Assign equipment to this station.
     *
     * @param  int  $equipmentId  The equipment ID to assign
     * @param  bool  $fromCatalog  Whether this is from the catalog (needs commitment created)
     */
    public function assignEquipment(int $equipmentId, bool $fromCatalog = false): void
    {
        if (! $this->canManage) {
            $this->dispatch('toast', [
                'title' => 'Error',
                'description' => 'You do not have permission to assign equipment.',
                'icon' => 'o-x-circle',
                'css' => 'alert-error',
            ]);

            return;
        }

        $equipment = Equipment::with('commitments')->findOrFail($equipmentId);

        // Run comprehensive validation checks
        $typeValidation = $this->validateEquipmentType($equipmentId, 'assignment');
        if (! $typeValidation['valid']) {
            $this->dispatch('toast', [
                'title' => 'Cannot Assign Equipment',
                'description' => $typeValidation['error_message'],
                'icon' => 'o-exclamation-triangle',
                'css' => 'alert-warning',
            ]);

            return;
        }

        // Check for equipment conflicts
        $conflictCheck = $this->checkEquipmentConflict($equipmentId, $this->stationId);
        if ($conflictCheck['is_conflicted']) {
            if (! $conflictCheck['can_reassign']) {
                $this->dispatch('toast', [
                    'title' => 'Equipment In Use',
                    'description' => $conflictCheck['conflict_message'],
                    'icon' => 'o-exclamation-circle',
                    'css' => 'alert-error',
                ]);

                return;
            }

            // Show conflict modal for reassignment
            $this->conflictEquipmentId = $equipmentId;
            $this->conflictData = [
                'equipment_make' => $equipment->make,
                'equipment_model' => $equipment->model,
                'equipment_type' => $equipment->type,
                'current_station_name' => $conflictCheck['current_station_name'],
                'current_station_id' => $conflictCheck['current_station_id'],
                'assigned_by' => $conflictCheck['assigned_by_user']?->call_sign
                    ?? $conflictCheck['assigned_by_user']?->first_name
                    ?? 'Unknown',
                'assigned_at' => $conflictCheck['assigned_at']?->format('M d, Y H:i'),
                'from_catalog' => $fromCatalog,
            ];
            $this->showConflictModal = true;

            return;
        }

        // Collect warnings for band compatibility and power limits
        $warnings = [];

        $bandCheck = $this->validateBandCompatibility($equipmentId, $this->stationId);
        if (! $bandCheck['compatible'] && $bandCheck['warning_message']) {
            $warnings[] = [
                'title' => 'Band Compatibility',
                'message' => $bandCheck['warning_message'],
            ];
        }

        $powerCheck = $this->validatePowerLimits($equipmentId, $this->stationId);
        if (! $powerCheck['within_limits'] && $powerCheck['warning_message']) {
            $warnings[] = [
                'title' => 'Power Limit',
                'message' => $powerCheck['warning_message'],
            ];
        }

        // If there are warnings, show confirmation modal instead of proceeding
        if (! empty($warnings)) {
            $this->warningMessages = $warnings;
            $this->pendingEquipmentId = $equipmentId;
            $this->pendingFromCatalog = $fromCatalog;
            $this->showWarningModal = true;

            return;
        }

        // No warnings — perform the assignment directly
        $this->performAssignment($equipmentId, $fromCatalog);
    }

    /**
     * Perform the actual equipment assignment.
     */
    private function performAssignment(int $equipmentId, bool $fromCatalog): void
    {
        $equipment = Equipment::findOrFail($equipmentId);

        if ($fromCatalog) {
            // Create new equipment_event record
            EquipmentEvent::create([
                'equipment_id' => $equipmentId,
                'event_id' => $this->eventModel->id,
                'station_id' => $this->stationId,
                'assigned_by_user_id' => auth()->id(),
                'status' => 'committed',
                'committed_at' => now(),
                'status_changed_at' => now(),
                'status_changed_by_user_id' => auth()->id(),
            ]);
        } else {
            // Update existing equipment_event record
            EquipmentEvent::query()
                ->where('equipment_id', $equipmentId)
                ->where('event_id', $this->eventModel->id)
                ->update([
                    'station_id' => $this->stationId,
                    'assigned_by_user_id' => auth()->id(),
                ]);
        }

        // Clear computed caches
        $this->clearCaches();

        $this->dispatch('toast', [
            'title' => 'Equipment Assigned',
            'description' => "{$equipment->make} {$equipment->model} assigned to this station.",
            'icon' => 'o-check-circle',
            'css' => 'alert-success',
        ]);
    }

    /**
     * Confirm conflict resolution and reassign equipment.
     */
    public function confirmReassignment(): void
    {
        if (! $this->conflictEquipmentId || ! $this->conflictData) {
            return;
        }

        $equipmentId = $this->conflictEquipmentId;
        $previousStationId = $this->conflictData['current_station_id'];

        // Get the equipment and old station before updating
        $equipment = Equipment::find($equipmentId);
        $previousStation = Station::find($previousStationId);
        $newStation = $this->stationModel;

        // Get the previous assignment to find who assigned it
        $previousAssignment = EquipmentEvent::query()
            ->where('equipment_id', $equipmentId)
            ->where('event_id', $this->eventModel->id)
            ->first();

        // Update the existing commitment to this station
        EquipmentEvent::query()
            ->where('equipment_id', $equipmentId)
            ->where('event_id', $this->eventModel->id)
            ->update([
                'station_id' => $this->stationId,
                'assigned_by_user_id' => auth()->id(),
            ]);

        // Clear computed caches
        $this->clearCaches();

        // Send notification to the previous assignment user if different from current user
        if ($previousAssignment && $previousAssignment->assigned_by_user_id && $previousAssignment->assigned_by_user_id !== auth()->id()) {
            $previousAssignedByUser = User::find($previousAssignment->assigned_by_user_id);

            if ($previousAssignedByUser) {
                $previousAssignedByUser->notify(new EquipmentReassigned(
                    $equipment,
                    $previousStation,
                    $newStation,
                    auth()->user(),
                ));
            }
        }

        // Dispatch notification event for the previous station
        $this->dispatch('equipment-reassigned', [
            'equipment_id' => $equipmentId,
            'from_station_id' => $previousStationId,
            'to_station_id' => $this->stationId,
        ]);

        $this->showConflictModal = false;
        $this->conflictEquipmentId = null;
        $this->conflictData = null;

        $this->dispatch('toast', [
            'title' => 'Equipment Reassigned',
            'description' => "{$equipment->make} {$equipment->model} reassigned to this station.",
            'icon' => 'o-check-circle',
            'css' => 'alert-success',
        ]);
    }

    /**
     * Cancel conflict resolution.
     */
    public function cancelConflict(): void
    {
        $this->showConflictModal = false;
        $this->conflictEquipmentId = null;
        $this->conflictData = null;
    }

    /**
     * Confirm assignment despite warnings.
     */
    public function confirmWarningAssignment(): void
    {
        if (! $this->pendingEquipmentId) {
            return;
        }

        $equipmentId = $this->pendingEquipmentId;
        $fromCatalog = $this->pendingFromCatalog;

        $this->showWarningModal = false;
        $this->warningMessages = [];
        $this->pendingEquipmentId = null;
        $this->pendingFromCatalog = false;

        $this->performAssignment($equipmentId, $fromCatalog);
    }

    /**
     * Cancel assignment due to warnings.
     */
    public function cancelWarningAssignment(): void
    {
        $this->showWarningModal = false;
        $this->warningMessages = [];
        $this->pendingEquipmentId = null;
        $this->pendingFromCatalog = false;
    }

    /**
     * Request unassign of equipment from this station.
     */
    public function requestUnassign(int $equipmentId): void
    {
        if (! $this->canManage) {
            return;
        }

        // Warn if station has an active operating session
        if ($this->hasActiveSession) {
            $this->unassignEquipmentId = $equipmentId;
            $this->showUnassignConfirmModal = true;

            return;
        }

        $this->unassignEquipment($equipmentId);
    }

    /**
     * Unassign equipment from this station.
     */
    public function unassignEquipment(int $equipmentId): void
    {
        if (! $this->canManage) {
            $this->dispatch('toast', [
                'title' => 'Error',
                'description' => 'You do not have permission to unassign equipment.',
                'icon' => 'o-x-circle',
                'css' => 'alert-error',
            ]);

            return;
        }

        $equipment = Equipment::find($equipmentId);

        // Update the equipment_event record - remove station assignment but keep committed
        EquipmentEvent::query()
            ->where('equipment_id', $equipmentId)
            ->where('event_id', $this->eventModel->id)
            ->where('station_id', $this->stationId)
            ->update([
                'station_id' => null,
                'assigned_by_user_id' => null,
            ]);

        $this->showUnassignConfirmModal = false;
        $this->unassignEquipmentId = null;

        // Clear computed caches
        $this->clearCaches();

        $this->dispatch('toast', [
            'title' => 'Equipment Unassigned',
            'description' => "{$equipment->make} {$equipment->model} removed from this station.",
            'icon' => 'o-check-circle',
            'css' => 'alert-success',
        ]);
    }

    /**
     * Cancel unassign confirmation.
     */
    public function cancelUnassign(): void
    {
        $this->showUnassignConfirmModal = false;
        $this->unassignEquipmentId = null;
    }

    /**
     * Show equipment details modal.
     */
    public function showDetails(int $equipmentId): void
    {
        $this->detailsEquipmentId = $equipmentId;
        $this->showDetailsModal = true;
    }

    /**
     * Close equipment details modal.
     */
    public function closeDetails(): void
    {
        $this->showDetailsModal = false;
        $this->detailsEquipmentId = null;
    }

    /**
     * Get equipment details for the modal.
     */
    #[Computed]
    public function detailsEquipment(): ?Equipment
    {
        if (! $this->detailsEquipmentId) {
            return null;
        }

        return Equipment::with(['owner', 'owningOrganization', 'bands', 'manager'])
            ->find($this->detailsEquipmentId);
    }

    /**
     * Handle drop event from drag & drop.
     */
    #[On('equipment-dropped')]
    public function handleDrop(int $equipmentId, bool $fromCatalog = false): void
    {
        $this->assignEquipment($equipmentId, $fromCatalog);
    }

    /**
     * Clear all filters.
     */
    public function clearFilters(): void
    {
        $this->searchQuery = '';
        $this->typeFilter = null;
        $this->ownerFilter = auth()->user()->can('view-all-equipment') ? 'all' : 'my';
        $this->bandFilter = null;

        $this->clearCaches();
    }

    /**
     * Clear computed caches.
     */
    private function clearCaches(): void
    {
        unset($this->assignedEquipmentByType);
        unset($this->eventCommittedEquipment);
        unset($this->catalogEquipment);
        unset($this->assignedCount);
        unset($this->assignedTotalValue);
        unset($this->hasActiveSession);
        unset($this->hasCommittedEquipmentDuringSession);
    }

    /**
     * Get the icon for an equipment type.
     */
    public function getTypeIcon(string $type): string
    {
        return match ($type) {
            'radio' => 'o-radio',
            'antenna' => 'o-signal',
            'amplifier' => 'o-bolt',
            'computer' => 'o-computer-desktop',
            'accessory' => 'o-wrench-screwdriver',
            default => 'o-cube',
        };
    }

    /**
     * Get the display name for an equipment type.
     */
    public function getTypeName(string $type): string
    {
        return match ($type) {
            'radio' => 'Radios',
            'antenna' => 'Antennas',
            'amplifier' => 'Amplifiers',
            'computer' => 'Computers',
            'accessory' => 'Accessories',
            default => 'Other Equipment',
        };
    }

    /**
     * Get owner display name for equipment.
     */
    public function getOwnerDisplay(Equipment $equipment): string
    {
        if ($equipment->owner_organization_id) {
            return $equipment->owningOrganization?->name ?? 'Club';
        }

        return $equipment->owner?->call_sign ?? $equipment->owner_name ?? 'Unknown';
    }

    /**
     * Get status badge class for commitment status.
     */
    public function getStatusBadgeClass(string $status): string
    {
        return match ($status) {
            'committed' => 'badge-info',
            'delivered' => 'badge-warning',
            default => 'badge-neutral',
        };
    }

    /**
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.stations.equipment-assignment')->layout('layouts.app');
    }
}
