<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Schedule\Concerns;

use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * The "Assign User to Shift" modal used by shift managers.
 *
 * Consuming components render the modal partial and may override
 * `shiftAssigned()` to refresh their own computed data.
 */
trait WithShiftAssignment
{
    public bool $showAssignModal = false;

    public ?int $assignShiftId = null;

    public ?int $assignUserId = null;

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    #[Computed]
    public function users(): Collection
    {
        if (! $this->showAssignModal) {
            return collect();
        }

        return User::query()
            ->excludeSystem()
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name)
                    .($user->call_sign ? " ({$user->call_sign})" : ''),
            ]);
    }

    public function openAssignModal(int $shiftId): void
    {
        Gate::authorize('manage-shifts');

        $this->assignShiftId = $shiftId;
        $this->assignUserId = null;
        $this->showAssignModal = true;
    }

    public function assignUser(): void
    {
        Gate::authorize('manage-shifts');

        $this->validate([
            'assignShiftId' => ['required', 'exists:shifts,id'],
            'assignUserId' => ['required', 'exists:users,id'],
        ]);

        $shift = Shift::findOrFail($this->assignShiftId);

        // Check capacity
        if (! $shift->has_capacity) {
            $this->showAssignModal = false;
            $this->dispatch('toast', title: 'Error', description: 'This shift is already at capacity.', icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        // Check for duplicate
        $alreadyAssigned = ShiftAssignment::where('shift_id', $this->assignShiftId)
            ->where('user_id', $this->assignUserId)
            ->exists();

        if ($alreadyAssigned) {
            $this->showAssignModal = false;
            $this->dispatch('toast', title: 'Error', description: 'User is already assigned to this shift.', icon: 'phosphor-x-circle', css: 'alert-error');

            return;
        }

        $assignment = ShiftAssignment::createOrRestore($this->assignShiftId, $this->assignUserId, ShiftAssignment::SIGNUP_TYPE_ASSIGNED);

        $user = User::find($this->assignUserId);
        AuditLog::log(
            action: 'shift.assigned',
            auditable: $assignment,
            newValues: [
                'assigned_user' => $user->call_sign,
                'role' => $shift->shiftRole->name,
                'start_time' => $shift->start_time->toIso8601String(),
                'end_time' => $shift->end_time->toIso8601String(),
            ]
        );

        $this->showAssignModal = false;
        $this->assignShiftId = null;
        $this->assignUserId = null;
        $this->shiftAssigned();

        $this->dispatch('toast', title: 'Success', description: 'User assigned to shift', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Called after a user is assigned so the component can refresh cached data.
     */
    protected function shiftAssigned(): void {}
}
