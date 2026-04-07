<?php

namespace App\Livewire\Equipment;

use App\Models\AuditLog;
use App\Models\Equipment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class ClubEquipmentList extends Component
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
     * Get the filtered and sorted club equipment query.
     */
    #[Computed]
    public function equipment()
    {
        return Equipment::query()
            ->with(['manager', 'activeCommitments'])
            ->whereNotNull('owner_organization_id')
            ->when($this->search, fn (Builder $query) => $query->search($this->search))
            ->when($this->typeFilter, fn (Builder $query) => $query->ofType($this->typeFilter))
            ->when($this->statusFilter, fn (Builder $query) => $query->withCommitmentStatus($this->statusFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(25);
    }

    /**
     * Open modal to view full-size equipment photo.
     */
    public function viewPhoto(string $photoPath, string $description): void
    {
        $this->photoPath = $photoPath;
        $this->photoDescription = $description;
        $this->showPhotoModal = true;
    }

    /**
     * Delete club equipment after authorization check.
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

        unset($this->equipment);
    }

    /**
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.equipment.club-equipment-list', [
            'equipment' => $this->equipment,
        ])->layout('layouts.app');
    }
}
