<?php

// SPDX-FileCopyrightText: 2026 Corey Koval <ckoval7@gmail.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace App\Livewire\Schedule\Concerns;

use App\Models\AuditLog;
use App\Models\ShiftAssignment;
use Illuminate\Support\Facades\Auth;

/**
 * Check-in, check-out and re-check-in for the signed-in volunteer's own
 * shift assignments, shared by My Shifts and the schedule timeline.
 */
trait WithShiftCheckIn
{
    /**
     * Clear the component's cached shift lists after an assignment changes.
     */
    abstract protected function forgetShiftLists(): void;

    /**
     * Check in to an assigned shift.
     */
    public function checkIn(int $assignmentId): void
    {
        $assignment = $this->findOwnAssignment($assignmentId, ShiftAssignment::STATUS_SCHEDULED);

        if (! $assignment->shift->can_check_in) {
            if ($assignment->shift->is_past) {
                $this->dispatch('toast', title: 'Too Late', description: 'This shift has already ended.', icon: 'phosphor-clock', css: 'alert-warning');
            } else {
                $this->dispatch('toast', title: 'Too Early', description: 'Check-in opens 15 minutes before the shift starts.', icon: 'phosphor-clock', css: 'alert-warning');
            }

            return;
        }

        $assignment->checkIn();
        $this->logShiftStatus('shift.checkin', $assignment);

        $this->dispatch('toast', title: 'Success', description: 'You have checked in.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Check out of a checked-in shift.
     */
    public function checkOut(int $assignmentId): void
    {
        $assignment = $this->findOwnAssignment($assignmentId, ShiftAssignment::STATUS_CHECKED_IN);

        $assignment->checkOut();
        $this->logShiftStatus('shift.checkout', $assignment);

        $this->dispatch('toast', title: 'Success', description: 'You have checked out.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    /**
     * Re-check-in to a checked-out shift, only while the shift is still active.
     */
    public function reCheckIn(int $assignmentId): void
    {
        $assignment = $this->findOwnAssignment($assignmentId, ShiftAssignment::STATUS_CHECKED_OUT);

        if (! $assignment->shift->is_current) {
            $this->dispatch('toast', title: 'Too Late', description: 'This shift has already ended.', icon: 'phosphor-clock', css: 'alert-warning');

            return;
        }

        $assignment->checkBackIn();
        $this->logShiftStatus('shift.checkin', $assignment);

        $this->dispatch('toast', title: 'Success', description: 'You have checked back in.', icon: 'phosphor-check-circle', css: 'alert-success');
    }

    private function findOwnAssignment(int $assignmentId, string $status): ShiftAssignment
    {
        $this->authorize('sign-up-shifts');

        return ShiftAssignment::where('id', $assignmentId)
            ->where('user_id', Auth::id())
            ->where('status', $status)
            ->firstOrFail();
    }

    private function logShiftStatus(string $action, ShiftAssignment $assignment): void
    {
        AuditLog::log(
            action: $action,
            auditable: $assignment,
            newValues: [
                'status' => $assignment->status,
                'role' => $assignment->shift->shiftRole->name,
            ]
        );

        $this->forgetShiftLists();
    }
}
