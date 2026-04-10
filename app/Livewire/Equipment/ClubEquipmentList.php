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

class ClubEquipmentList extends Component
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
            ->with(['manager', 'activeCommitments', 'commitments.event'])
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
     * Gate on edit-any-equipment permission for club equipment actions.
     */
    protected function authorizeCommitAction(): void
    {
        $this->authorize('edit-any-equipment');
    }

    /**
     * Check that the commitment is for club equipment and user has permission.
     */
    protected function canManageCommitment(EquipmentEvent $commitment): bool
    {
        return $commitment->equipment->owner_organization_id !== null
            && auth()->user()->can('edit-any-equipment');
    }

    /**
     * Validation rule ensuring the equipment is club-owned.
     */
    protected function commitEquipmentValidationRule(): Closure
    {
        return function ($attribute, $value, $fail) {
            $equipment = Equipment::find($value);
            if (! $equipment || ! $equipment->owner_organization_id) {
                $fail('This is not club equipment.');
            }
        };
    }

    /**
     * Scope selected equipment to club-owned items.
     */
    protected function scopeSelectedEquipment(array $ids): EloquentCollection
    {
        return Equipment::query()
            ->whereIn('id', $ids)
            ->whereNotNull('owner_organization_id')
            ->get();
    }

    /**
     * Bulk delete selected club equipment items.
     */
    public function bulkDeleteEquipment(): void
    {
        $this->authorizeCommitAction();

        $clubEquipment = $this->scopeSelectedEquipment($this->selectedIds);

        $count = $clubEquipment->count();

        foreach ($clubEquipment as $equipment) {
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
        }

        $this->dispatch('notify', title: 'Success', description: "{$count} item(s) deleted successfully.", type: 'success');

        $this->selectedIds = [];

        unset($this->equipment);
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
