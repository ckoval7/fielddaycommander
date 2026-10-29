<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Equipment;

use App\Livewire\Equipment\Concerns\ListsEquipment;
use App\Livewire\Equipment\Concerns\ManagesEquipmentCommitments;
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

/**
 * Equipment Catalog: every item, personal and club, with an owner filter.
 * Viewing needs view-all-equipment; actions need edit-any-equipment. It has
 * no selection or bulk actions.
 */
class AllEquipmentList extends Component
{
    use AuthorizesRequests, ListsEquipment, ManagesEquipmentCommitments;

    public int|string|null $userFilter = null;

    /**
     * Mount the component and authorize the user.
     */
    public function mount(): void
    {
        $this->authorize('view-all-equipment');
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
            ['id' => '', 'name' => 'All Owners'],
            ['id' => 'club', 'name' => 'Club Equipment'],
        ];

        $users = User::query()
            ->whereHas('equipment')
            ->orderBy('call_sign')
            ->get(['id', 'call_sign', 'first_name', 'last_name']);

        foreach ($users as $user) {
            $label = $user->call_sign;
            if ($user->first_name || $user->last_name) {
                $label .= ', '.trim($user->first_name.' '.$user->last_name);
            }
            $options[] = ['id' => $user->id, 'name' => $label];
        }

        return $options;
    }

    /**
     * All equipment, narrowed to club equipment or one owner by the owner filter.
     */
    protected function equipmentQuery(): Builder
    {
        return Equipment::query()
            ->with(['owner', 'manager', 'activeCommitments'])
            ->when($this->userFilter === 'club', function (Builder $query) {
                $query->whereNotNull('owner_organization_id');
            })
            ->when($this->userFilter && $this->userFilter !== 'club', function (Builder $query) {
                $query->where('owner_user_id', (int) $this->userFilter);
            });
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
     * Render the component view.
     */
    public function render(): View
    {
        return view('livewire.equipment.all-equipment-list', [
            'equipment' => $this->equipment,
        ])->layout('layouts.app', ['title' => 'Equipment Catalog']);
    }
}
