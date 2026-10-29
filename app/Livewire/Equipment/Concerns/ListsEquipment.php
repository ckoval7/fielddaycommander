<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Equipment\Concerns;

use App\Models\AuditLog;
use App\Models\Equipment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;

/**
 * Shared behavior for the equipment list pages (My Equipment, Club Equipment
 * and the Equipment Catalog): search, type and status filters, sorting,
 * pagination, the photo viewer and deleting a single item.
 *
 * Each page decides which equipment it lists (and which relations its view
 * needs) in equipmentQuery(). Who may see the page and who may delete an
 * item stay with the page's mount() and the equipment policy.
 */
trait ListsEquipment
{
    use GuardsPrimaryRadioDeletion, WithPagination;

    public string $search = '';

    public ?string $typeFilter = null;

    public ?string $statusFilter = null;

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public bool $showPhotoModal = false;

    public ?string $photoPath = null;

    public ?string $photoDescription = null;

    /**
     * The equipment this page lists, with the relations its view needs,
     * before the search, filters and sort are applied.
     *
     * @return Builder<Equipment>
     */
    abstract protected function equipmentQuery(): Builder;

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
     * The page's equipment, searched, filtered and sorted, 25 to a page.
     *
     * @return LengthAwarePaginator<int, Equipment>
     */
    #[Computed]
    public function equipment(): LengthAwarePaginator
    {
        $query = $this->equipmentQuery()
            ->when($this->search, fn (Builder $query) => $query->search($this->search))
            ->when($this->typeFilter, fn (Builder $query) => $query->ofType($this->typeFilter))
            ->when($this->statusFilter, fn (Builder $query) => $query->withCommitmentStatus($this->statusFilter));

        return $this->sortEquipment($query)->paginate(25);
    }

    /**
     * Apply the chosen sort. Pages can add a tiebreaker.
     *
     * @param  Builder<Equipment>  $query
     * @return Builder<Equipment>
     */
    protected function sortEquipment(Builder $query): Builder
    {
        return $query->sortedBy($this->sortBy, $this->sortDirection);
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
     * Delete one item after the policy check, unless it is the primary radio
     * of a station in an event that hasn't ended.
     */
    public function deleteEquipment(int $equipmentId): void
    {
        $equipment = Equipment::findOrFail($equipmentId);

        $this->authorize('delete', $equipment);

        $blockedMessage = $this->primaryRadioDeletionBlocker($equipment);

        if ($blockedMessage !== null) {
            $this->dispatch('notify', title: 'Radio still in use', description: $blockedMessage, type: 'error');

            return;
        }

        $this->logEquipmentDeletion($equipment);

        $equipment->delete();

        $this->dispatch('notify', title: 'Success', description: 'Equipment deleted.', type: 'success');

        unset($this->equipment);
    }

    /**
     * Record the deletion in the audit log.
     */
    protected function logEquipmentDeletion(Equipment $equipment): void
    {
        AuditLog::log(
            action: 'equipment.deleted',
            auditable: $equipment,
            oldValues: [
                'make' => $equipment->make,
                'model' => $equipment->model,
                'type' => $equipment->type,
            ]
        );
    }
}
