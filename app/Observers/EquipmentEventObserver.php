<?php

namespace App\Observers;

use App\Models\EquipmentEvent;
use App\Models\User;
use App\Notifications\Equipment\EquipmentCancelled;
use App\Notifications\Equipment\EquipmentCommitted;
use App\Notifications\Equipment\EquipmentDelivered;
use App\Notifications\Equipment\EquipmentIncident;
use App\Notifications\Equipment\EquipmentStatusChanged;
use Illuminate\Support\Facades\Log;

/**
 * Observer for EquipmentEvent model to send notifications on status changes.
 *
 * Handles notifications for equipment commitments, status changes, and incidents.
 */
class EquipmentEventObserver
{
    /**
     * Handle the EquipmentEvent "created" event.
     *
     * Sends confirmation to operator and alert to event managers.
     */
    public function created(EquipmentEvent $equipmentEvent): void
    {
        try {
            // Send confirmation to operator (equipment owner)
            $operator = $equipmentEvent->equipment->owner;
            if ($operator) {
                $operator->notify(new EquipmentCommitted($equipmentEvent, 'operator'));
            }

            // Send alert to Event Managers
            $managers = $this->getEventManagers($equipmentEvent->event_id);
            foreach ($managers as $manager) {
                $manager->notify(new EquipmentCommitted($equipmentEvent, 'manager'));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send EquipmentCommitted notifications', [
                'equipment_event_id' => $equipmentEvent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the EquipmentEvent "updated" event.
     *
     * Detects status changes and sends appropriate notifications.
     */
    public function updated(EquipmentEvent $equipmentEvent): void
    {
        // Check if status changed
        if (! $equipmentEvent->wasChanged('status')) {
            return;
        }

        $oldStatus = $equipmentEvent->getOriginal('status');
        $newStatus = $equipmentEvent->status;

        try {
            // Get operator (equipment owner)
            $operator = $equipmentEvent->equipment->owner;

            // Handle different status transitions
            match ($newStatus) {
                'delivered' => $this->handleDelivered($equipmentEvent, $operator),
                'in_use', 'returned' => $this->handleStatusChange($equipmentEvent, $operator, $oldStatus),
                'lost', 'damaged' => $this->handleIncident($equipmentEvent, $operator, $newStatus),
                'cancelled' => $this->handleCancelled($equipmentEvent),
                default => null,
            };
        } catch (\Exception $e) {
            Log::error('Failed to send equipment status change notifications', [
                'equipment_event_id' => $equipmentEvent->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the EquipmentEvent "deleted" event.
     */
    public function deleted(EquipmentEvent $equipmentEvent): void
    {
        // No notifications needed for deletions
    }

    /**
     * Handle the EquipmentEvent "restored" event.
     */
    public function restored(EquipmentEvent $equipmentEvent): void
    {
        // No notifications needed for restorations
    }

    /**
     * Handle the EquipmentEvent "force deleted" event.
     */
    public function forceDeleted(EquipmentEvent $equipmentEvent): void
    {
        // No notifications needed for force deletions
    }

    /**
     * Handle equipment delivered status.
     *
     * @param  EquipmentEvent  $equipmentEvent  The equipment event
     * @param  User|null  $operator  The equipment owner
     */
    protected function handleDelivered(EquipmentEvent $equipmentEvent, ?User $operator): void
    {
        // Notify operator
        if ($operator) {
            $operator->notify(new EquipmentDelivered($equipmentEvent, 'operator'));
        }

        // Notify managers
        $managers = $this->getEventManagers($equipmentEvent->event_id);
        foreach ($managers as $manager) {
            $manager->notify(new EquipmentDelivered($equipmentEvent, 'manager'));
        }
    }

    /**
     * Handle general status changes (in_use, returned).
     *
     * @param  EquipmentEvent  $equipmentEvent  The equipment event
     * @param  User|null  $operator  The equipment owner
     * @param  string  $oldStatus  The previous status
     */
    protected function handleStatusChange(EquipmentEvent $equipmentEvent, ?User $operator, string $oldStatus): void
    {
        // Notify operator only
        if ($operator) {
            $operator->notify(new EquipmentStatusChanged($equipmentEvent, $oldStatus));
        }
    }

    /**
     * Handle equipment incidents (lost, damaged).
     *
     * Send immediate (non-queued) notifications to operator and admins.
     *
     * @param  EquipmentEvent  $equipmentEvent  The equipment event
     * @param  User|null  $operator  The equipment owner
     * @param  string  $incidentType  The type of incident ('lost' or 'damaged')
     */
    protected function handleIncident(EquipmentEvent $equipmentEvent, ?User $operator, string $incidentType): void
    {
        // Create incident notification (NOT queued for immediate delivery)
        $notification = new EquipmentIncident($equipmentEvent, $incidentType);

        // Notify operator immediately
        if ($operator) {
            $operator->notifyNow($notification);
        }

        // Notify admins immediately
        $admins = User::role('system-admin')->get();
        foreach ($admins as $admin) {
            $admin->notifyNow($notification);
        }

        // Also notify event managers
        $managers = $this->getEventManagers($equipmentEvent->event_id);
        foreach ($managers as $manager) {
            $manager->notifyNow($notification);
        }
    }

    /**
     * Handle equipment cancellation.
     *
     * @param  EquipmentEvent  $equipmentEvent  The equipment event
     */
    protected function handleCancelled(EquipmentEvent $equipmentEvent): void
    {
        // Notify Event Managers only
        $managers = $this->getEventManagers($equipmentEvent->event_id);
        foreach ($managers as $manager) {
            $manager->notify(new EquipmentCancelled($equipmentEvent));
        }
    }

    /**
     * Get all Event Managers for a specific event.
     *
     * Event Managers are users with 'manage-event-equipment' permission.
     *
     * @param  int  $eventId  The event ID
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    protected function getEventManagers(int $eventId): \Illuminate\Database\Eloquent\Collection
    {
        return User::permission('manage-event-equipment')->get();
    }
}
