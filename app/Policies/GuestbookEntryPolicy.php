<?php

namespace App\Policies;

use App\Models\GuestbookEntry;
use App\Models\User;

/**
 * Authorization policy for GuestbookEntry model.
 *
 * Controls access to guestbook operations using Spatie Laravel-Permission.
 * Special handling for guest access via nullable User parameter on create().
 *
 * Permissions:
 * - manage-guestbook: View all guestbook entries
 * - manage-guestbook: Verify, edit, delete, export entries
 */
class GuestbookEntryPolicy
{
    /**
     * Determine whether the user can view any guestbook entries.
     *
     * Requires 'manage-guestbook' permission to view the full list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('manage-guestbook');
    }

    /**
     * Determine whether the user can view a specific guestbook entry.
     *
     * Requires 'manage-guestbook' permission to view individual entries.
     */
    public function view(User $user, GuestbookEntry $guestbookEntry): bool
    {
        return $user->can('manage-guestbook');
    }

    /**
     * Determine whether anyone can create guestbook entries.
     *
     * ALWAYS returns true to allow guests to sign the guestbook.
     * Rate limiting is handled via middleware, not authorization.
     *
     * Note: User parameter is nullable to support guest access.
     */
    public function create(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update a guestbook entry.
     *
     * Requires 'manage-guestbook' permission to edit entries.
     */
    public function update(User $user, GuestbookEntry $guestbookEntry): bool
    {
        return $user->can('manage-guestbook');
    }

    /**
     * Determine whether the user can delete a guestbook entry.
     *
     * Requires 'manage-guestbook' permission to delete entries.
     */
    public function delete(User $user, GuestbookEntry $guestbookEntry): bool
    {
        return $user->can('manage-guestbook');
    }

    /**
     * Determine whether the user can restore a soft-deleted guestbook entry.
     *
     * Requires 'manage-guestbook' permission to restore entries.
     */
    public function restore(User $user, GuestbookEntry $guestbookEntry): bool
    {
        return $user->can('manage-guestbook');
    }

    /**
     * Determine whether the user can permanently delete a guestbook entry.
     *
     * Requires 'manage-guestbook' permission to force delete entries.
     */
    public function forceDelete(User $user, GuestbookEntry $guestbookEntry): bool
    {
        return $user->can('manage-guestbook');
    }

    /**
     * Determine whether the user can verify a guestbook entry.
     *
     * Requires 'manage-guestbook' permission to verify entries.
     * Used to mark entries as verified for bonus point eligibility.
     */
    public function verify(User $user, GuestbookEntry $guestbookEntry): bool
    {
        return $user->can('manage-guestbook');
    }
}
