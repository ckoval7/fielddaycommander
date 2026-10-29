<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Equipment;

use App\Livewire\Equipment\Concerns\BulkDeletesEquipment;
use App\Livewire\Equipment\Concerns\ListsEquipment;
use App\Livewire\Equipment\Concerns\ManagesEquipmentCommitments;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

/**
 * Club Equipment: everything the club owns. Any signed-in user can browse
 * it; selecting, committing and deleting need edit-any-equipment.
 */
class ClubEquipmentList extends Component
{
    use AuthorizesRequests, BulkDeletesEquipment, ListsEquipment, ManagesEquipmentCommitments;

    /**
     * Only club-owned equipment.
     */
    protected function equipmentQuery(): Builder
    {
        return Equipment::query()
            ->with(['manager', 'activeCommitments'])
            ->whereNotNull('owner_organization_id');
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
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.equipment.club-equipment-list', [
            'equipment' => $this->equipment,
        ])->layout('layouts.app', ['title' => 'Club Equipment']);
    }
}
