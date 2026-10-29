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
 * My Equipment: the signed-in user's own equipment. Anyone who can manage
 * their own equipment can select, commit and delete what they own.
 */
class EquipmentList extends Component
{
    use AuthorizesRequests, BulkDeletesEquipment, ListsEquipment, ManagesEquipmentCommitments;

    /**
     * Mount the component and authorize the user.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', Equipment::class);
    }

    /**
     * Only the authenticated user's equipment.
     */
    protected function equipmentQuery(): Builder
    {
        return Equipment::query()
            ->with(['manager', 'activeCommitments'])
            ->where('owner_user_id', auth()->id());
    }

    /**
     * Break sort ties by ID so items don't swap places between pages.
     */
    protected function sortEquipment(Builder $query): Builder
    {
        return $query
            ->sortedBy($this->sortBy, $this->sortDirection)
            ->orderBy('id', $this->sortDirection === 'asc' ? 'asc' : 'desc');
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
        return function ($_attribute, $value, $fail) { // NOSONAR - parameter required by Laravel validation closure signature
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
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.equipment.equipment-list', [
            'equipment' => $this->equipment,
        ])->layout('layouts.app', ['title' => 'My Equipment']);
    }
}
