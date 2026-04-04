<?php

namespace App\Livewire\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class EquipmentList extends Component
{
    use AuthorizesRequests, WithPagination;

    public string $search = '';

    public ?string $typeFilter = null;

    public ?string $statusFilter = null;

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public bool $showPhotoModal = false;

    public ?string $photoPath = null;

    public ?string $photoDescription = null;

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
     * Mount the component and authorize the user.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Equipment::class);
    }

    /**
     * Reset to page 1 when search query changes.
     */
    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Reset to page 1 when type filter changes.
     */
    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Reset to page 1 when status filter changes.
     */
    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Get the filtered and sorted equipment query.
     *
     * Returns only the authenticated user's equipment, filtered by search query,
     * type, and status, then sorted by the specified column.
     */
    #[Computed]
    public function equipment()
    {
        return Equipment::query()
            ->with(['manager', 'commitments.event'])
            ->where('owner_user_id', auth()->id())
            ->when($this->search, fn (Builder $query) => $query->search($this->search))
            ->when($this->typeFilter, fn (Builder $query) => $query->ofType($this->typeFilter))
            ->when($this->statusFilter, fn (Builder $query) => $query->withCommitmentStatus($this->statusFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->orderBy('id', $this->sortDirection)
            ->paginate(25);
    }

    /**
     * Open modal to view full-size equipment photo.
     *
     * @param  string  $photoPath  The storage path of the photo
     * @param  string  $description  Equipment description
     */
    public function viewPhoto(string $photoPath, string $description): void
    {
        $this->photoPath = $photoPath;
        $this->photoDescription = $description;
        $this->showPhotoModal = true;
    }

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
     *
     * @param  int  $equipmentId  The ID of the equipment to commit
     */
    public function openCommitModal(int $equipmentId): void
    {
        $this->commitEquipmentId = $equipmentId;
        $this->commitEventId = null;
        $this->commitExpectedDeliveryAt = null;
        $this->commitDeliveryNotes = null;
        $this->showCommitModal = true;
    }

    /**
     * Create a new EquipmentEvent commitment from the catalog.
     */
    public function commitEquipment(): void
    {
        $event = Event::findOrFail($this->commitEventId);

        $this->validate([
            'commitEquipmentId' => [
                'required',
                'exists:equipment,id',
                function ($attribute, $value, $fail) {
                    $equipment = Equipment::find($value);
                    if (! $equipment || $equipment->owner_user_id !== auth()->id()) {
                        $fail('You do not own this equipment.');
                    }
                },
            ],
            'commitEventId' => [
                'required',
                'exists:events,id',
            ],
            'commitExpectedDeliveryAt' => array_filter([
                'nullable',
                'date',
                $event->setup_allowed_from ? "after_or_equal:{$event->setup_allowed_from}" : null,
                "before_or_equal:{$event->end_time}",
            ]),
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

        if ($hasOverlap) {
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

        if ($commitment->equipment->owner_user_id !== auth()->id()) {
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
        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        if ($commitment->equipment->owner_user_id !== auth()->id()) {
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
        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        if ($commitment->equipment->owner_user_id !== auth()->id()) {
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
        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        if ($commitment->equipment->owner_user_id !== auth()->id()) {
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
     * Delete equipment after authorization check.
     *
     * @param  int  $equipmentId  The ID of the equipment to delete
     */
    public function deleteEquipment(int $equipmentId): void
    {
        $equipment = Equipment::findOrFail($equipmentId);

        $this->authorize('delete', $equipment);

        $equipment->delete();

        $this->dispatch('notify', title: 'Success', description: 'Equipment deleted successfully.', type: 'success');
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
        $event = Event::findOrFail($this->bulkCommitEventId);

        $this->validate([
            'bulkCommitEventId' => ['required', 'exists:events,id'],
            'bulkCommitExpectedDeliveryAt' => array_filter([
                'nullable',
                'date',
                $event->setup_allowed_from ? "after_or_equal:{$event->setup_allowed_from}" : null,
                "before_or_equal:{$event->end_time}",
            ]),
            'bulkCommitDeliveryNotes' => ['nullable', 'string', 'max:500'],
        ]);

        $ownedEquipment = Equipment::query()
            ->whereIn('id', $this->selectedIds)
            ->where('owner_user_id', auth()->id())
            ->get();

        $conflicting = [];

        foreach ($ownedEquipment as $equipment) {
            $hasOverlap = EquipmentEvent::query()
                ->where('equipment_id', $equipment->id)
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

            if ($hasOverlap) {
                $conflicting[] = trim("{$equipment->make} {$equipment->model}");
            }
        }

        if (! empty($conflicting)) {
            $names = implode(', ', $conflicting);
            $this->addError('bulkCommit', "The following items have overlapping commitments: {$names}.");

            return;
        }

        foreach ($ownedEquipment as $equipment) {
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

        $count = $ownedEquipment->count();
        $this->dispatch('notify', title: 'Success', description: "{$count} item(s) committed to event successfully.", type: 'success');

        $this->showBulkCommitModal = false;
        $this->bulkCommitEventId = null;
        $this->bulkCommitExpectedDeliveryAt = null;
        $this->bulkCommitDeliveryNotes = null;
        $this->selectedIds = [];

        unset($this->equipment);
    }

    /**
     * Bulk delete selected equipment items owned by the authenticated user.
     */
    public function bulkDeleteEquipment(): void
    {
        $ownedEquipment = Equipment::query()
            ->whereIn('id', $this->selectedIds)
            ->where('owner_user_id', auth()->id())
            ->get();

        $count = $ownedEquipment->count();

        foreach ($ownedEquipment as $equipment) {
            $equipment->delete();
        }

        $this->dispatch('notify', title: 'Success', description: "{$count} item(s) deleted successfully.", type: 'success');

        $this->selectedIds = [];

        unset($this->equipment);
    }

    /**
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.equipment.equipment-list', [
            'equipment' => $this->equipment,
        ])->layout('layouts.app');
    }
}
