<?php

namespace App\Livewire\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Shared equipment commitment management for equipment list components.
 *
 * Provides commit-to-event, status changes, delivery notes, bulk commit,
 * and bulk selection. Each consuming component implements the abstract
 * methods to control authorization and ownership scoping.
 */
trait ManagesEquipmentCommitments
{
    // Commit modal
    public bool $showCommitModal = false;

    public ?int $commitEquipmentId = null;

    public ?int $commitEventId = null;

    public ?string $commitExpectedDeliveryAt = null;

    public ?string $commitDeliveryNotes = null;

    // Commitment details modal
    public bool $showDetailsModal = false;

    public ?EquipmentEvent $detailCommitment = null;

    // Notes modal
    public bool $showNotesModal = false;

    public ?int $updateNoteId = null;

    public ?string $tempNotes = null;

    /** @var array<int> */
    public array $selectedIds = [];

    // Bulk commit modal
    public bool $showBulkCommitModal = false;

    public ?int $bulkCommitEventId = null;

    public ?string $bulkCommitExpectedDeliveryAt = null;

    public ?string $bulkCommitDeliveryNotes = null;

    private const PERMISSION_ERROR = 'You do not have permission to modify this commitment.';

    /**
     * Authorize the current user for commit/status/notes actions.
     *
     * Throw an authorization exception if the user lacks permission.
     * For user-owned equipment this is a no-op (ownership is checked
     * via canManageCommitment instead). For club equipment this gates
     * on the edit-any-equipment permission.
     */
    abstract protected function authorizeCommitAction(): void;

    /**
     * Check whether the authenticated user can manage a specific commitment.
     *
     * For user-owned equipment: true when the equipment belongs to the user.
     * For club equipment: true when the equipment is org-owned and user
     * has the edit-any-equipment permission.
     */
    abstract protected function canManageCommitment(EquipmentEvent $commitment): bool;

    /**
     * Return a validation closure for the commitEquipmentId field.
     *
     * The closure receives ($attribute, $value, $fail) and should call
     * $fail() when the equipment does not belong to this list's scope.
     */
    abstract protected function commitEquipmentValidationRule(): Closure;

    /**
     * Scope a set of equipment IDs to those manageable in this context.
     *
     * For user equipment: filters to owner_user_id = auth()->id().
     * For club equipment: filters to owner_organization_id IS NOT NULL.
     */
    abstract protected function scopeSelectedEquipment(array $ids): EloquentCollection;

    /**
     * Get events within next 30 days or currently active.
     */
    #[Computed]
    public function upcomingEvents(): Collection
    {
        return Event::query()
            ->where(function (Builder $query) {
                $query->where('start_time', '<=', now()->addDays(30))
                    ->where('end_time', '>=', now());
            })
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Open modal to commit a specific equipment item to an event.
     */
    public function openCommitModal(int $equipmentId): void
    {
        $this->authorizeCommitAction();

        $this->commitEquipmentId = $equipmentId;
        $this->commitEventId = null;
        $this->commitExpectedDeliveryAt = null;
        $this->commitDeliveryNotes = null;
        $this->showCommitModal = true;
    }

    /**
     * Create a new EquipmentEvent commitment.
     */
    public function commitEquipment(): void
    {
        $this->authorizeCommitAction();

        $event = Event::find($this->commitEventId);

        $this->validate([
            'commitEquipmentId' => [
                'required',
                'exists:equipment,id',
                $this->commitEquipmentValidationRule(),
            ],
            'commitEventId' => [
                'required',
                'exists:events,id',
            ],
            'commitExpectedDeliveryAt' => array_filter([
                'nullable',
                'date',
                $event?->setup_allowed_from ? "after_or_equal:{$event->setup_allowed_from}" : null,
                $event ? "before_or_equal:{$event->end_time}" : null,
            ]),
            'commitDeliveryNotes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        if ($this->hasOverlappingCommitment($this->commitEquipmentId, $event)) {
            $this->addError('commitEquipmentId', 'This equipment is already committed to an overlapping event.');

            return;
        }

        EquipmentEvent::create([
            'equipment_id' => $this->commitEquipmentId,
            'event_id' => $this->commitEventId,
            'status' => 'committed',
            'committed_at' => now(),
            'expected_delivery_at' => $this->commitExpectedDeliveryAt ? Carbon::parse($this->commitExpectedDeliveryAt) : null,
            'delivery_notes' => $this->commitDeliveryNotes,
            'status_changed_at' => now(),
            'status_changed_by_user_id' => auth()->id(),
        ]);

        $this->dispatch('notify', title: 'Success', description: 'Equipment committed to event successfully.', type: 'success');

        $this->showCommitModal = false;
        $this->commitEquipmentId = null;
        $this->commitEventId = null;
        $this->commitExpectedDeliveryAt = null;
        $this->commitDeliveryNotes = null;

        unset($this->equipment);
    }

    /**
     * Open modal to view full commitment details.
     */
    public function openDetailsModal(int $commitmentId): void
    {
        $commitment = EquipmentEvent::with([
            'equipment.bands',
            'equipment.owner',
            'event',
            'station',
            'assignedBy',
            'statusChangedBy',
        ])->findOrFail($commitmentId);

        if (! $this->canManageCommitment($commitment)) {
            $this->dispatch('notify', title: 'Error', description: self::PERMISSION_ERROR, type: 'error');

            return;
        }

        $this->detailCommitment = $commitment;
        $this->showDetailsModal = true;
    }

    /**
     * Change equipment commitment status.
     */
    public function changeStatus(int $commitmentId, string $newStatus): void
    {
        $this->authorizeCommitAction();

        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        if (! $this->canManageCommitment($commitment)) {
            $this->dispatch('notify', title: 'Error', description: self::PERMISSION_ERROR, type: 'error');

            return;
        }

        if ($commitment->changeStatus($newStatus, auth()->user())) {
            $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));
            $this->dispatch('notify', title: 'Success', description: "Status changed to {$statusLabel}.", type: 'success');
            $this->showDetailsModal = false;
            unset($this->equipment);
        } else {
            $this->dispatch('notify', title: 'Error', description: 'Invalid status.', type: 'error');
        }
    }

    /**
     * Open modal to update delivery notes for a commitment.
     */
    public function openNotesModal(int $commitmentId): void
    {
        $this->authorizeCommitAction();

        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        if (! $this->canManageCommitment($commitment)) {
            $this->dispatch('notify', title: 'Error', description: self::PERMISSION_ERROR, type: 'error');

            return;
        }

        $this->updateNoteId = $commitmentId;
        $this->tempNotes = $commitment->delivery_notes;
        $this->showNotesModal = true;
    }

    /**
     * Update delivery notes for a commitment.
     */
    public function updateNotes(int $commitmentId, ?string $notes): void
    {
        $this->authorizeCommitAction();

        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        if (! $this->canManageCommitment($commitment)) {
            $this->dispatch('notify', title: 'Error', description: self::PERMISSION_ERROR, type: 'error');

            return;
        }

        $commitment->update([
            'delivery_notes' => $notes,
        ]);

        $this->dispatch('notify', title: 'Success', description: 'Notes updated successfully.', type: 'success');
        $this->showNotesModal = false;
        $this->updateNoteId = null;
        $this->tempNotes = null;
        unset($this->equipment);
    }

    /**
     * Select all equipment IDs on the current page.
     */
    public function selectAll(): void
    {
        $this->selectedIds = $this->equipment->pluck('id')->toArray();
    }

    /**
     * Clear the current selection.
     */
    public function deselectAll(): void
    {
        $this->selectedIds = [];
    }

    /**
     * Open the bulk commit modal and reset its fields.
     */
    public function openBulkCommitModal(): void
    {
        $this->authorizeCommitAction();

        $this->bulkCommitEventId = null;
        $this->bulkCommitExpectedDeliveryAt = null;
        $this->bulkCommitDeliveryNotes = null;
        $this->showBulkCommitModal = true;
    }

    /**
     * Bulk commit selected equipment items to an event.
     *
     * Aborts entirely if any item has an overlapping commitment.
     */
    public function bulkCommitEquipment(): void
    {
        $this->authorizeCommitAction();

        $event = Event::find($this->bulkCommitEventId);

        $this->validate([
            'bulkCommitEventId' => ['required', 'exists:events,id'],
            'bulkCommitExpectedDeliveryAt' => array_filter([
                'nullable',
                'date',
                $event?->setup_allowed_from ? "after_or_equal:{$event->setup_allowed_from}" : null,
                $event ? "before_or_equal:{$event->end_time}" : null,
            ]),
            'bulkCommitDeliveryNotes' => ['nullable', 'string', 'max:500'],
        ]);

        $equipmentItems = $this->scopeSelectedEquipment($this->selectedIds);

        $conflicting = [];

        foreach ($equipmentItems as $equipment) {
            if ($this->hasOverlappingCommitment($equipment->id, $event)) {
                $conflicting[] = trim("{$equipment->make} {$equipment->model}");
            }
        }

        if (! empty($conflicting)) {
            $names = implode(', ', $conflicting);
            $this->addError('bulkCommit', "The following items have overlapping commitments: {$names}.");

            return;
        }

        foreach ($equipmentItems as $equipment) {
            EquipmentEvent::create([
                'equipment_id' => $equipment->id,
                'event_id' => $this->bulkCommitEventId,
                'status' => 'committed',
                'committed_at' => now(),
                'expected_delivery_at' => $this->bulkCommitExpectedDeliveryAt ? Carbon::parse($this->bulkCommitExpectedDeliveryAt) : null,
                'delivery_notes' => $this->bulkCommitDeliveryNotes,
                'status_changed_at' => now(),
                'status_changed_by_user_id' => auth()->id(),
            ]);
        }

        $count = $equipmentItems->count();
        $this->dispatch('notify', title: 'Success', description: "{$count} item(s) committed to event successfully.", type: 'success');

        $this->showBulkCommitModal = false;
        $this->bulkCommitEventId = null;
        $this->bulkCommitExpectedDeliveryAt = null;
        $this->bulkCommitDeliveryNotes = null;
        $this->selectedIds = [];

        unset($this->equipment);
    }

    /**
     * Check if equipment has an overlapping commitment for the given event.
     */
    private function hasOverlappingCommitment(int $equipmentId, Event $event): bool
    {
        return EquipmentEvent::query()
            ->where('equipment_id', $equipmentId)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->whereHas('event', function (Builder $query) use ($event) {
                $query->where(function (Builder $q) use ($event) {
                    $q->whereBetween('start_time', [$event->start_time, $event->end_time])
                        ->orWhereBetween('end_time', [$event->start_time, $event->end_time])
                        ->orWhere(function (Builder $q2) use ($event) {
                            $q2->where('start_time', '<=', $event->start_time)
                                ->where('end_time', '>=', $event->end_time);
                        });
                });
            })
            ->exists();
    }
}
