<?php

namespace App\Livewire\Equipment;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class EquipmentList extends Component
{
    use AuthorizesRequests, ManagesEquipmentCommitments, WithPagination;

    public string $search = '';

    public ?string $typeFilter = null;

    public ?string $statusFilter = null;

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public bool $showPhotoModal = false;

    public ?string $photoPath = null;

    public ?string $photoDescription = null;

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
     * No-op: ownership is checked per-item via canManageCommitment.
     */
    protected function authorizeCommitAction(): void
    {
        // No gate needed for user-owned equipment; ownership is verified per-item.
    }

    /**
     * Check that the commitment belongs to the authenticated user's equipment.
     */
    protected function canManageCommitment(EquipmentEvent $commitment): bool
    {
        return $commitment->equipment->owner_user_id === auth()->id();
    }

    /**
     * Validation rule ensuring the equipment is owned by the authenticated user.
     */
    protected function commitEquipmentValidationRule(): Closure
    {
        return function ($_attribute, $value, $fail) {
            $equipment = Equipment::find($value);
            if (! $equipment || $equipment->owner_user_id !== auth()->id()) {
                $fail('You do not own this equipment.');
            }
        };
    }

    /**
     * Scope selected equipment to items owned by the authenticated user.
     */
    protected function scopeSelectedEquipment(array $ids): EloquentCollection
    {
        return Equipment::query()
            ->whereIn('id', $ids)
            ->where('owner_user_id', auth()->id())
            ->get();
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

        AuditLog::log(
            action: 'equipment.deleted',
            auditable: $equipment,
            oldValues: [
                'make' => $equipment->make,
                'model' => $equipment->model,
                'type' => $equipment->type,
            ]
        );

        $equipment->delete();

        $this->dispatch('notify', title: 'Success', description: 'Equipment deleted successfully.', type: 'success');
    }

    /**
     * Bulk delete selected equipment items owned by the authenticated user.
     */
    public function bulkDeleteEquipment(): void
    {
        $ownedEquipment = $this->scopeSelectedEquipment($this->selectedIds);

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
