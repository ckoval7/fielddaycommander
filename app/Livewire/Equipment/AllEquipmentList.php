<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Equipment;

use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class AllEquipmentList extends Component
{
    use AuthorizesRequests, ManagesEquipmentCommitments, WithPagination;

    public string $search = '';

    public ?string $typeFilter = null;

    public ?string $statusFilter = null;

    public int|string|null $userFilter = null;

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
        $this->authorize('view-all-equipment');
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
     * Reset to page 1 when user filter changes.
     */
    public function updatedUserFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Get users who own at least one equipment item, for the filter dropdown.
     *
     * @return array<int, array{id: int|string, name: string}>
     */
    #[Computed]
    public function userOptions(): array
    {
        $options = [
            ['id' => '', 'name' => 'All Users'],
            ['id' => 'club', 'name' => 'Club Equipment'],
        ];

        $users = User::query()
            ->whereHas('equipment')
            ->orderBy('call_sign')
            ->get(['id', 'call_sign', 'first_name', 'last_name']);

        foreach ($users as $user) {
            $label = $user->call_sign;
            if ($user->first_name || $user->last_name) {
                $label .= ' — '.trim($user->first_name.' '.$user->last_name);
            }
            $options[] = ['id' => $user->id, 'name' => $label];
        }

        return $options;
    }

    /**
     * Get the filtered and sorted equipment query for all users.
     */
    #[Computed]
    public function equipment()
    {
        return Equipment::query()
            ->with(['owner', 'manager', 'activeCommitments'])
            ->when($this->userFilter === 'club', function (Builder $query) {
                $query->whereNotNull('owner_organization_id');
            })
            ->when($this->userFilter && $this->userFilter !== 'club', function (Builder $query) {
                $query->where('owner_user_id', (int) $this->userFilter);
            })
            ->when($this->search, fn (Builder $query) => $query->search($this->search))
            ->when($this->typeFilter, fn (Builder $query) => $query->ofType($this->typeFilter))
            ->when($this->statusFilter, fn (Builder $query) => $query->withCommitmentStatus($this->statusFilter))
            ->sortedBy($this->sortBy, $this->sortDirection)
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
     * Gate on edit-any-equipment permission for equipment actions.
     */
    protected function authorizeCommitAction(): void
    {
        $this->authorize('edit-any-equipment');
    }

    /**
     * Any commitment can be managed by users with edit-any-equipment.
     */
    protected function canManageCommitment(EquipmentEvent $commitment): bool
    {
        return auth()->user()->can('edit-any-equipment');
    }

    /**
     * Validation rule ensuring the equipment exists.
     */
    protected function commitEquipmentValidationRule(): Closure
    {
        return function ($attribute, $value, $fail) {
            if (! Equipment::whereKey($value)->exists()) {
                $fail('The selected equipment does not exist.');
            }
        };
    }

    /**
     * Scope selected equipment to any existing equipment.
     */
    protected function scopeSelectedEquipment(array $ids): EloquentCollection
    {
        return Equipment::query()->whereIn('id', $ids)->get();
    }

    /**
     * Delete any user's equipment after authorization check.
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
        return view('livewire.equipment.all-equipment-list', [
            'equipment' => $this->equipment,
        ])->layout('layouts.app');
    }
}
