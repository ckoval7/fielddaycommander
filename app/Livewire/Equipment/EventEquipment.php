<?php

namespace App\Livewire\Equipment;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\Event;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class EventEquipment extends Component
{
    use AuthorizesRequests;

    private const PERMISSION_ERROR = 'You do not have permission to modify this commitment.';

    public ?string $selectedTab = null;

    public ?int $selectedEventId = null;

    public ?int $equipmentId = null;

    public ?string $expectedDeliveryAt = null;

    public ?string $deliveryNotes = null;

    public bool $showCommitModal = false;

    public bool $showNotesModal = false;

    public ?int $updateNoteId = null;

    public ?string $tempNotes = null;

    public bool $showPhotoModal = false;

    public ?string $photoPath = null;

    public ?string $photoDescription = null;

    public bool $showDetailsModal = false;

    public ?EquipmentEvent $detailCommitment = null;

    /**
     * Mount the component and set default selected event.
     */
    public function mount(): void
    {
        // Set default selected event to first upcoming event
        $firstEvent = $this->upcomingEvents->first();
        if ($firstEvent) {
            $this->selectedTab = "event-{$firstEvent->id}";
            $this->selectedEventId = $firstEvent->id;
        }
    }

    /**
     * When tab changes, extract and set the event ID.
     */
    public function updatedSelectedTab(): void
    {
        // Extract event ID from tab name (format: "event-123")
        if ($this->selectedTab && preg_match('/^event-(\d+)$/', $this->selectedTab, $matches)) {
            $this->selectedEventId = (int) $matches[1];
        }
    }

    /**
     * Get events within next 30 days or currently active.
     */
    #[Computed]
    public function upcomingEvents(): Collection
    {
        return Event::query()
            ->where(function (Builder $query) {
                $query->where('start_time', '<=', appNow()->addDays(30))
                    ->where('end_time', '>=', appNow());
            })
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Get user's available equipment (not deleted).
     */
    #[Computed]
    public function userEquipment(): Collection
    {
        return Equipment::query()
            ->where('owner_user_id', auth()->id())
            ->orderBy('make')
            ->orderBy('model')
            ->get();
    }

    /**
     * Get user's commitments for selected event.
     */
    #[Computed]
    public function commitments(): Collection
    {
        if (! $this->selectedEventId) {
            return collect();
        }

        return EquipmentEvent::query()
            ->byOwner(auth()->id())
            ->forEvent($this->selectedEventId)
            ->with(['equipment', 'event', 'statusChangedBy'])
            ->orderBy('created_at', 'desc')
            ->get();
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
     * Open modal to commit equipment.
     */
    public function openCommitModal(): void
    {
        $this->showCommitModal = true;
        $this->equipmentId = null;
        $this->expectedDeliveryAt = null;
        $this->deliveryNotes = null;
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
     * Open modal to view full commitment details.
     */
    public function openDetailsModal(int $commitmentId): void
    {
        $commitment = EquipmentEvent::with([
            'equipment.bands',
            'equipment.owner',
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
     * Create new EquipmentEvent record.
     */
    public function commitEquipment(): void
    {
        $event = Event::findOrFail($this->selectedEventId);

        // Validate inputs
        $this->validate([
            'equipmentId' => [
                'required',
                'exists:equipment,id',
                function ($attribute, $value, $fail) {
                    $equipment = Equipment::find($value);
                    if (! $equipment || $equipment->owner_user_id !== auth()->id()) {
                        $fail('You do not own this equipment.');
                    }
                },
            ],
            'expectedDeliveryAt' => [
                'nullable',
                'date',
                "after_or_equal:{$event->setup_allowed_from}",
                "before_or_equal:{$event->end_time}",
            ],
            'deliveryNotes' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        // Check for overlapping commitments
        $hasOverlap = EquipmentEvent::query()
            ->where('equipment_id', $this->equipmentId)
            ->whereNotIn('status', ['cancelled', 'returned'])
            ->whereHas('event', function (Builder $query) use ($event) {
                $query->where(function (Builder $q) use ($event) {
                    // Event starts during target event
                    $q->whereBetween('start_time', [$event->start_time, $event->end_time])
                        // Event ends during target event
                        ->orWhereBetween('end_time', [$event->start_time, $event->end_time])
                        // Event completely encompasses target event
                        ->orWhere(function (Builder $q2) use ($event) {
                            $q2->where('start_time', '<=', $event->start_time)
                                ->where('end_time', '>=', $event->end_time);
                        });
                });
            })
            ->exists();

        if ($hasOverlap) {
            $this->addError('equipmentId', 'This equipment is already committed to an overlapping event.');

            return;
        }

        // Create commitment
        EquipmentEvent::create([
            'equipment_id' => $this->equipmentId,
            'event_id' => $this->selectedEventId,
            'status' => 'committed',
            'committed_at' => now(),
            'expected_delivery_at' => $this->expectedDeliveryAt ? \Carbon\Carbon::parse($this->expectedDeliveryAt) : null,
            'delivery_notes' => $this->deliveryNotes,
            'status_changed_at' => now(),
            'status_changed_by_user_id' => auth()->id(),
        ]);

        $this->dispatch('notify', title: 'Success', description: 'Equipment committed to event successfully.', type: 'success');

        // Close modal and refresh
        $this->showCommitModal = false;
        $this->equipmentId = null;
        $this->expectedDeliveryAt = null;
        $this->deliveryNotes = null;

        // Clear computed properties cache
        unset($this->commitments);
    }

    /**
     * Change equipment commitment status to any valid status.
     */
    public function changeStatus(int $commitmentId, string $newStatus): void
    {
        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        // Authorize (user owns equipment)
        if ($commitment->equipment->owner_user_id !== auth()->id()) {
            $this->dispatch('notify', title: 'Error', description: self::PERMISSION_ERROR, type: 'error');

            return;
        }

        if ($commitment->changeStatus($newStatus, auth()->user())) {
            $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));
            $this->dispatch('notify', title: 'Success', description: "Status changed to {$statusLabel}.", type: 'success');
            $this->showDetailsModal = false;
            unset($this->commitments);
        } else {
            $this->dispatch('notify', title: 'Error', description: 'Invalid status.', type: 'error');
        }
    }

    /**
     * Update delivery_notes.
     */
    public function updateNotes(int $commitmentId, ?string $notes): void
    {
        $commitment = EquipmentEvent::with('equipment')->findOrFail($commitmentId);

        // Authorize (user owns equipment)
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
        unset($this->commitments);
    }

    /**
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.equipment.event-equipment', [
            'events' => $this->upcomingEvents,
            'commitments' => $this->commitments,
            'userEquipment' => $this->userEquipment,
        ])->layout('layouts.app');
    }
}
