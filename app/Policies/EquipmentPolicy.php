<?php

namespace App\Policies;

use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\User;

class EquipmentPolicy
{
    /**
     * Determine whether the user can view any equipment.
     *
     * All authenticated users can view the equipment list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the equipment.
     *
     * All authenticated users can view individual equipment details.
     */
    public function view(User $user, Equipment $equipment): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create equipment.
     *
     * Users can create equipment if they have permission to manage their own equipment
     * or to edit any equipment.
     */
    public function create(User $user): bool
    {
        return $user->can('manage-own-equipment') || $user->can('edit-any-equipment');
    }

    /**
     * Determine whether the user can update the equipment.
     *
     * Users can update equipment if:
     * - They own the equipment and have 'manage-own-equipment' permission
     * - OR they have 'edit-any-equipment' permission
     */
    public function update(User $user, Equipment $equipment): bool
    {
        // User can edit any equipment
        if ($user->can('edit-any-equipment')) {
            return true;
        }

        // User can edit their own equipment
        if ($equipment->owner_user_id === $user->id && $user->can('manage-own-equipment')) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the equipment.
     *
     * Uses the same logic as update - users can delete equipment if:
     * - They own the equipment and have 'manage-own-equipment' permission
     * - OR they have 'edit-any-equipment' permission
     */
    public function delete(User $user, Equipment $equipment): bool
    {
        return $this->update($user, $equipment);
    }

    /**
     * Determine whether the user can restore the equipment.
     *
     * Uses the same logic as update.
     */
    public function restore(User $user, Equipment $equipment): bool
    {
        return $this->update($user, $equipment);
    }

    /**
     * Determine whether the user can permanently delete the equipment.
     *
     * Only users with 'edit-any-equipment' permission can force delete equipment,
     * and only if the equipment has no active commitments.
     */
    public function forceDelete(User $user, Equipment $equipment): bool
    {
        // Must have edit-any-equipment permission
        if (! $user->can('edit-any-equipment')) {
            return false;
        }

        // Equipment must not have active commitments
        $hasActiveCommitments = $equipment->commitments()
            ->whereIn('status', ['committed', 'delivered'])
            ->exists();

        return ! $hasActiveCommitments;
    }

    /**
     * Determine whether the user can commit equipment to an event.
     *
     * Users can commit equipment if:
     * - They own the equipment
     * - OR they have 'manage-event-equipment' permission
     */
    public function commit(User $user, Equipment $equipment): bool
    {
        return $equipment->owner_user_id === $user->id || $user->can('manage-event-equipment');
    }

    /**
     * Determine whether the user can change the status of an equipment commitment.
     *
     * Users can change equipment status if:
     * - They own the equipment AND status is 'committed' -> 'delivered' (owner delivering their own equipment)
     * - OR they have 'manage-event-equipment' permission (can manage any status change)
     */
    public function changeStatus(User $user, EquipmentEvent $equipmentEvent): bool
    {
        // Load the equipment relationship if not already loaded
        if (! $equipmentEvent->relationLoaded('equipment')) {
            $equipmentEvent->load('equipment');
        }

        // Users with manage-event-equipment can change any status
        if ($user->can('manage-event-equipment')) {
            return true;
        }

        // Equipment owners can mark committed equipment as delivered
        if ($equipmentEvent->equipment->owner_user_id === $user->id && $equipmentEvent->status === 'committed') {
            return true;
        }

        return false;
    }
}
