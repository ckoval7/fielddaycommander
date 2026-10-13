<?php

namespace App\Observers;

use App\Enums\NotificationCategory;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ShiftAssignmentObserver
{
    public function __construct(protected NotificationService $notificationService) {}

    /**
     * Notify shift managers when a volunteer checks in to a role that needs confirmation.
     */
    public function updated(ShiftAssignment $assignment): void
    {
        if (! $this->isNewCheckInNeedingConfirmation($assignment)) {
            return;
        }

        try {
            $assignment->loadMissing(['user', 'shift.shiftRole']);

            $volunteer = $assignment->user;
            $volunteerName = trim("{$volunteer->first_name} {$volunteer->last_name}");
            $callSign = $volunteer->call_sign ? " ({$volunteer->call_sign})" : '';
            $roleName = $assignment->shift->shiftRole->name;

            $managers = User::permission('manage-shifts')
                ->excludeSystem()
                ->when(Auth::id(), fn ($query, int $actorId) => $query->whereKeyNot($actorId))
                ->get();

            foreach ($managers as $manager) {
                $this->notificationService->notify(
                    user: $manager,
                    category: NotificationCategory::ShiftConfirmation,
                    title: 'Shift Check-in to Confirm',
                    message: "{$volunteerName}{$callSign} checked in as {$roleName}",
                    url: route('schedule.manage', ['tab' => 'confirmations']),
                    groupKey: 'shift_confirmations',
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to send shift confirmation notification', [
                'assignment_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether this save moved a scheduled, unconfirmed assignment into checked-in
     * on a role that requires manager confirmation.
     */
    private function isNewCheckInNeedingConfirmation(ShiftAssignment $assignment): bool
    {
        return $assignment->wasChanged('status')
            && $assignment->status === ShiftAssignment::STATUS_CHECKED_IN
            && $assignment->getOriginal('status') === ShiftAssignment::STATUS_SCHEDULED
            && $assignment->confirmed_by_user_id === null
            && (bool) $assignment->shift?->shiftRole?->requires_confirmation;
    }
}
