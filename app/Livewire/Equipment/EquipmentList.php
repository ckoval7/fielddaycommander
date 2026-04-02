<?php

namespace App\Livewire\Equipment;

use App\Models\Equipment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
            ->with('manager')
            ->where('owner_user_id', auth()->id())
            ->when($this->search, function (Builder $query) {
                $query->where(function (Builder $q) {
                    $q->where('make', 'like', "%{$this->search}%")
                        ->orWhere('model', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%")
                        ->orWhere('serial_number', 'like', "%{$this->search}%");
                });
            })
            ->when($this->typeFilter, function (Builder $query) {
                $query->where('type', $this->typeFilter);
            })
            ->when($this->statusFilter, function (Builder $query) {
                match ($this->statusFilter) {
                    'available' => $query->whereDoesntHave('commitments', function (Builder $q) {
                        $q->whereIn('status', ['committed', 'delivered'])
                            ->whereHas('event', function (Builder $eventQuery) {
                                $eventQuery->where('start_time', '<=', now()->addDays(30))
                                    ->where('end_time', '>=', now());
                            });
                    }),
                    'committed' => $query->whereHas('commitments', function (Builder $q) {
                        $q->whereIn('status', ['committed', 'delivered'])
                            ->whereHas('event', function (Builder $eventQuery) {
                                $eventQuery->where('start_time', '<=', now()->addDays(30))
                                    ->where('end_time', '>=', now());
                            });
                    }),
                    default => $query,
                };
            })
            ->orderBy($this->sortBy, $this->sortDirection)
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
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.equipment.equipment-list', [
            'equipment' => $this->equipment,
        ])->layout('layouts.app');
    }
}
